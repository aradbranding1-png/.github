<?php
declare(strict_types=1);

namespace App\Core;

/**
 * RBAC engine.
 *  - مدیر کل (is_root) همه مجوزها را دارد، از جمله مجوزهای root_only.
 *  - سایر کاربران: اجتماع مجوزهای نقش‌ها + مجوزهای «اجازه» فردی − مجوزهای «منع» فردی.
 *  - مجوزهای root_only هرگز به غیر مدیر کل داده نمی‌شوند (حتی اگر در دیتابیس ثبت شوند).
 */
final class Gate
{
    private static array $cache = [];
    private static ?array $registry = null;

    public static function registry(): array
    {
        return self::$registry ??= require APP_PATH . '/Config/permissions.php';
    }

    /** All permission keys => [module, action, label, root_only] */
    public static function allPermissions(): array
    {
        $reg = self::registry();
        $out = [];
        foreach ($reg['modules'] as $m => $def) {
            foreach ($def['actions'] as $a) {
                $k = "$m.$a";
                $out[$k] = ['module' => $m, 'action' => $a, 'label' => $def['label'] . ' — ' . ($reg['actions'][$a] ?? $a), 'root_only' => in_array($k, $reg['root_only'], true)];
            }
        }
        return $out;
    }

    public static function isRootOnly(string $perm): bool
    {
        return in_array($perm, self::registry()['root_only'], true);
    }

    public static function isRoot(?array $user = null): bool
    {
        $user ??= Auth::user();
        return $user !== null && (int)($user['is_root'] ?? 0) === 1;
    }

    public static function allows(string $perm, ?array $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) return false;
        if (self::isRoot($user)) return true;
        if (self::isRootOnly($perm)) return false;
        return isset(self::permissionsFor((int)$user['id'])[$perm]);
    }

    public static function authorize(string $perm): void
    {
        if (!self::allows($perm)) {
            Audit::log('access.denied', 'permission', null, 'denied', ['perm' => $perm, 'path' => Request::path()]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,true> */
    public static function permissionsFor(int $userId): array
    {
        if (isset(self::$cache[$userId])) return self::$cache[$userId];
        $perms = [];
        foreach (DB::column('SELECT DISTINCT rp.permission_key FROM role_permissions rp JOIN user_roles ur ON ur.role_id = rp.role_id JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_active = 1', [$userId]) as $k) {
            $perms[$k] = true;
        }
        foreach (DB::all('SELECT permission_key, effect FROM user_permissions WHERE user_id = ?', [$userId]) as $o) {
            if ($o['effect'] === 'allow') $perms[$o['permission_key']] = true;
        }
        foreach (DB::all("SELECT permission_key FROM user_permissions WHERE user_id = ? AND effect = 'deny'", [$userId]) as $o) {
            unset($perms[$o['permission_key']]);
        }
        foreach (self::registry()['root_only'] as $ro) unset($perms[$ro]);
        return self::$cache[$userId] = $perms;
    }

    public static function flush(?int $userId = null): void
    {
        if ($userId === null) self::$cache = []; else unset(self::$cache[$userId]);
    }

    /** Data scope: all | supervised | own. The widest scope among the user's roles wins. */
    public static function scope(?array $user = null): string
    {
        $user ??= Auth::user();
        if (!$user) return 'own';
        if (self::isRoot($user)) return 'all';
        $scopes = DB::column('SELECT DISTINCT r.data_scope FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? AND r.is_active = 1', [(int)$user['id']]);
        if (in_array('all', $scopes, true)) return 'all';
        if (in_array('supervised', $scopes, true)) return 'supervised';
        return 'own';
    }

    /** Can the current actor grant this permission to someone else? (privilege-escalation guard) */
    public static function canGrant(string $perm): bool
    {
        if (self::isRootOnly($perm)) return false;
        if (self::isRoot()) return true;
        return self::allows($perm);
    }

    /** Can current actor assign a role (all its permissions must be grantable by actor)? */
    public static function canAssignRole(array $role): bool
    {
        if ((int)$role['is_root'] === 1) return false;
        if (self::isRoot()) return true;
        $perms = DB::column('SELECT permission_key FROM role_permissions WHERE role_id = ?', [(int)$role['id']]);
        foreach ($perms as $p) if (!self::canGrant($p)) return false;
        return true;
    }

    /** Can current actor manage (edit/delete/deactivate) the target user? */
    public static function canManageUser(array $target): bool
    {
        $me = Auth::user();
        if (!$me) return false;
        if ((int)$target['is_root'] === 1) return self::isRoot($me) && (int)$target['id'] === (int)$me['id'];
        if (self::isRoot($me)) return true;
        // a non-root manager cannot manage users that hold permissions they do not have
        foreach (array_keys(self::permissionsFor((int)$target['id'])) as $p) {
            if (!self::allows($p, $me)) return false;
        }
        return true;
    }
}
