<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;

/**
 * Credit packages tied to a role (کارمند فراگیر، نماینده).
 *  - Each package points at a role (chosen in «شارژ گروهی نقش‌ها»; default = the learner role of that segment).
 *  - auto = 1 → a user who receives the role is charged once automatically.
 *  - The bulk button charges every member of the role who has not been charged yet (or everyone again, if asked).
 * Table role_grants keeps one row per (user, role): granted = 1 once the package was given.
 */
final class RoleGrants
{
    /** package key => [title, role slug, fallback role names, permission to run the bulk charge] */
    public const PACKAGES = [
        'employee' => ['title' => 'کارمندان فراگیر', 'slug' => 'employee', 'names' => ['کارمند فراگیر', 'کارمند'], 'perm' => 'grant_employee.run', 'icon' => 'briefcase'],
        'agent'    => ['title' => 'نمایندگان',      'slug' => 'agent',    'names' => ['نماینده فراگیر', 'نماینده'], 'perm' => 'grant_agent.run', 'icon' => 'flag'],
    ];

    public const DEFAULTS = [
        'employee' => ['role_id' => 0, 'course_hours' => 10, 'webinar_hours' => 100, 'workshop' => 100, 'meeting_months' => 12, 'auto' => 1],
        'agent'    => ['role_id' => 0, 'course_hours' => 10, 'webinar_hours' => 100, 'workshop' => 100, 'meeting_months' => 12, 'auto' => 0],
    ];

    public static function norm(string $s): string
    {
        return (string)preg_replace('/[\s\x{200C}]+/u', ' ', trim(strtr($s, ['ي' => 'ی', 'ك' => 'ک'])));
    }

