<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/** Dynamic settings (JSON values) from the `settings` table, cached as one blob. */
final class Settings
{
    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->cache->remember('settings:v' . $this->cache->version('settings'), 3600, function (): array {
            $out = [];
            foreach ($this->db->select('SELECT `key`, value FROM settings') as $row) {
                $out[$row['key']] = json_decode((string) $row['value'], true);
            }
            return $out;
        });
        return $all[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $actorId = null): void
    {
        $this->db->exec(
            'INSERT INTO settings (`key`, value, updated_by, updated_at) VALUES (?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE value = ?, updated_by = ?, updated_at = NOW(3)',
            [$key, $json = json_encode($value, JSON_UNESCAPED_UNICODE), $actorId, $json, $actorId]
        );
        $this->cache->bump('settings');
    }
}
