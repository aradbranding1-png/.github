<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;

/**
 * Resolves audiences (person / group / org unit / role / level / classification term) and
 * powers manual assignments plus the automatic assignment Rule Engine.
 */
final class Targeting
{
    public const FIELDS = [
        'segment' => 'نوع کاربر',
        'group' => 'گروه',
        'org_unit' => 'واحد سازمانی (معاونت/واحد/بخش/سمت)',
        'role' => 'نقش',
        'level' => 'سطح',
        'term' => 'طبقه‌بندی (بازار، کشور، منطقه، ...)',
    ];

    /** descendant ids including self */
    public static function descendants(string $table, int $id): array
    {
        $table = DB::ident($table);
        $ids = [$id];
        $frontier = [$id];
        for ($depth = 0; $frontier && $depth < 12; $depth++) {
            $frontier = array_map('intval', DB::column("SELECT id FROM `$table` WHERE parent_id IN (" . DB::in($frontier) . ')', $frontier));
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = array_merge($ids, $frontier);
        }
        return $ids;
    }

    /** @return int[] active user ids for a target */
    public static function users(string $type, int|string $id): array
    {
        $active = "u.status = 'active' AND u.deleted_at IS NULL";
        $ids = match ($type) {
            'user' => DB::column("SELECT u.id FROM users u WHERE u.id = ? AND $active", [(int)$id]),
            'segment' => DB::column("SELECT u.id FROM users u WHERE u.segment = ? AND $active", [(string)$id]),
            'group' => (function () use ($id, $active) { $g = self::descendants('groups', (int)$id); return DB::column("SELECT DISTINCT u.id FROM users u JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id IN (" . DB::in($g) . ") AND $active", $g); })(),
            'org_unit' => (function () use ($id, $active) { $o = self::descendants('org_units', (int)$id); return DB::column("SELECT DISTINCT u.id FROM users u JOIN user_org_units x ON x.user_id = u.id WHERE x.org_unit_id IN (" . DB::in($o) . ") AND $active", $o); })(),
            'role' => DB::column("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.role_id = ? AND $active", [(int)$id]),
            'level' => DB::column("SELECT u.id FROM users u JOIN user_levels ul ON ul.user_id = u.id WHERE ul.level_id = ? AND $active", [(int)$id]),
            'term' => DB::column("SELECT u.id FROM users u JOIN user_terms ut ON ut.user_id = u.id WHERE ut.term_id = ? AND $active", [(int)$id]),
            default => [],
        };
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function targetLabel(string $type, int $id): string
    {
        return match ($type) {
            'user' => full_name(DB::find('users', $id)),
            'group' => (string)DB::value('SELECT name FROM `groups` WHERE id = ?', [$id]),
            'org_unit' => (string)DB::value('SELECT CONCAT(t.name, \' — \', o.name) FROM org_units o JOIN org_unit_types t ON t.id = o.type_id WHERE o.id = ?', [$id]),
            'role' => (string)DB::value('SELECT name FROM roles WHERE id = ?', [$id]),
            'level' => (string)DB::value('SELECT CONCAT(g.name, \' — \', l.name) FROM levels l JOIN `groups` g ON g.id = l.group_id WHERE l.id = ?', [$id]),
            'term' => (string)DB::value('SELECT CONCAT(t.name, \': \', tt.name) FROM taxonomy_terms tt JOIN taxonomies t ON t.id = tt.taxonomy_id WHERE tt.id = ?', [$id]),
            default => '—',
        } ?: '—';
    }

    /** Apply an assignment row to its audience (idempotent). Returns number of users. */
    public static function applyAssignment(array $a, ?array $onlyUsers = null): int
    {
        $users = $onlyUsers ?? self::users($a['target_type'], (int)$a['target_id']);
        $opts = ['source' => 'assignment', 'assignment_id' => (int)$a['id'], 'training_type' => $a['training_type'], 'due_at' => $a['due_at'], 'assigned_by' => $a['created_by']];
        foreach ($users as $uid) {
            if ($a['path_id']) PathService::enroll($uid, (int)$a['path_id'], $opts + ['source' => 'assignment']);
            elseif ($a['course_id']) Enrollment::enroll($uid, (int)$a['course_id'], $opts);
        }
        DB::update('assignments', ['enrolled_count' => count(self::users($a['target_type'], (int)$a['target_id']))], 'id = ?', [$a['id']]);
        return count($users);
    }

    // ------------------------------------------------------------------ rule engine

    /** @return int[] users matching the rule conditions */
    public static function ruleUsers(array $rule): array
    {
        $conds = json_decode((string)$rule['conditions'], true) ?: [];
        if (!$conds) return [];
        $all = null;
        $sets = [];
        foreach ($conds as $c) {
            $field = (string)($c['field'] ?? '');
            $value = (string)($c['value'] ?? '');
            if (!isset(self::FIELDS[$field]) || $value === '') continue;
            $set = self::users($field, $field === 'segment' ? $value : (int)$value);
            if (($c['op'] ?? 'is') === 'is_not') {
                $all ??= array_map('intval', DB::column("SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL"));
                $set = array_values(array_diff($all, $set));
            }
            $sets[] = $set;
        }
        if (!$sets) return [];
        if (($rule['match_type'] ?? 'all') === 'any') return array_values(array_unique(array_merge(...$sets)));
        $r = array_shift($sets);
        foreach ($sets as $s) $r = array_intersect($r, $s);
        return array_values(array_unique($r));
    }

    public static function ruleMatchesUser(array $rule, int $userId): bool
    {
        return in_array($userId, self::ruleUsers($rule), true);
    }

    public static function runRule(array $rule, ?array $onlyUsers = null): int
    {
        $users = self::ruleUsers($rule);
        if ($onlyUsers !== null) $users = array_values(array_intersect($users, $onlyUsers));
        $due = $rule['due_days'] ? date('Y-m-d 23:59:59', strtotime('+' . (int)$rule['due_days'] . ' days')) : null;
        $n = 0;
        foreach ($users as $uid) {
            $opts = ['source' => 'rule', 'rule_id' => (int)$rule['id'], 'training_type' => $rule['training_type'], 'due_at' => $due];
            if ($rule['path_id']) {
                if (!DB::value('SELECT 1 FROM path_enrollments WHERE path_id = ? AND user_id = ?', [(int)$rule['path_id'], $uid])) { PathService::enroll($uid, (int)$rule['path_id'], $opts); $n++; }
            } elseif ($rule['course_id']) {
                if (!Enrollment::get($uid, (int)$rule['course_id'])) { Enrollment::enroll($uid, (int)$rule['course_id'], $opts); $n++; }
            }
        }
        if ($onlyUsers === null) DB::update('assignment_rules', ['last_run_at' => now(), 'last_run_count' => $n], 'id = ?', [$rule['id']]);
        return $n;
    }

    public static function runAll(): int
    {
        $n = 0;
        foreach (DB::all('SELECT * FROM assignment_rules WHERE is_active = 1') as $r) $n += self::runRule($r);
        return $n;
    }

    /** Called after a user's groups/org/roles/levels change. Applies matching rules and group assignments. */
    public static function syncUser(int $userId): void
    {
        foreach (DB::all('SELECT * FROM assignment_rules WHERE is_active = 1') as $r) self::runRule($r, [$userId]);
        foreach (DB::all('SELECT * FROM assignments WHERE is_active = 1 AND target_type <> \'user\'') as $a) {
            if (in_array($userId, self::users($a['target_type'], (int)$a['target_id']), true)) {
                $has = $a['path_id'] ? DB::value('SELECT 1 FROM path_enrollments WHERE path_id = ? AND user_id = ?', [(int)$a['path_id'], $userId]) : Enrollment::get($userId, (int)$a['course_id']);
                if (!$has) self::applyAssignment($a, [$userId]);
            }
        }
        Growth::evaluate($userId);
    }
}