    /** Stored configuration merged over the defaults */
    public static function config(): array
    {
        $saved = json_decode((string)setting('role_grant_rules', ''), true);
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $row = is_array($saved[$k] ?? null) ? $saved[$k] : [];
            foreach ($def as $f => $v) $out[$k][$f] = max(0, (int)($row[$f] ?? $v));
            $out[$k]['auto'] = $out[$k]['auto'] ? 1 : 0;
        }
        return $out;
    }

    public static function saveConfig(array $cfg): void
    {
        $clean = [];
        foreach (self::DEFAULTS as $k => $def) foreach ($def as $f => $v) $clean[$k][$f] = max(0, min(100000, (int)($cfg[$k][$f] ?? $v)));
        foreach ($clean as $k => $_) $clean[$k]['auto'] = $clean[$k]['auto'] ? 1 : 0;
        \App\Core\Settings::set(['role_grant_rules' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
    }

    /** The role a package belongs to: configured id → learner role of the segment → role name */
    public static function roleId(string $key, ?array $cfg = null): ?int
    {
        $p = self::PACKAGES[$key] ?? null;
        if (!$p) return null;
        $cfg ??= self::config();
        $id = (int)($cfg[$key]['role_id'] ?? 0);
        if ($id > 0 && DB::value('SELECT 1 FROM roles WHERE id = ? AND is_root = 0', [$id])) return $id;
        $v = DB::value('SELECT id FROM roles WHERE slug = ? AND is_root = 0', [$p['slug']]);
        if ($v) return (int)$v;
        $names = array_map([self::class, 'norm'], $p['names']);
        foreach (DB::all('SELECT id, name FROM roles WHERE is_root = 0 ORDER BY is_learner DESC, id') as $r) {
            if (in_array(self::norm((string)$r['name']), $names, true)) return (int)$r['id'];
        }
        return null;
    }

    /** What a package gives, in storage units (minutes / count / months) */
    public static function amounts(array $c): array
    {
        return ['course' => $c['course_hours'] * 60, 'webinar' => $c['webinar_hours'] * 60, 'workshop' => $c['workshop'], 'meeting' => $c['meeting_months']];
    }

    public static function describe(array $c): string
    {
        $parts = [];
        if ($c['course_hours']) $parts[] = fa($c['course_hours']) . ' ساعت دوره';
        if ($c['meeting_months']) $parts[] = ($c['meeting_months'] % 12 === 0 ? fa(intdiv($c['meeting_months'], 12)) . ' سال' : fa($c['meeting_months']) . ' ماه') . ' میتینگ';
        if ($c['webinar_hours']) $parts[] = fa($c['webinar_hours']) . ' ساعت وبینار';
        if ($c['workshop']) $parts[] = fa($c['workshop']) . ' کارگاه';
        return $parts ? implode('، ', $parts) : 'بدون اعتبار';
    }

    /** Members of the package's role: total / already charged / waiting */
    public static function stats(string $key, ?int $roleId = null): array
    {
        $roleId ??= self::roleId($key);
        if (!$roleId) return ['members' => 0, 'charged' => 0, 'pending' => 0];
        $r = DB::one('SELECT COUNT(*) members, SUM(g.granted = 1) charged FROM user_roles ur JOIN users u ON u.id = ur.user_id AND u.deleted_at IS NULL
                       LEFT JOIN role_grants g ON g.user_id = ur.user_id AND g.role_id = ur.role_id WHERE ur.role_id = ?', [$roleId]);
        $m = (int)($r['members'] ?? 0); $c = (int)($r['charged'] ?? 0);
        return ['members' => $m, 'charged' => $c, 'pending' => max(0, $m - $c)];
    }

    /** Auto packages: called after roles change; charges once per (user, role). Never throws. */
    public static function apply(int $userId): void
    {
        if ($userId <= 0) return;
        try {
            $cfg = self::config();
            $held = array_map('intval', DB::column('SELECT role_id FROM user_roles WHERE user_id = ?', [$userId]));
            foreach (self::PACKAGES as $key => $p) {
                if (!$cfg[$key]['auto']) continue;
                $rid = self::roleId($key, $cfg);
                if (!$rid || !in_array($rid, $held, true)) continue;
                self::grant($userId, $rid, $key, $cfg[$key], false);
            }
        } catch (\Throwable $e) {
            Logger::write('error', 'role grant failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Bulk button: charge members of the package's role.
     * @return array{charged:int, skipped:int, failed:int, role_id:?int}
     */
    public static function runBulk(string $key, bool $again = false): array
    {
        $cfg = self::config();
        $rid = self::roleId($key, $cfg);
        $res = ['charged' => 0, 'skipped' => 0, 'failed' => 0, 'role_id' => $rid];
        if (!$rid) return $res;
        @set_time_limit(600);
        $ids = DB::column('SELECT ur.user_id FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = ? AND u.deleted_at IS NULL ORDER BY ur.user_id', [$rid]);
        foreach ($ids as $uid) {
            try {
                self::grant((int)$uid, $rid, $key, $cfg[$key], $again) ? $res['charged']++ : $res['skipped']++;
            } catch (\Throwable $e) {
                $res['failed']++;
                Logger::write('error', 'role bulk grant failed', ['user' => (int)$uid, 'package' => $key, 'error' => $e->getMessage()]);
            }
        }
        Audit::log('roles.grant_bulk', 'role', $rid, $res['failed'] ? 'partial' : 'success', ['package' => $key, 'again' => $again] + $res + $cfg[$key]);
        return $res;
    }

    /** Give one user the package. false = already charged (and $again is off). */
    private static function grant(int $userId, int $roleId, string $key, array $c, bool $again): bool
    {
        $amounts = self::amounts($c);
        if (!array_filter($amounts)) return false;
        $note = 'شارژ ' . (self::PACKAGES[$key]['title'] ?? 'نقش');
        $by = Auth::id() ?: null;
        $given = DB::transaction(function () use ($userId, $roleId, $amounts, $note, $by, $again): bool {
            $row = DB::one('SELECT granted FROM role_grants WHERE user_id = ? AND role_id = ? FOR UPDATE', [$userId, $roleId]);
            if ($row && (int)$row['granted'] === 1 && !$again) return false;
            if ($row) DB::run('UPDATE role_grants SET granted = 1, created_at = ? WHERE user_id = ? AND role_id = ?', [now(), $userId, $roleId]);
            else DB::run('INSERT INTO role_grants (user_id, role_id, granted, created_at) VALUES (?,?,1,?)', [$userId, $roleId, now()]);
            foreach ($amounts as $type => $amount) {
                if ($amount <= 0) continue;
                [$ok, $msg] = $type === 'meeting'
                    ? Credit::meetingExtend($userId, (int)$amount, 0, null, $note, $by)
                    : Credit::adjustType($userId, $type, (int)$amount, $note, $by);
                if (!$ok) throw new \RuntimeException($type . ': ' . $msg);
            }
            return true;
        });
        if ($given) Audit::log('roles.grant', 'user', $userId, 'success', ['package' => $key, 'role' => $roleId] + $amounts);
        return $given;
    }
}
