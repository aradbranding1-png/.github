<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Db\Connection;
use RuntimeException;

/**
 * Forward-only, versioned migrations.
 *  - Each file in database/migrations returns an object with up(Connection): void.
 *  - Migrations must be idempotent (IF NOT EXISTS, column checks) because MySQL
 *    DDL auto-commits and cannot be rolled back; rollback = restore the pre-update backup.
 *  - A MySQL named lock prevents two servers migrating at once.
 *  - The checksum of every applied file is stored; edited applied files are reported.
 */
final class Migrator
{
    public function __construct(private Connection $db, private string $dir)
    {
    }

    /** @return list<string> applied versions */
    public function migrate(?callable $output = null): array
    {
        return $this->db->withLock('sadt:migrate', 30, function () use ($output): array {
            $this->ensureTable();
            $applied = $this->applied();
            $batch = (int) ($this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') ?? 0) + 1;
            $ran = [];

            foreach ($this->files() as $version => $file) {
                if (isset($applied[$version])) {
                    continue;
                }
                $output && $output("Migrating {$version}");
                $migration = require $file;
                if (!is_object($migration) || !method_exists($migration, 'up')) {
                    throw new RuntimeException("Migration {$version} must return an object with up()");
                }
                $start = hrtime(true);
                $migration->up($this->db);
                $this->db->exec(
                    'INSERT INTO migrations (version, checksum, batch, duration_ms, ran_at) VALUES (?, ?, ?, ?, NOW(3))',
                    [$version, hash_file('sha256', $file), $batch, (int) ((hrtime(true) - $start) / 1e6)]
                );
                $ran[] = $version;
            }
            return $ran;
        });
    }

    /** @return list<array{version: string, status: string}> */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $rows = [];
        foreach ($this->files() as $version => $file) {
            $status = 'pending';
            if (isset($applied[$version])) {
                $status = hash_file('sha256', $file) === $applied[$version] ? 'applied' : 'applied (file changed!)';
            }
            $rows[] = ['version' => $version, 'status' => $status];
        }
        return $rows;
    }

    public function pendingCount(): int
    {
        return count(array_filter($this->status(), static fn (array $r): bool => $r['status'] === 'pending'));
    }

    /** @return array<string, string> version => file */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, string> version => checksum */
    private function applied(): array
    {
        return array_column($this->db->select('SELECT version, checksum FROM migrations'), 'checksum', 'version');
    }

    private function ensureTable(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS migrations (
                version     VARCHAR(191) NOT NULL,
                checksum    CHAR(64) NOT NULL,
                batch       INT UNSIGNED NOT NULL,
                duration_ms INT UNSIGNED NOT NULL,
                ran_at      DATETIME(3) NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci'
        );
    }
}
