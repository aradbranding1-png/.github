<?php

declare(strict_types=1);

namespace App\Core\Db;

use App\Core\Log\Logger;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PDO wrapper: prepared statements only, UTC session, strict SQL mode,
 * query counting for the N+1 budget, slow-query logging, read-replica ready,
 * and transactions that retry only on deadlock / lock-wait timeout.
 */
final class Connection
{
    private ?PDO $write = null;
    private ?PDO $read = null;
    private int $level = 0;
    private int $count = 0;
    private float $ms = 0.0;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private Logger $logger)
    {
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = [], bool $useReplica = false): array
    {
        return $this->run($this->pdoFor($useReplica), $sql, $bindings)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $bindings = [], bool $useReplica = false): ?array
    {
        $row = $this->run($this->pdoFor($useReplica), $sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $bindings */
    public function scalar(string $sql, array $bindings = [], bool $useReplica = false): mixed
    {
        $value = $this->run($this->pdoFor($useReplica), $sql, $bindings)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<int|string, mixed> $bindings Returns affected rows. */
    public function exec(string $sql, array $bindings = []): int
    {
        return $this->run($this->writer(), $sql, $bindings)->rowCount();
    }

    /** @param array<int|string, mixed> $bindings Returns the new AUTO_INCREMENT id. */
    public function insert(string $sql, array $bindings = []): int
    {
        $this->run($this->writer(), $sql, $bindings);
        return (int) $this->writer()->lastInsertId();
    }

    /** Raw DDL / multi-statement for migrations. No bindings. */
    public function statement(string $sql): void
    {
        $start = hrtime(true);
        $this->writer()->exec($sql);
        $this->record($sql, (hrtime(true) - $start) / 1e6);
    }

    /**
     * Runs $fn inside a transaction. Nested calls join the outer transaction.
     * Only the outermost call retries, and only on MySQL 1213 / 1205.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn, int $retries = 3): mixed
    {
        if ($this->level > 0) {
            $this->level++;
            try {
                return $fn($this);
            } finally {
                $this->level--;
            }
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $pdo = $this->writer();
            $pdo->beginTransaction();
            $this->level = 1;
            try {
                $result = $fn($this);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($attempt <= $retries && self::isRetryable($e)) {
                    usleep(random_int(10_000, 50_000) * $attempt);
                    continue;
                }
                throw $e;
            } finally {
                $this->level = 0;
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    /** @return array{count: int, ms: float} */
    public function stats(): array
    {
        return ['count' => $this->count, 'ms' => round($this->ms, 2)];
    }

    public function serverVersion(): string
    {
        return (string) $this->writer()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function isMariaDb(): bool
    {
        return stripos($this->serverVersion(), 'mariadb') !== false;
    }

    /** Named lock (GET_LOCK) for migrations, cron and other single-runner tasks. */
    public function withLock(string $name, int $timeoutSeconds, callable $fn): mixed
    {
        $got = (int) $this->scalar('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]);
        if ($got !== 1) {
            throw new \RuntimeException("Could not acquire lock: {$name}");
        }
        try {
            return $fn($this);
        } finally {
            $this->scalar('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public static function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return in_array($driverCode, [1213, 1205], true) || $e->getCode() === '40001';
    }

    private function pdoFor(bool $useReplica): PDO
    {
        if ($useReplica && $this->level === 0 && !empty($this->config['read']['host'])) {
            return $this->read ??= $this->connect(array_merge($this->config, $this->config['read']));
        }
        return $this->writer();
    }

    private function writer(): PDO
    {
        return $this->write ??= $this->connect($this->config);
    }

    /** @param array<string, mixed> $c */
    private function connect(array $c): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'],
            (int) $c['port'],
            $c['database']
        );
        $pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => (bool) ($c['persistent'] ?? false),
            PDO::ATTR_TIMEOUT => (int) ($c['connect_timeout'] ?? 3),
        ]);
        $pdo->exec("SET time_zone = '+00:00', "
            . "SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', "
            . 'SESSION innodb_lock_wait_timeout = ' . (int) ($c['lock_wait_timeout'] ?? 5));
        $this->applyTimeouts($pdo, $c);
        return $pdo;
    }

    /**
     * Without these, a query waiting for a metadata lock (an ALTER/partition change queued behind a
     * long transaction) or reading from a stalled server waits for hours: every web request piles up
     * behind it and the site just spins with no error. Fail fast instead.
     *
     * @param array<string, mixed> $c
     */
    private function applyTimeouts(PDO $pdo, array $c): void
    {
        $cli = PHP_SAPI === 'cli';
        $meta = (int) ($c[$cli ? 'metadata_lock_timeout_cli' : 'metadata_lock_timeout'] ?? ($cli ? 60 : 8));
        $stmtMs = (int) ($c[$cli ? 'statement_timeout_ms_cli' : 'statement_timeout_ms'] ?? ($cli ? 0 : 20_000));
        $sets = ['SET SESSION lock_wait_timeout = ' . max(1, $meta), 'SET SESSION net_read_timeout = 60', 'SET SESSION net_write_timeout = 60'];
        if ($stmtMs > 0) {
            // MySQL: max_execution_time (ms, SELECT only). MariaDB: max_statement_time (seconds, all statements).
            $sets[] = $this->isMariaDbServer($pdo)
                ? 'SET SESSION max_statement_time = ' . max(1, intdiv($stmtMs, 1000))
                : 'SET SESSION max_execution_time = ' . $stmtMs;
        }
        foreach ($sets as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $this->logger->log('warning', 'DB timeout setting skipped', ['sql' => $sql, 'error' => $e->getMessage()]);
            }
        }
    }

    /** For web requests that legitimately run long (update, backup, migrate): lift the web-request limits. */
    public function allowLongRunning(): void
    {
        $this->applyTimeouts($this->writer(), array_merge($this->config, [
            'metadata_lock_timeout' => 60,
            'statement_timeout_ms' => 0,
        ]));
        $pdo = $this->writer();
        foreach (['SET SESSION max_execution_time = 0', 'SET SESSION max_statement_time = 0'] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException) {
                // variable does not exist on this server flavour
            }
        }
    }

    private function isMariaDbServer(PDO $pdo): bool
    {
        return stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    }

    /** @param array<int|string, mixed> $bindings */
    private function run(PDO $pdo, string $sql, array $bindings): PDOStatement
    {
        $start = hrtime(true);
        $stmt = $pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->execute();
        $this->record($sql, (hrtime(true) - $start) / 1e6);
        return $stmt;
    }

    private function record(string $sql, float $ms): void
    {
        $this->count++;
        $this->ms += $ms;
        $threshold = (float) ($this->config['slow_query_ms'] ?? 300);
        if ($threshold > 0 && $ms >= $threshold) {
            $this->logger->log('warning', 'Slow query', [
                'ms' => round($ms, 1),
                'sql' => substr(preg_replace('/\s+/', ' ', $sql) ?? $sql, 0, 2000),
            ], 'slow-query');
        }
    }
}
