<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Thin PDO wrapper. All queries use prepared statements. */
final class DB
{
    private static ?PDO $pdo = null;
    public static int $queryCount = 0;

    public static function connect(?array $cfg = null): PDO
    {
        $cfg ??= [
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '3306'),
            'name' => env('DB_DATABASE', ''),
            'user' => env('DB_USERNAME', ''),
            'pass' => env('DB_PASSWORD', ''),
        ];
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
        $pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Same behaviour on MySQL 8 and MariaDB: report queries group by the main key only
        try { $pdo->exec("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'ONLY_FULL_GROUP_BY', '')"); } catch (\Throwable) {}
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        self::$queryCount++;
        $st = self::pdo()->prepare($sql);
        // positional parameters that lost their 0..n keys (array_filter/array_unique) are re-indexed
        if ($params && !array_is_list($params) && count(array_filter(array_keys($params), 'is_int')) === count($params)) $params = array_values($params);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        if (!array_is_list($params)) {
            foreach ($params as $k => $v) {
                $st->bindValue(':' . ltrim((string)$k, ':'), $v, is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR));
            }
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** key => value pairs from first two columns */
    public static function pairs(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function find(string $table, int $id): ?array
    {
        return self::one('SELECT * FROM `' . self::ident($table) . '` WHERE id = ?', [$id]);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . self::ident($table) . '` (`' . implode('`,`', array_map([self::class, 'ident'], $cols)) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        if (!$data) return 0;
        $set = implode(',', array_map(fn($c) => '`' . self::ident($c) . '` = ?', array_keys($data)));
        return self::run('UPDATE `' . self::ident($table) . '` SET ' . $set . ' WHERE ' . $where, array_merge(array_values($data), $params))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run('DELETE FROM `' . self::ident($table) . '` WHERE ' . $where, $params)->rowCount();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE */
    public static function upsert(string $table, array $data, array $updateCols): void
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . self::ident($table) . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE '
            . implode(',', array_map(fn($c) => str_contains($c, '=') ? $c : "`$c` = VALUES(`$c`)", $updateCols));
        self::run($sql, array_values($data));
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            if ($pdo->inTransaction()) $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Build "IN (?,?,?)" placeholder list */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }

    public static function ident(string $s): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $s)) throw new \InvalidArgumentException('Invalid identifier');
        return $s;
    }

    public static function tableExists(string $table): bool
    {
        return (bool)self::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
    }

    /** Simple pagination helper. Returns ['rows','total','page','pages','per'] */
    public static function paginate(string $sql, array $params = [], int $per = 20, ?int $page = null): array
    {
        $page = max(1, $page ?? (int)($_GET['page'] ?? 1));
        $total = (int)self::value('SELECT COUNT(*) FROM (' . self::countableSql($sql) . ') AS _c', $params);
        $pages = max(1, (int)ceil($total / $per));
        $page = min($page, $pages);
        $rows = self::all($sql . ' LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per), $params);
        return compact('rows', 'total', 'page', 'pages', 'per');
    }

    /**
     * MySQL/MariaDB reject a derived table with duplicate column names
     * (e.g. "c.*, e.status" when both tables have "status"). For counting we
     * only need the rows, so the top-level select list is replaced with "1".
     * Queries whose select list matters for the row count (DISTINCT, HAVING)
     * are left as they are.
     */
    private static function countableSql(string $sql): string
    {
        $s = ltrim($sql);
        if (!preg_match('/^SELECT\s/i', $s) || preg_match('/^SELECT\s+DISTINCT\b/i', $s) || preg_match('/\bHAVING\b/i', $s)) return $sql;
        $depth = 0; $quote = null; $len = strlen($s); $from = null; $order = null;
        for ($i = 6; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote !== null) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $quote) $quote = null;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; continue; }
            if ($ch === '(') { $depth++; continue; }
            if ($ch === ')') { $depth--; continue; }
            if ($depth !== 0 || !ctype_space($s[$i - 1])) continue;
            if ($from === null && preg_match('/\GFROM\b/i', $s, $m, 0, $i)) $from = $i;
            elseif ($from !== null && preg_match('/\GORDER\s+BY\b/i', $s, $m, 0, $i)) $order = $i;
        }
        if ($from === null) return $sql;
        // ORDER BY may reference select-list aliases and is irrelevant for counting
        $tail = $order !== null ? substr($s, $from, $order - $from) : substr($s, $from);
        return 'SELECT 1 ' . $tail;
    }
}
