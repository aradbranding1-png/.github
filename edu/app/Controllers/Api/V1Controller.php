<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\Enrollment;
use App\Services\Targeting;
use App\Services\UserService;

/**
 * REST API v1 for integrations (my.aradbranding.me, CRM, HR, mobile app).
 * Auth: "Authorization: Bearer <token>" — tokens are created in the admin panel, stored hashed, scoped by abilities.
 */
final class V1Controller
{
    public const ABILITIES = [
        'courses.read' => 'خواندن دوره‌ها',
        'users.read' => 'خواندن کاربران',
        'users.write' => 'ایجاد/به‌روزرسانی کاربران',
        'progress.read' => 'خواندن پیشرفت آموزشی',
        'enrollments.write' => 'تخصیص دوره',
        'reports.read' => 'خواندن آمار کلی',
    ];

    private function auth(string $ability): array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(ae_[a-f0-9]{48})$/', trim($h), $m)) throw new HttpException(401, 'توکن API ارسال نشده یا نامعتبر است.');
        if (!RateLimiter::hit('api-ip:' . Request::ip(), 600, 60)) throw new HttpException(429);
        $t = DB::one('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $m[1])]);
        if (!$t || ($t['expires_at'] && $t['expires_at'] < now())) throw new HttpException(401, 'توکن API نامعتبر یا منقضی است.');
        if (!in_array($ability, explode(',', (string)$t['abilities']), true)) throw new HttpException(403, 'این توکن مجوز «' . $ability . '» را ندارد.');
        if (!RateLimiter::hit('api-token:' . $t['id'], 300, 60)) throw new HttpException(429);
        if (!$t['last_used_at'] || strtotime((string)$t['last_used_at']) < time() - 60) DB::update('api_tokens', ['last_used_at' => now()], 'id = ?', [$t['id']]);
        return $t;
    }

    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($j) ? $j : $_POST;
    }

    public function health(): never
    {
        try { DB::value('SELECT 1'); $db = true; } catch (\Throwable) { $db = false; }
        json_out(['ok' => $db, 'status' => $db ? 'ok' : 'degraded', 'time' => date('c')], $db ? 200 : 503);
    }

    public function courses(): never
    {
        $this->auth('courses.read');
        $rows = DB::all("SELECT c.id, c.title, c.summary, c.training_type, c.target_segment, c.status, c.duration_minutes, cat.name AS category FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id WHERE c.deleted_at IS NULL AND c.status = 'published' ORDER BY c.id");
        json_out(['ok' => true, 'data' => $rows]);
    }

    private function publicUser(array $u): array
    {
        return ['id' => (int)$u['id'], 'uuid' => $u['uuid'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'mobile' => $u['mobile'], 'email' => $u['email'],
            'segment' => $u['segment'], 'status' => $u['status'], 'my_user_id' => $u['my_user_id'], 'last_login_at' => $u['last_login_at'], 'created_at' => $u['created_at']];
    }

    public function users(): never
    {
        $this->auth('users.read');
        $w = 'deleted_at IS NULL'; $p = [];
        if ($m = Request::str('mobile')) { $w .= ' AND mobile = ?'; $p[] = $m; }
        if ($my = Request::str('my_user_id')) { $w .= ' AND my_user_id = ?'; $p[] = $my; }
        if ($id = Request::int('id')) { $w .= ' AND id = ?'; $p[] = $id; }
        $limit = max(1, min(200, Request::int('limit', 50)));
        $offset = max(0, Request::int('offset'));
        $rows = DB::all("SELECT * FROM users WHERE $w ORDER BY id LIMIT $limit OFFSET $offset", $p);
        json_out(['ok' => true, 'data' => array_map([$this, 'publicUser'], $rows)]);
    }

    public function upsertUser(): never
    {
        $t = $this->auth('users.write');
        $b = $this->body();
        $mobile = normalize_input((string)($b['mobile'] ?? ''));
        $myId = trim((string)($b['my_user_id'] ?? ''));
        if ($mobile === '' && $myId === '') throw new HttpException(422, 'mobile یا my_user_id الزامی است.');
        if ($mobile !== '' && !preg_match('/^(\+?\d{8,15}|09\d{9})$/', $mobile)) throw new HttpException(422, 'mobile نامعتبر است.');
        $u = $myId !== '' ? DB::one('SELECT * FROM users WHERE my_user_id = ? AND deleted_at IS NULL', [$myId]) : null;
        if ($mobile !== '') $mobile = canonical_mobile($mobile);
        $u ??= $mobile !== '' ? (($t2 = mobile_taken($mobile)) ? DB::find('users', (int)$t2['id']) : null) : null;
        $seg = in_array($b['segment'] ?? '', ['merchant', 'employee', 'agent'], true) ? $b['segment'] : null;
        if ($u) {
            if ((int)$u['is_root'] === 1) throw new HttpException(403);
            if ($u['my_user_id'] && $myId !== '' && $u['my_user_id'] !== $myId) throw new HttpException(409, 'این موبایل به حساب my دیگری متصل است.');
            $upd = array_filter(['first_name' => $b['first_name'] ?? null, 'last_name' => $b['last_name'] ?? null, 'segment' => $seg, 'job_title' => $b['job_title'] ?? null], fn($v) => $v !== null && $v !== '');
            if ($myId !== '' && !$u['my_user_id']) { $upd['my_user_id'] = $myId; $upd['my_linked_at'] = now(); }
            if ($upd) DB::update('users', $upd + ['updated_at' => now()], 'id = ?', [(int)$u['id']]);
            $id = (int)$u['id'];
            $created = false;
        } else {
            if ($mobile === '') throw new HttpException(422, 'برای ایجاد کاربر جدید mobile الزامی است.');
            $id = UserService::create(['first_name' => $b['first_name'] ?? '', 'last_name' => $b['last_name'] ?? '', 'mobile' => $mobile, 'email' => filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null, 'segment' => $seg ?? 'merchant', 'my_user_id' => $myId ?: null, 'status' => 'active']);
            $created = true;
        }
        foreach ((array)($b['group_ids'] ?? []) as $g) DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) SELECT id, ?, NOW() FROM `groups` WHERE id = ?', [$id, (int)$g]);
        foreach ((array)($b['org_unit_ids'] ?? []) as $o) DB::run('INSERT IGNORE INTO user_org_units (user_id, org_unit_id) SELECT ?, id FROM org_units WHERE id = ?', [$id, (int)$o]);
        Targeting::syncUser($id);
        Audit::log('api.users.upsert', 'user', $id, 'success', ['token' => (int)$t['id'], 'created' => $created]);
        json_out(['ok' => true, 'created' => $created, 'data' => $this->publicUser(DB::find('users', $id))], $created ? 201 : 200);
    }

    public function progress(int $id): never
    {
        $this->auth('progress.read');
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        $en = DB::all('SELECT e.course_id, c.title, e.training_type, e.status, e.progress_pct, e.score, e.due_at, e.started_at, e.completed_at FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ?', [$id]);
        $growth = DB::all('SELECT g.name AS `group`, s.name AS stage, ug.achieved_at FROM user_growth ug JOIN growth_stages s ON s.id = ug.stage_id JOIN `groups` g ON g.id = ug.group_id WHERE ug.user_id = ?', [$id]);
        $certs = DB::all('SELECT code, course_title, issued_at, expires_at FROM certificates WHERE user_id = ? AND revoked_at IS NULL', [$id]);
        $tg = null;
        if ((int)($u['tg_stage'] ?? 0) > 0) {
            $st = \App\Services\TraderGrowth::stageByNo((int)$u['tg_stage']);
            $rk = \App\Services\TraderGrowth::rankFor((int)$u['tg_stage']);
            $tg = ['stage' => (int)$u['tg_stage'], 'stage_title' => $st['title'] ?? null, 'stage_progress' => (float)$u['tg_progress'], 'stages_total' => \App\Services\TraderGrowth::count(),
                   'rank' => $rk['title'] ?? null, 'stars' => $rk ? (int)$rk['stars'] : 0, 'stars_filled' => $rk ? (bool)$rk['filled'] : false, 'services_updated_at' => $u['tg_services_at'] ?? null];
        }
        json_out(['ok' => true, 'data' => ['user' => $this->publicUser($u), 'enrollments' => $en, 'growth' => $growth, 'trader_growth' => $tg, 'certificates' => $certs]]);
    }

    public function enroll(): never
    {
        $t = $this->auth('enrollments.write');
        $b = $this->body();
        $uid = (int)($b['user_id'] ?? 0);
        $cid = (int)($b['course_id'] ?? 0);
        if (!DB::value('SELECT 1 FROM users WHERE id = ? AND deleted_at IS NULL', [$uid]) || !DB::value('SELECT 1 FROM courses WHERE id = ? AND deleted_at IS NULL', [$cid])) throw new HttpException(422, 'user_id یا course_id نامعتبر است.');
        $type = in_array($b['training_type'] ?? '', array_keys(\App\Core\Labels::TRAINING_TYPE), true) ? $b['training_type'] : 'mandatory';
        $due = !empty($b['due_at']) && strtotime((string)$b['due_at']) ? date('Y-m-d H:i:s', strtotime((string)$b['due_at'])) : null;
        $eid = Enrollment::enroll($uid, $cid, ['source' => 'api', 'training_type' => $type, 'due_at' => $due]);
        Audit::log('api.enroll', 'enrollment', $eid, 'success', ['token' => (int)$t['id'], 'user' => $uid, 'course' => $cid]);
        json_out(['ok' => true, 'enrollment_id' => $eid], 201);
    }

    public function summary(): never
    {
        $this->auth('reports.read');
        json_out(['ok' => true, 'data' => [
            'users' => (int)DB::value("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'"),
            'active_30d' => (int)DB::value('SELECT COUNT(DISTINCT user_id) FROM user_daily_activity WHERE day >= ?', [date('Y-m-d', strtotime('-30 days'))]),
            'courses' => (int)DB::value("SELECT COUNT(*) FROM courses WHERE deleted_at IS NULL AND status = 'published'"),
            'enrollments' => (int)DB::value('SELECT COUNT(*) FROM enrollments'),
            'completed' => (int)DB::value("SELECT COUNT(*) FROM enrollments WHERE status = 'completed'"),
            'avg_progress' => round((float)DB::value('SELECT AVG(progress_pct) FROM enrollments'), 2),
        ]]);
    }
}
