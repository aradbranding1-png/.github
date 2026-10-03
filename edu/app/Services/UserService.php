<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;

final class UserService
{
    public static function create(array $d, array $roleIds = []): int
    {
        $seg = in_array($d['segment'] ?? '', ['merchant', 'employee', 'agent'], true) ? $d['segment'] : 'merchant';
        // every new account (self sign-up, SSO, API, import, staff) waits for approval,
        // unless it is created by someone who is allowed to approve accounts
        $status = in_array($d['status'] ?? 'active', ['active', 'inactive', 'pending'], true) ? ($d['status'] ?? 'active') : 'active';
        // skip_approval: trusted server-to-server creation (e.g. a paid order from Arad Contact with auto-activation on)
        if ($status === 'active' && empty($d['skip_approval']) && self::approvalRequired() && !(Auth::check() && \App\Core\Gate::allows('users.approve'))) $status = 'pending';
        $d['status'] = $status;
        $id = DB::insert('users', [
            'uuid' => uuid4(),
            'first_name' => mb_substr(trim((string)($d['first_name'] ?? '')), 0, 100),
            'last_name' => mb_substr(trim((string)($d['last_name'] ?? '')), 0, 100),
            'mobile' => !empty($d['mobile']) ? canonical_mobile((string)$d['mobile']) : null,
            'email' => !empty($d['email']) ? strtolower(trim((string)$d['email'])) : null,
            'username' => !empty($d['username']) ? trim((string)$d['username']) : null,
            'password_hash' => !empty($d['password']) ? Auth::hashPassword((string)$d['password']) : null,
            'segment' => $seg,
            'status' => $d['status'] ?? 'active',
            'job_title' => $d['job_title'] ?? null,
            'my_user_id' => $d['my_user_id'] ?? null,
            'my_linked_at' => !empty($d['my_user_id']) ? now() : null,
            'supervisor_id' => !empty($d['supervisor_id']) ? (int)$d['supervisor_id'] : null,
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);
        if (!$roleIds) {
            $rid = self::learnerRoleId($seg);
            if ($rid) $roleIds = [$rid];
        }
        foreach ($roleIds as $rid) DB::run('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?,?,?,?)', [$id, (int)$rid, Auth::id(), now()]);
        self::ensureSegmentGroup($id, $seg);
        RoleGrants::apply($id);
        if ($status === 'pending') self::notifyApprovers($id);
        return $id;
    }

    public static function approvalRequired(): bool
    {
        return setting('registration_requires_approval', '1') === '1';
    }

    /** ids of active users who may approve accounts (root + roles/users holding users.approve, minus explicit denies) */
    public static function approverIds(): array
    {
        return array_map('intval', DB::column("SELECT DISTINCT u.id FROM users u
            WHERE u.deleted_at IS NULL AND u.status = 'active' AND (
                u.is_root = 1
                OR ((EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id WHERE ur.user_id = u.id AND rp.permission_key = 'users.approve')
                     OR EXISTS (SELECT 1 FROM user_permissions up WHERE up.user_id = u.id AND up.permission_key = 'users.approve' AND up.effect = 'allow'))
                    AND NOT EXISTS (SELECT 1 FROM user_permissions up2 WHERE up2.user_id = u.id AND up2.permission_key = 'users.approve' AND up2.effect = 'deny')))"));
    }

    public static function notifyApprovers(int $userId): void
    {
        $u = DB::find('users', $userId);
        if (!$u) return;
        $ids = self::approverIds();
        if ($ids) \App\Core\Notify::send($ids, 'system', 'حساب جدید در انتظار تأیید: ' . trim($u['first_name'] . ' ' . $u['last_name']), 'موبایل: ' . ($u['mobile'] ?? '—'), url('/admin/users/' . $userId), 'approve-user-' . $userId);
    }

    /** Approve a pending account (activate + welcome notification + auto-assignments) */
    public static function approve(int $userId): void
    {
        DB::update('users', ['status' => 'active', 'updated_at' => now()], 'id = ?', [$userId]);
        \App\Core\Notify::send($userId, 'system', 'حساب شما تأیید شد', 'به سامانه آموزش آراد برندینگ خوش آمدید. اکنون می‌توانید از همه امکانات سامانه استفاده کنید.', url('/'));
        Targeting::syncUser($userId);
        Audit::log('users.approve', 'user', $userId);
    }

    public static function learnerRoleId(string $segment): ?int
    {
        $v = DB::value('SELECT id FROM roles WHERE slug = ? AND is_learner = 1', [$segment]);
        return $v ? (int)$v : null;
    }

    public static function segmentGroupId(string $segment): ?int
    {
        $v = DB::value('SELECT id FROM `groups` WHERE segment = ? AND is_system = 1 AND parent_id IS NULL ORDER BY id LIMIT 1', [$segment]);
        return $v ? (int)$v : null;
    }

    public static function ensureSegmentGroup(int $userId, string $segment): void
    {
        $gid = self::segmentGroupId($segment);
        if ($gid) DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?,?,?)', [$gid, $userId, now()]);
    }

    public static function roles(int $userId): array
    {
        return DB::all('SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.sort', [$userId]);
    }

    /** Groups of user (id => name) */
    public static function groups(int $userId): array
    {
        return DB::all('SELECT g.* FROM `groups` g JOIN group_members gm ON gm.group_id = g.id WHERE gm.user_id = ? ORDER BY g.sort, g.name', [$userId]);
    }

    /**
     * Merge $sourceId into $targetId without losing any learning history
     * (enrollments, progress, exams, exercises, certificates, evaluations, activity).
     */
    public static function merge(int $sourceId, int $targetId): array
    {
        if ($sourceId === $targetId) throw new \InvalidArgumentException('same user');
        $src = DB::find('users', $sourceId);
        $dst = DB::find('users', $targetId);
        if (!$src || !$dst || (int)$src['is_root'] === 1) throw new \InvalidArgumentException('invalid users');
        $summary = [];
        DB::transaction(function () use ($sourceId, $targetId, $src, $dst, &$summary) {
            // enrollments: keep the better one on conflict
            foreach (DB::all('SELECT * FROM enrollments WHERE user_id = ?', [$sourceId]) as $en) {
                $ex = DB::one('SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?', [$targetId, $en['course_id']]);
                if (!$ex) { DB::update('enrollments', ['user_id' => $targetId], 'id = ?', [$en['id']]); }
                elseif ((float)$en['progress_pct'] > (float)$ex['progress_pct']) {
                    DB::delete('enrollments', 'id = ?', [$ex['id']]);
                    DB::update('enrollments', ['user_id' => $targetId], 'id = ?', [$en['id']]);
                } else { DB::delete('enrollments', 'id = ?', [$en['id']]); }
            }
            foreach (DB::all('SELECT * FROM lesson_progress WHERE user_id = ?', [$sourceId]) as $lp) {
                $ex = DB::one('SELECT * FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$targetId, $lp['lesson_id']]);
                if (!$ex) DB::run('UPDATE lesson_progress SET user_id = ? WHERE user_id = ? AND lesson_id = ?', [$targetId, $sourceId, $lp['lesson_id']]);
                else {
                    DB::run('UPDATE lesson_progress SET percent = GREATEST(percent, ?), time_spent_sec = time_spent_sec + ?, views = views + ?, status = IF(status = \'completed\' OR ? = \'completed\', \'completed\', status), completed_at = COALESCE(completed_at, ?) WHERE user_id = ? AND lesson_id = ?',
                        [$lp['percent'], $lp['time_spent_sec'], $lp['views'], $lp['status'], $lp['completed_at'], $targetId, $lp['lesson_id']]);
                    DB::run('DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$sourceId, $lp['lesson_id']]);
                }
            }
            foreach (['exam_attempts', 'exercise_submissions', 'certificates', 'practical_evaluations', 'training_needs', 'notifications', 'activity_logs', 'login_history'] as $t) {
                $summary[$t] = DB::run("UPDATE `$t` SET user_id = ? WHERE user_id = ?", [$targetId, $sourceId])->rowCount();
            }
            foreach (DB::all('SELECT * FROM user_daily_activity WHERE user_id = ?', [$sourceId]) as $d) {
                DB::run('INSERT INTO user_daily_activity (user_id, day, logins, lessons_viewed, lessons_completed, courses_completed, exams, exercises, content_views, learning_events, seconds_spent) VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE logins = logins + VALUES(logins), lessons_viewed = lessons_viewed + VALUES(lessons_viewed), lessons_completed = lessons_completed + VALUES(lessons_completed),
                    courses_completed = courses_completed + VALUES(courses_completed), exams = exams + VALUES(exams), exercises = exercises + VALUES(exercises), content_views = content_views + VALUES(content_views),
                    learning_events = learning_events + VALUES(learning_events), seconds_spent = seconds_spent + VALUES(seconds_spent)',
                    [$targetId, $d['day'], $d['logins'], $d['lessons_viewed'], $d['lessons_completed'], $d['courses_completed'], $d['exams'], $d['exercises'], $d['content_views'], $d['learning_events'], $d['seconds_spent']]);
            }
            DB::delete('user_daily_activity', 'user_id = ?', [$sourceId]);
            foreach (DB::all('SELECT * FROM path_enrollments WHERE user_id = ?', [$sourceId]) as $pe) {
                if (!DB::value('SELECT 1 FROM path_enrollments WHERE user_id = ? AND path_id = ?', [$targetId, $pe['path_id']])) DB::update('path_enrollments', ['user_id' => $targetId], 'id = ?', [$pe['id']]);
                else DB::delete('path_enrollments', 'id = ?', [$pe['id']]);
            }
            foreach (['group_members' => 'group_id', 'user_roles' => 'role_id', 'user_org_units' => 'org_unit_id', 'user_terms' => 'term_id'] as $t => $col) {
                DB::run("INSERT IGNORE INTO `$t` (user_id, `$col`) SELECT ?, `$col` FROM `$t` WHERE user_id = ?", [$targetId, $sourceId]);
                DB::delete($t, 'user_id = ?', [$sourceId]);
            }
            DB::run('INSERT IGNORE INTO user_levels (user_id, group_id, level_id, assigned_at) SELECT ?, group_id, level_id, assigned_at FROM user_levels WHERE user_id = ?', [$targetId, $sourceId]);
            DB::delete('user_levels', 'user_id = ?', [$sourceId]);
            DB::run('INSERT IGNORE INTO user_growth (user_id, group_id, stage_id, achieved_at, promoted_by) SELECT ?, group_id, stage_id, achieved_at, promoted_by FROM user_growth WHERE user_id = ?', [$targetId, $sourceId]);
            DB::delete('user_growth', 'user_id = ?', [$sourceId]);
            // trader growth: deals, requests, stage approvals, extra phones (the merged account's mobile becomes an extra phone), services
            DB::run('UPDATE tg_deals SET user_id = ? WHERE user_id = ?', [$targetId, $sourceId]);
            DB::run('UPDATE tg_requests SET user_id = ? WHERE user_id = ?', [$targetId, $sourceId]);
            DB::run('UPDATE tg_history SET user_id = ? WHERE user_id = ?', [$targetId, $sourceId]);
            DB::run('INSERT IGNORE INTO tg_user_stages (user_id, stage_id, status, by_user, note, created_at) SELECT ?, stage_id, status, by_user, note, created_at FROM tg_user_stages WHERE user_id = ?', [$targetId, $sourceId]);
            DB::delete('tg_user_stages', 'user_id = ?', [$sourceId]);
            DB::run('INSERT IGNORE INTO user_phones (user_id, phone, label, created_by, created_at) SELECT ?, phone, label, created_by, created_at FROM user_phones WHERE user_id = ?', [$targetId, $sourceId]);
            DB::delete('user_phones', 'user_id = ?', [$sourceId]);
            if (!empty($src['mobile']) && $src['mobile'] !== $dst['mobile']) DB::run('INSERT IGNORE INTO user_phones (user_id, phone, label, created_at) VALUES (?, ?, ?, ?)', [$targetId, $src['mobile'], 'از حساب ادغام‌شده', now()]);
            DB::run('UPDATE tg_user_services SET user_id = ? WHERE user_id = ?', [$targetId, $sourceId]);
            if (empty($dst['tg_track_id']) && !empty($src['tg_track_id'])) DB::update('users', ['tg_track_id' => $src['tg_track_id']], 'id = ?', [$targetId]);
            if ((int)($src['tg_achieved'] ?? 0) > (int)($dst['tg_achieved'] ?? 0)) DB::update('users', ['tg_achieved' => (int)$src['tg_achieved']], 'id = ?', [$targetId]);
            DB::run('UPDATE users SET supervisor_id = ? WHERE supervisor_id = ?', [$targetId, $sourceId]);

            $upd = [
                'login_count' => (int)$dst['login_count'] + (int)$src['login_count'],
                'first_login_at' => min(array_filter([$dst['first_login_at'], $src['first_login_at']]) ?: [null]),
            ];
            $myId = $src['my_user_id'];
            DB::update('users', ['mobile' => null, 'email' => null, 'username' => null, 'my_user_id' => null, 'status' => 'inactive', 'merged_into_id' => $targetId, 'deleted_at' => now()], 'id = ?', [$sourceId]);
            if (empty($dst['my_user_id']) && $myId) { $upd['my_user_id'] = $myId; $upd['my_linked_at'] = now(); }
            if (empty($dst['mobile']) && $src['mobile']) $upd['mobile'] = $src['mobile'];
            if (empty($dst['email']) && $src['email']) $upd['email'] = $src['email'];
            if (empty($dst['avatar_path']) && $src['avatar_path']) { $upd['avatar_path'] = $src['avatar_path']; $upd['avatar_source'] = $src['avatar_source']; }
            DB::update('users', $upd, 'id = ?', [$targetId]);
            DB::insert('account_merges', ['source_user_id' => $sourceId, 'target_user_id' => $targetId, 'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'merged_by' => Auth::id(), 'created_at' => now()]);
        });
        Audit::log('users.merge', 'user', $targetId, 'success', ['source' => $sourceId] + $summary);
        Enrollment::recalcUser($targetId);
        return $summary;
    }
}
