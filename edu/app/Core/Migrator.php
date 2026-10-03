<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Database migrations. Each file in database/migrations returns:
 *   ['description' => '...', 'up' => function (\PDO $db): void { ... }]
 * Executed migrations are tracked in the `migrations` table (status, batch, duration, error).
 */
final class Migrator
{
    public static function dir(?string $base = null): string
    {
        return ($base ?? BASE_PATH) . '/database/migrations';
    }

    public static function ensureTable(): void
    {
        DB::pdo()->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(191) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            batch INT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            error TEXT NULL,
            duration_ms INT UNSIGNED NULL,
            executed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return string[] migration names (without .php), sorted */
    public static function files(?string $base = null): array
    {
        $files = glob(self::dir($base) . '/*.php') ?: [];
        $names = array_map(fn($f) => basename($f, '.php'), $files);
        sort($names, SORT_STRING);
        return $names;
    }

    public static function status(): array
    {
        self::ensureTable();
        $ran = [];
        foreach (DB::all('SELECT * FROM migrations ORDER BY id') as $r) $ran[$r['migration']] = $r;
        $out = [];
        $order = 0;
        foreach (self::files() as $name) {
            $order++;
            $r = $ran[$name] ?? null;
            $out[] = [
                'order' => $order, 'migration' => $name,
                'status' => $r ? $r['status'] : 'pending',
                'batch' => $r['batch'] ?? null, 'executed_at' => $r['executed_at'] ?? null,
                'duration_ms' => $r['duration_ms'] ?? null, 'error' => $r['error'] ?? null,
                'description' => $r['description'] ?? self::describe($name),
            ];
            unset($ran[$name]);
        }
        foreach ($ran as $name => $r) {
            $out[] = ['order' => ++$order, 'migration' => $name, 'status' => 'orphan', 'batch' => $r['batch'], 'executed_at' => $r['executed_at'], 'duration_ms' => $r['duration_ms'], 'error' => $r['error'], 'description' => $r['description']];
        }
        return $out;
    }

    public static function describe(string $name, ?string $base = null): string
    {
        $file = self::dir($base) . '/' . $name . '.php';
        if (!is_file($file)) return '';
        $src = (string)file_get_contents($file, false, null, 0, 4000);
        return preg_match("/'description'\s*=>\s*'([^']+)'/u", $src, $m) ? $m[1] : '';
    }

    /** @return string[] */
    public static function pending(): array
    {
        self::ensureTable();
        $done = DB::column("SELECT migration FROM migrations WHERE status = 'success'");
        return array_values(array_diff(self::files(), $done));
    }

    /** Pending migrations contained in another base dir (e.g. an update staging directory). */
    public static function pendingIn(string $base): array
    {
        self::ensureTable();
        $done = DB::column("SELECT migration FROM migrations WHERE status = 'success'");
        return array_values(array_diff(self::files($base), $done));
    }

    /**
     * Run pending migrations in order. Stops at the first failure.
     * @return array{ran: string[], failed: ?string, error: ?string}
     */
    public static function migrate(?string $base = null): array
    {
        self::ensureTable();
        $done = DB::column("SELECT migration FROM migrations WHERE status = 'success'");
        $pending = array_values(array_diff(self::files($base), $done));
        $batch = (int)DB::value('SELECT COALESCE(MAX(batch),0) FROM migrations') + 1;
        $ran = [];
        foreach ($pending as $name) {
            $file = self::dir($base) . '/' . $name . '.php';
            $t = microtime(true);
            try {
                $def = require $file;
                if (!is_array($def) || !isset($def['up']) || !is_callable($def['up'])) throw new \RuntimeException('Invalid migration file');
                ($def['up'])(DB::pdo());
                DB::upsert('migrations', [
                    'migration' => $name, 'description' => mb_substr((string)($def['description'] ?? ''), 0, 250), 'batch' => $batch, 'status' => 'success',
                    'error' => null, 'duration_ms' => (int)((microtime(true) - $t) * 1000), 'executed_at' => now(),
                ], ['description', 'batch', 'status', 'error', 'duration_ms', 'executed_at']);
                $ran[] = $name;
            } catch (\Throwable $e) {
                $err = Logger::redact($e->getMessage());
                try {
                    DB::upsert('migrations', [
                        'migration' => $name, 'description' => self::describe($name, $base), 'batch' => $batch, 'status' => 'failed',
                        'error' => mb_substr($err, 0, 2000), 'duration_ms' => (int)((microtime(true) - $t) * 1000), 'executed_at' => now(),
                    ], ['batch', 'status', 'error', 'duration_ms', 'executed_at']);
                } catch (\Throwable) {
                }
                Logger::error('Migration failed: ' . $name . ' — ' . $err);
                return ['ran' => $ran, 'failed' => $name, 'error' => $err];
            }
        }
        if ($ran) PermissionSync::sync();
        return ['ran' => $ran, 'failed' => null, 'error' => null];
    }
}
