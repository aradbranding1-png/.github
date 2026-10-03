<?php
declare(strict_types=1);

namespace App\Core;

/** Keeps the `permissions` table in sync with the code registry (labels in DB are preserved if customized). */
final class PermissionSync
{
    public static function sync(): void
    {
        if (!DB::tableExists('permissions')) return;
        $all = Gate::allPermissions();
        foreach ($all as $key => $p) {
            DB::run(
                'INSERT INTO permissions (`key`, module, action, label, root_only) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), root_only = VALUES(root_only)',
                [$key, $p['module'], $p['action'], $p['label'], $p['root_only'] ? 1 : 0]
            );
        }
        $keys = array_keys($all);
        if ($keys) {
            DB::run('DELETE FROM permissions WHERE `key` NOT IN (' . DB::in($keys) . ')', $keys);
            // root-only permissions can never be attached to roles or users
            $ro = Gate::registry()['root_only'];
            DB::run('DELETE FROM role_permissions WHERE permission_key IN (' . DB::in($ro) . ')', $ro);
            DB::run('DELETE FROM user_permissions WHERE permission_key IN (' . DB::in($ro) . ')', $ro);
        }
    }
}
