<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Notify;
use App\Core\Request;
use App\Core\Settings;
use App\Core\Xlsx;
use App\Services\EventService;
use App\Services\GrowthDocs;
use App\Services\Scope;
use App\Services\ServiceSync;
use App\Services\TraderGrowth;

/** Admin: نظام رشد تاجر — stages, requirements, traders, reviews, purchased services & sync, ranks/tracks/seasons */
final class GrowthController
{
    // ------------------------------------------------------------------ overview
    public function index(): string
    {
        if (TraderGrowth::dirtyCount()) TraderGrowth::processDirty(200, 4.0);
        $stages = TraderGrowth::stages();
        $segs = TraderGrowth::segments() ?: ['merchant'];
        $us = Scope::userSql('u.id');
        $base = "u.deleted_at IS NULL AND u.tg_stage > 0" . $us['sql'];
        $counts = DB::pairs("SELECT u.tg_stage, COUNT(*) FROM users u WHERE $base GROUP BY u.tg_stage", $us['params']);
        $total = array_sum($counts);
        $avg = (float)(DB::value("SELECT AVG(u.tg_progress) FROM users u WHERE $base", $us['params']) ?? 0);
        $items = TraderGrowth::items();
        $pending = ['deals' => (int)DB::value("SELECT COUNT(*) FROM tg_deals WHERE status = 'pending'"), 'requests' => (int)DB::value("SELECT COUNT(*) FROM tg_requests WHERE status = 'pending'")];
        $rankCounts = [];
        foreach (TraderGrowth::ranks() as $r) {
            $rankCounts[] = ['rank' => $r, 'n' => array_sum(array_map(fn($no) => (int)($counts[$no] ?? 0), range((int)$r['from_stage'], max((int)$r['from_stage'], (int)$r['to_stage']))))];
        }
        $chart = ['type' => 'bar', 'labels' => array_map(fn($s) => 'مرحله ' . fa($s['no']), $stages), 'series' => [['name' => 'تعداد تاجران', 'data' => array_map(fn($s) => (int)($counts[$s['no']] ?? 0), $stages), 'color' => '#6366f1']]];
        $recent = DB::all('SELECT h.*, u.first_name, u.last_name, u.avatar_path, u.tg_stage, u.id AS uid FROM tg_history h JOIN users u ON u.id = h.user_id ORDER BY h.id DESC LIMIT 12');
        $legacy = DB::tableExists('growth_stages') ? (int)DB::value('SELECT COUNT(*) FROM growth_stages') : 0;
        return view('admin/growth/index', ['title' => 'نظام رشد تاجر', 'stages' => $stages, 'seasons' => TraderGrowth::seasons(), 'counts' => $counts, 'total' => $total, 'avg' => $avg,
            'items' => $items, 'pending' => $pending, 'rankCounts' => $rankCounts, 'chart' => $chart, 'recent' => $recent, 'legacy' => $legacy,
            'dirty' => TraderGrowth::dirtyCount(), 'lastRun' => ServiceSync::lastRun(), 'configured' => ServiceSync::configured(), 'segs' => $segs, 'inactive' => DB::all('SELECT * FROM tg_stages WHERE active = 0 ORDER BY sort')]);
    }

    // ------------------------------------------------------------------ stages
    public function stage(int $id): string
    {
        $s = DB::find('tg_stages', $id) ?? throw new HttpException(404);
        return $this->stageView($s);
    }

    public function stageNew(): string
    {
        return $this->stageView(null);
    }

    private function stageView(?array $s): string
    {
        $items = $s ? DB::all('SELECT * FROM tg_items WHERE stage_id = ? ORDER BY kind, id', [(int)$s['id']]) : [];
        $ids = fn($k) => array_values(array_unique(array_map(fn($i) => (int)$i['ref_id'], array_filter($items, fn($i) => $i['kind'] === $k && $i['ref_id'] !== null))));
        $courseIds = $ids('course'); $eventIds = $ids('event'); $svcIds = $ids('service');
        $titles = [
            'course' => $courseIds ? DB::pairs('SELECT id, title FROM courses WHERE id IN (' . DB::in($courseIds) . ')', $courseIds) : [],
            'event' => $eventIds ? DB::pairs("SELECT id, CONCAT(title, ' — ', COALESCE(DATE(starts_at), '')) FROM events WHERE id IN (" . DB::in($eventIds) . ')', $eventIds) : [],
            'service' => $svcIds ? DB::pairs('SELECT id, name FROM tg_services WHERE id IN (' . DB::in($svcIds) . ')', $svcIds) : [],
        ];
        $eventCounts = DB::pairs("SELECT type, COUNT(*) FROM events WHERE deleted_at IS NULL AND status = 'active' GROUP BY type");
        $no = 0;
        if ($s) foreach (TraderGrowth::stages() as $st) if ((int)$st['id'] === (int)$s['id']) $no = $st['no'];
        return view('admin/growth/stage', [
            'title' => $s ? 'مرحله ' . fa($no ?: $s['sort']) . ': ' . $s['title'] : 'مرحله جدید', 's' => $s, 'no' => $no, 'items' => $items, 'titles' => $titles, 'eventCounts' => $eventCounts,
            'seasons' => TraderGrowth::seasons(), 'tracks' => TraderGrowth::tracks(false),
            'courses' => DB::all("SELECT c.id, c.title, c.status, cat.name AS cat FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id WHERE c.deleted_at IS NULL ORDER BY c.status = 'published' DESC, c.title"),
            'events' => DB::all("SELECT id, type, title, starts_at FROM events WHERE deleted_at IS NULL ORDER BY starts_at DESC LIMIT 400"),
            'services' => DB::all('SELECT id, name, category FROM tg_services WHERE active = 1 ORDER BY sort, name'),
            'traders' => $s ? (int)DB::value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND tg_stage = ?', [$no]) : 0,
        ]);
    }

    public function saveStage(?int $id = null): never
    {
        $title = trim(Request::str('title'));
        if ($title === '') { flash('danger', 'عنوان مرحله را وارد کنید.'); back(); }
        $approval = array_key_exists(Request::str('approval'), TraderGrowth::APPROVALS) ? Request::str('approval') : 'auto';
        $w = fn($k) => max(0, min(1000, (int)normalize_input(Request::str($k, '0'))));
        $row = [
            'title' => mb_substr($title, 0, 150), 'season_id' => Request::intOrNull('season_id'),
            'goal' => trim(Request::str('goal')) ?: null, 'description' => CourseController::richText($_POST['description'] ?? '') ?: null,
            'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#4f46e5',
            'icon' => in_array(Request::str('icon'), TraderGrowth::ICONS, true) ? Request::str('icon') : 'flag',
            'approval' => $approval, 'pass_percent' => max(1, min(100, (int)normalize_input(Request::str('pass_percent', '100')))),
            'edu_weight' => $w('edu_weight'), 'svc_weight' => $w('svc_weight'), 'deal_weight' => $w('deal_weight'),
            'min_deals' => max(0, (int)normalize_input(Request::str('min_deals', '0'))), 'allow_deals' => Request::bool('allow_deals') || (int)normalize_input(Request::str('min_deals', '0')) > 0 ? 1 : 0,
            'request_docs' => implode("\n", TraderGrowth::docLines(Request::str('request_docs'))) ?: null, 'request_hint' => trim(Request::str('request_hint')) ?: null,
            'active' => Request::str('active', '1') === '0' ? 0 : 1, 'updated_at' => now(),
        ];
        if ($id) {
            DB::find('tg_stages', $id) ?? throw new HttpException(404);
            DB::update('tg_stages', $row, 'id = ?', [$id]);
        } else {
            $row['sort'] = (int)DB::value('SELECT COALESCE(MAX(sort), 0) + 1 FROM tg_stages');
            $row['created_at'] = now();
            $id = DB::insert('tg_stages', $row);
        }
        // weights / tracks / counts of the requirement rows are saved with the stage too
        $n = 0;
        foreach ((array)($_POST['items'] ?? []) as $itemId => $in) {
            $it = DB::one('SELECT * FROM tg_items WHERE id = ? AND stage_id = ?', [(int)$itemId, $id]);
            if ($it && $this->saveItemRow($it, (array)$in)) $n++;
        }
        TraderGrowth::renumber();
        Audit::log('growth.stage_save', 'tg_stage', $id, 'success', ['items' => $n]);
        TraderGrowth::markAllDirty();
        flash('success', 'مرحله' . ($n ? ' و ' . fa($n) . ' ردیف الزامات' : '') . ' ذخیره شد؛ درصد پیشرفت تاجران دوباره محاسبه می‌شود.');
        redirect('/admin/growth/stages/' . $id);
    }

    public function moveStage(int $id): never
    {
        $ids = array_map('intval', DB::column('SELECT id FROM tg_stages ORDER BY active DESC, sort, id'));
        $i = array_search($id, $ids, true);
        if ($i === false) throw new HttpException(404);
        $j = Request::str('dir') === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
        foreach ($ids as $k => $sid) DB::update('tg_stages', ['sort' => $k + 1], 'id = ?', [$sid]);
        TraderGrowth::flush();
        TraderGrowth::markAllDirty();
        flash('success', 'ترتیب مراحل تغییر کرد.');
        redirect('/admin/growth');
    }

    public function deleteStage(int $id): never
    {
        $s = DB::find('tg_stages', $id) ?? throw new HttpException(404);
        if (DB::value('SELECT 1 FROM tg_requests WHERE stage_id = ? LIMIT 1', [$id]) || DB::value('SELECT 1 FROM tg_user_stages WHERE stage_id = ? LIMIT 1', [$id])) {
            DB::update('tg_stages', ['active' => 0, 'updated_at' => now()], 'id = ?', [$id]);
            flash('warning', 'این مرحله سابقه تأیید/درخواست دارد؛ به جای حذف، غیرفعال شد.');
        } else {
            DB::delete('tg_items', 'stage_id = ?', [$id]);
            DB::delete('tg_stages', 'id = ?', [$id]);
            flash('success', 'مرحله «' . $s['title'] . '» حذف شد.');
        }
        TraderGrowth::renumber();
        Audit::log('growth.stage_delete', 'tg_stage', $id);
        TraderGrowth::markAllDirty();
        redirect('/admin/growth');
    }

    // ------------------------------------------------------------------ stage items
    public function addItems(int $stageId): never
    {
        DB::find('tg_stages', $stageId) ?? throw new HttpException(404);
        $kind = Request::str('kind');
        $track = Request::intOrNull('track_id');
        $weight = max(1, min(100, (int)normalize_input(Request::str('weight', '1'))));
        $n = 0;
        $exists = fn(string $k, ?int $ref, ?string $type) => (bool)DB::value('SELECT 1 FROM tg_items WHERE stage_id = ? AND kind = ? AND COALESCE(ref_id, 0) = ? AND COALESCE(ref_type, \'\') = ? AND COALESCE(track_id, 0) = ?', [$stageId, $k, (int)$ref, (string)$type, (int)$track]);
        $add = function (string $k, ?int $ref, ?string $type = null, ?int $min = null) use ($stageId, $track, $weight, &$n, $exists) {
            if ($exists($k, $ref, $type)) return;
            DB::insert('tg_items', ['stage_id' => $stageId, 'kind' => $k, 'ref_id' => $ref, 'ref_type' => $type, 'min_count' => $min, 'track_id' => $track, 'weight' => $weight, 'created_at' => now()]);
            $n++;
        };
        switch ($kind) {
            case 'course': foreach (Request::ints('course_ids') as $cid) if (DB::value('SELECT 1 FROM courses WHERE id = ? AND deleted_at IS NULL', [$cid])) $add('course', $cid); break;
            case 'event': foreach (Request::ints('event_ids') as $eid) if (DB::value('SELECT 1 FROM events WHERE id = ? AND deleted_at IS NULL', [$eid])) $add('event', $eid); break;
            case 'service': foreach (Request::ints('service_ids') as $sid) if (DB::value('SELECT 1 FROM tg_services WHERE id = ?', [$sid])) $add('service', $sid); break;
            case 'event_all':
                $t = Request::str('event_type');
                if (isset(EventService::TYPES[$t])) $add('event_all', null, $t);
                break;
            case 'event_count':
                $t = Request::str('event_type');
                $min = max(1, (int)normalize_input(Request::str('min_count', '1')));
                if (isset(EventService::TYPES[$t])) $add('event_count', null, $t, $min);
                break;
            default: throw new HttpException(422);
        }
        if ($n) { Audit::log('growth.items_add', 'tg_stage', $stageId, 'success', ['kind' => $kind, 'n' => $n]); TraderGrowth::markAllDirty(); }
        flash($n ? 'success' : 'warning', $n ? fa($n) . ' مورد به الزامات مرحله اضافه شد؛ درصد پیشرفت تاجران دوباره محاسبه می‌شود.' : 'موردی انتخاب نشد یا قبلاً اضافه شده بود.');
        redirect('/admin/growth/stages/' . $stageId . '#items');
    }

    public function updateItem(int $id): never
    {
        $it = DB::find('tg_items', $id) ?? throw new HttpException(404);
        $in = $_POST['items'][$id] ?? ['weight' => Request::str('weight', '1'), 'track_id' => Request::str('track_id'), 'min_count' => Request::str('min_count')];
        $this->saveItemRow($it, (array)$in);
        TraderGrowth::markAllDirty();
        flash('success', 'مورد به‌روزرسانی شد.');
        redirect('/admin/growth/stages/' . $it['stage_id'] . '#items');
    }

    private function saveItemRow(array $it, array $in): bool
    {
        $w = max(1, min(100, (int)normalize_input((string)($in['weight'] ?? $it['weight']))));
        $t = (string)($in['track_id'] ?? '');
        $track = $t === '' ? null : (int)$t;
        $upd = ['weight' => $w, 'track_id' => $track];
        if ($it['kind'] === 'event_count' && isset($in['min_count']) && $in['min_count'] !== '') $upd['min_count'] = max(1, (int)normalize_input((string)$in['min_count']));
        $changed = (int)$it['weight'] !== $w || ($it['track_id'] === null ? null : (int)$it['track_id']) !== $track || (isset($upd['min_count']) && (int)$it['min_count'] !== $upd['min_count']);
        if ($changed) DB::update('tg_items', $upd, 'id = ?', [(int)$it['id']]);
        return $changed;
    }

    public function deleteItem(int $id): never
    {
        $it = DB::find('tg_items', $id) ?? throw new HttpException(404);
        DB::delete('tg_items', 'id = ?', [$id]);
        Audit::log('growth.item_delete', 'tg_stage', (int)$it['stage_id']);
        TraderGrowth::markAllDirty();
        flash('success', 'مورد از الزامات مرحله حذف شد.');
        redirect('/admin/growth/stages/' . $it['stage_id'] . '#items');
    }

    // ------------------------------------------------------------------ traders
    public function traders(): string
    {
        if (TraderGrowth::dirtyCount()) TraderGrowth::processDirty(200, 3.0);
        $us = Scope::userSql('u.id');
        $w = 'u.deleted_at IS NULL AND u.tg_stage > 0' . $us['sql'];
        $p = $us['params'];
        $q = trim(Request::str('q'));
        if ($q !== '') {
            $w .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.mobile LIKE ? OR EXISTS (SELECT 1 FROM user_phones ph WHERE ph.user_id = u.id AND ph.phone LIKE ?))";
            $like = '%' . normalize_input($q) . '%';
            array_push($p, '%' . $q . '%', $like, $like);
        }
        if ($st = Request::int('stage')) { $w .= ' AND u.tg_stage = ?'; $p[] = $st; }
        if ($rk = Request::int('rank')) {
            $r = DB::find('tg_ranks', $rk);
            if ($r) { $w .= ' AND u.tg_stage BETWEEN ? AND ?'; array_push($p, (int)$r['from_stage'], (int)$r['to_stage']); }
        }
        if (Request::str('track') !== '') { if (Request::str('track') === '0') $w .= ' AND u.tg_track_id IS NULL'; else { $w .= ' AND u.tg_track_id = ?'; $p[] = Request::int('track'); } }
        if (Request::str('sync') === 'never') $w .= ' AND u.tg_services_at IS NULL';
        if (Request::str('sync') === 'error') $w .= ' AND u.tg_services_error IS NOT NULL';
        $sort = match (Request::str('sort')) { 'progress' => 'u.tg_stage DESC, u.tg_progress DESC', 'name' => 'u.last_name, u.first_name', 'low' => 'u.tg_stage, u.tg_progress', default => 'u.tg_stage DESC, u.tg_progress DESC, u.id DESC' };
        $sql = "SELECT u.*, t.name AS track_name,
                       (SELECT COUNT(DISTINCT s.service_id) FROM tg_user_services s WHERE s.user_id = u.id AND s.is_active = 1 AND s.service_id IS NOT NULL) AS svc_count,
                       (SELECT COUNT(*) FROM tg_deals d WHERE d.user_id = u.id AND d.status = 'approved') AS deals,
                       (SELECT COUNT(*) FROM tg_deals d WHERE d.user_id = u.id AND d.status = 'pending') + (SELECT COUNT(*) FROM tg_requests r WHERE r.user_id = u.id AND r.status = 'pending') AS pending
                  FROM users u LEFT JOIN tg_tracks t ON t.id = u.tg_track_id WHERE $w ORDER BY $sort";
        if (Request::str('export') === '1') {
            if (!can('growth.export')) throw new HttpException(403);
            $rows = DB::all($sql . ' LIMIT 20000', $p);
            Xlsx::download('trader-growth', ['نام', 'نام خانوادگی', 'موبایل', 'مرحله', 'عنوان مرحله', 'پیشرفت مرحله (٪)', 'رتبه', 'مسیر', 'خدمات دریافت‌شده', 'معاملات تأییدشده', 'آخرین بروزرسانی خدمات'],
                array_map(function ($r) { $s = TraderGrowth::stageByNo((int)$r['tg_stage']); $rk = TraderGrowth::rankFor((int)$r['tg_stage']);
                    return [$r['first_name'], $r['last_name'], $r['mobile'], (int)$r['tg_stage'], $s['title'] ?? '', (float)$r['tg_progress'], $rk['title'] ?? '', $r['track_name'] ?? '—', (int)$r['svc_count'], (int)$r['deals'], $r['tg_services_at'] ? jdatetime($r['tg_services_at']) : 'هرگز']; }, $rows), 'نظام رشد تاجر');
        }
        return view('admin/growth/traders', ['title' => 'تاجران در نظام رشد', 'page' => DB::paginate($sql, $p, 25), 'stages' => TraderGrowth::stages(), 'ranks' => TraderGrowth::ranks(), 'tracks' => TraderGrowth::tracks(false)]);
    }

    // ------------------------------------------------------------------ one trader
    private function trader(int $id): array
    {
        Scope::authorizeUser($id);
        return DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
    }

    public function user(int $id): string
    {
        $u = $this->trader($id);
        $ev = TraderGrowth::refresh($id, false) ?? TraderGrowth::evaluate($id);
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        $deals = DB::all('SELECT d.*, r.first_name AS rv_first, r.last_name AS rv_last FROM tg_deals d LEFT JOIN users r ON r.id = d.reviewer_id WHERE d.user_id = ? ORDER BY d.id DESC', [$id]);
        $requests = DB::all('SELECT q.*, s.title AS stage_title FROM tg_requests q JOIN tg_stages s ON s.id = q.stage_id WHERE q.user_id = ? ORDER BY q.id DESC', [$id]);
        return view('admin/growth/user', [
            'title' => 'نظام رشد: ' . full_name($u), 'u' => $u, 'ev' => $ev, 'tracks' => TraderGrowth::tracks(), 'phones' => ServiceSync::phones($id),
            'services' => DB::all('SELECT us.*, s.name AS catalog_name FROM tg_user_services us LEFT JOIN tg_services s ON s.id = us.service_id WHERE us.user_id = ? ORDER BY us.is_active DESC, us.purchased_at DESC, us.id DESC', [$id]),
            'catalog' => DB::pairs('SELECT id, name FROM tg_services WHERE active = 1 ORDER BY sort, name'),
            'deals' => $deals, 'dealDocs' => GrowthDocs::forOwners('deal', array_map(fn($d) => (int)$d['id'], $deals)),
            'requests' => $requests, 'reqDocs' => GrowthDocs::forOwners('request', array_map(fn($r) => (int)$r['id'], $requests)),
            'history' => DB::all('SELECT h.*, b.first_name AS by_first, b.last_name AS by_last FROM tg_history h LEFT JOIN users b ON b.id = h.by_user WHERE h.user_id = ? ORDER BY h.id DESC LIMIT 30', [$id]),
            'legacy' => DB::tableExists('growth_history') ? DB::all('SELECT h.*, s.name AS stage_name, g.name AS group_name FROM growth_history h JOIN growth_stages s ON s.id = h.stage_id JOIN `groups` g ON g.id = h.group_id WHERE h.user_id = ? ORDER BY h.id DESC LIMIT 20', [$id]) : [],
            'stats' => TraderGrowth::dealStats($id), 'configured' => ServiceSync::configured(), 'participant' => TraderGrowth::participates($u),
        ]);
    }

    public function userTrack(int $id): never
    {
        $this->trader($id);
        $t = Request::intOrNull('track_id');
        if ($t !== null && !DB::find('tg_tracks', $t)) throw new HttpException(422);
        DB::update('users', ['tg_track_id' => $t], 'id = ?', [$id]);
        TraderGrowth::refresh($id, false);
        Audit::log('growth.track', 'user', $id, 'success', ['track' => $t]);
        flash('success', 'مسیر تجاری کاربر ثبت شد.');
        redirect('/admin/growth/user/' . $id);
    }

    public function userPlace(int $id): never
    {
        $this->trader($id);
        $no = Request::int('stage_no');
        if ($no < 1 || $no > TraderGrowth::count()) { flash('danger', 'مرحله نامعتبر است.'); back(); }
        TraderGrowth::place($id, $no, (int)Auth::id(), mb_substr(Request::str('note'), 0, 250));
        Audit::log('growth.place', 'user', $id, 'success', ['stage' => $no]);
        flash('success', 'مرحله رشد کاربر روی مرحله ' . fa($no) . ' تنظیم شد (مراحل قبل از آن معاف شدند).');
        redirect('/admin/growth/user/' . $id);
    }

    public function userApprove(int $id, int $stageId): never
    {
        $this->trader($id);
        $s = DB::find('tg_stages', $stageId) ?? throw new HttpException(404);
        if (Request::str('op') === 'revoke') {
            TraderGrowth::revoke($id, $stageId);
            flash('success', 'تأیید/معافیت مرحله «' . $s['title'] . '» برداشته شد.');
        } else {
            TraderGrowth::approve($id, $stageId, (int)Auth::id(), Request::str('note'));
            DB::run("UPDATE tg_requests SET status = 'approved', reviewer_id = ?, reviewed_at = ?, review_note = COALESCE(review_note, ?) WHERE user_id = ? AND stage_id = ? AND status = 'pending'", [(int)Auth::id(), now(), 'تأیید از پروفایل تاجر', $id, $stageId]);
            Notify::send($id, 'growth', 'مرحله «' . $s['title'] . '» تأیید شد', Request::str('note'), url('/learn/growth'));
            flash('success', 'مرحله «' . $s['title'] . '» برای کاربر تأیید شد.');
        }
        Audit::log('growth.stage_approve', 'user', $id, 'success', ['stage' => $stageId, 'op' => Request::str('op') ?: 'approve']);
        redirect('/admin/growth/user/' . $id);
    }

    public function userSync(int $id): never
    {
        $this->trader($id);
        $r = ServiceSync::syncUser($id);
        Audit::log('growth.sync_user', 'user', $id, $r['ok'] || !empty($r['partial']) ? 'success' : 'failure');
        if ($r['ok']) flash('success', 'خدمات کاربر از سامانه فروش بروزرسانی شد: ' . fa($r['count']) . ' خدمت روی ' . fa($r['phones']) . ' شماره.');
        elseif (!empty($r['partial'])) flash('warning', 'بروزرسانی بخشی از شماره‌ها ناموفق بود: ' . $r['error']);
        else flash('danger', 'بروزرسانی خدمات ناموفق بود: ' . $r['error']);
        redirect('/admin/growth/user/' . $id . '#services');
    }

    public function phoneAdd(int $id): never
    {
        $this->trader($id);
        $phone = ServiceSync::canonPhone(Request::str('phone'));
        if (!preg_match('/^0\d{9,11}$/', $phone)) { flash('danger', 'شماره موبایل معتبر نیست (مثال: ۰۹۱۲۱۲۳۴۵۶۷).'); redirect('/admin/growth/user/' . $id . '#phones'); }
        $primary = ServiceSync::canonPhone((string)DB::value('SELECT mobile FROM users WHERE id = ?', [$id]));
        if ($phone === $primary || DB::value('SELECT 1 FROM user_phones WHERE user_id = ? AND phone = ?', [$id, $phone])) { flash('warning', 'این شماره قبلاً برای کاربر ثبت شده است.'); redirect('/admin/growth/user/' . $id . '#phones'); }
        $other = DB::one('SELECT u.id, u.first_name, u.last_name FROM users u WHERE u.deleted_at IS NULL AND u.id <> ? AND (u.mobile = ? OR EXISTS (SELECT 1 FROM user_phones p WHERE p.user_id = u.id AND p.phone = ?)) LIMIT 1', [$id, $phone, $phone]);
        DB::insert('user_phones', ['user_id' => $id, 'phone' => $phone, 'label' => mb_substr(trim(Request::str('label')), 0, 60) ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('growth.phone_add', 'user', $id, 'success', ['phone' => substr($phone, 0, 4) . '***' . substr($phone, -4)]);
        if ($other) flash('warning', 'توجه: این شماره برای «' . full_name($other) . '» هم ثبت است؛ خدمات آن برای هر دو نفر محاسبه می‌شود.');
        if (ServiceSync::configured() && Request::bool('sync')) {
            $r = ServiceSync::syncUser($id);
            flash($r['ok'] ? 'success' : 'warning', 'شماره اضافه شد' . ($r['ok'] ? ' و خدمات بروزرسانی شد (' . fa($r['count']) . ' خدمت).' : '؛ بروزرسانی خدمات: ' . $r['error']));
        } else flash('success', 'شماره اضافه شد. برای دریافت خدمات این شماره «بروزرسانی خدمات» را بزنید.');
        redirect('/admin/growth/user/' . $id . '#phones');
    }

    public function phoneDelete(int $id, int $pid): never
    {
        $this->trader($id);
        $p = DB::one('SELECT * FROM user_phones WHERE id = ? AND user_id = ?', [$pid, $id]) ?? throw new HttpException(404);
        DB::delete('user_phones', 'id = ?', [$pid]);
        DB::delete('tg_user_services', "user_id = ? AND source = 'api' AND phone = ?", [$id, $p['phone']]);
        TraderGrowth::refresh($id, false);
        Audit::log('growth.phone_delete', 'user', $id);
        flash('success', 'شماره و خدمات مربوط به آن حذف شد.');
        redirect('/admin/growth/user/' . $id . '#phones');
    }

    /** Record a service manually (e.g. bought outside the sales system) */
    public function serviceManual(int $id): never
    {
        $this->trader($id);
        $sid = Request::int('service_id');
        $svc = DB::find('tg_services', $sid) ?? throw new HttpException(422);
        DB::insert('tg_user_services', ['user_id' => $id, 'service_id' => $sid, 'service_name' => $svc['name'], 'status' => 'ثبت دستی', 'is_active' => 1, 'source' => 'manual',
            'purchased_at' => now(), 'note' => mb_substr(Request::str('note'), 0, 250) ?: null, 'created_by' => Auth::id(), 'updated_at' => now()]);
        TraderGrowth::refresh($id);
        Audit::log('growth.service_manual', 'user', $id, 'success', ['service' => $sid]);
        flash('success', 'خدمت «' . $svc['name'] . '» به صورت دستی برای کاربر ثبت شد.');
        redirect('/admin/growth/user/' . $id . '#services');
    }

    public function serviceManualDelete(int $id, int $rowId): never
    {
        $this->trader($id);
        DB::delete('tg_user_services', "id = ? AND user_id = ? AND source = 'manual'", [$rowId, $id]);
        TraderGrowth::refresh($id, false);
        flash('success', 'خدمت ثبت دستی حذف شد.');
        redirect('/admin/growth/user/' . $id . '#services');
    }

    // ------------------------------------------------------------------ reviews
    public function reviews(): string
    {
        $status = in_array(Request::str('status'), ['pending', 'approved', 'rejected'], true) ? Request::str('status') : 'pending';
        $us = Scope::userSql('u.id');
        $deals = DB::all("SELECT d.*, u.first_name, u.last_name, u.avatar_path, u.mobile, u.tg_stage, u.id AS uid, (SELECT COUNT(*) FROM tg_docs x WHERE x.owner_type = 'deal' AND x.owner_id = d.id) AS docs
                            FROM tg_deals d JOIN users u ON u.id = d.user_id WHERE d.status = ?" . $us['sql'] . ' ORDER BY d.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 200', array_merge([$status], $us['params']));
        $requests = DB::all("SELECT q.*, s.title AS stage_title, s.sort AS stage_sort, u.first_name, u.last_name, u.avatar_path, u.mobile, u.tg_stage, u.id AS uid, (SELECT COUNT(*) FROM tg_docs x WHERE x.owner_type = 'request' AND x.owner_id = q.id) AS docs
                            FROM tg_requests q JOIN users u ON u.id = q.user_id JOIN tg_stages s ON s.id = q.stage_id WHERE q.status = ?" . $us['sql'] . ' ORDER BY q.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 200', array_merge([$status], $us['params']));
        $counts = ['deals' => (int)DB::value("SELECT COUNT(*) FROM tg_deals WHERE status = 'pending'"), 'requests' => (int)DB::value("SELECT COUNT(*) FROM tg_requests WHERE status = 'pending'")];
        return view('admin/growth/reviews', ['title' => 'بررسی معاملات و درخواست‌های رشد', 'deals' => $deals, 'requests' => $requests, 'status' => $status, 'counts' => $counts, 'tab' => Request::str('tab', 'deals')]);
    }

    public function review(string $type, int $id): string
    {
        if ($type === 'deal') {
            $row = DB::one('SELECT d.* FROM tg_deals d WHERE d.id = ?', [$id]) ?? throw new HttpException(404);
            $stage = null;
        } elseif ($type === 'request') {
            $row = DB::one('SELECT q.* FROM tg_requests q WHERE q.id = ?', [$id]) ?? throw new HttpException(404);
            $stage = DB::find('tg_stages', (int)$row['stage_id']);
        } else throw new HttpException(404);
        $u = $this->trader((int)$row['user_id']);
        $ev = TraderGrowth::evaluate((int)$u['id']);
        $stageEv = null;
        if ($stage) foreach ($ev['stages'] as $o) if ((int)$o['stage']['id'] === (int)$stage['id']) $stageEv = $o;
        return view('admin/growth/review', ['title' => $type === 'deal' ? 'بررسی معامله' : 'بررسی درخواست مرحله', 'type' => $type, 'row' => $row, 'u' => $u, 'stage' => $stage, 'stageEv' => $stageEv, 'ev' => $ev,
            'docs' => GrowthDocs::list($type, $id), 'stats' => TraderGrowth::dealStats((int)$u['id']),
            'reviewer' => $row['reviewer_id'] ? DB::find('users', (int)$row['reviewer_id']) : null,
            'otherDeals' => DB::all('SELECT * FROM tg_deals WHERE user_id = ? AND id <> ? ORDER BY id DESC LIMIT 15', [(int)$u['id'], $type === 'deal' ? $id : 0])]);
    }

    public function decide(string $type, int $id): never
    {
        $table = match ($type) { 'deal' => 'tg_deals', 'request' => 'tg_requests', default => throw new HttpException(404) };
        $row = DB::find($table, $id) ?? throw new HttpException(404);
        $uid = (int)$row['user_id'];
        $this->trader($uid);
        $decision = Request::str('decision');
        if (!in_array($decision, ['approved', 'rejected', 'pending'], true)) throw new HttpException(422);
        $note = mb_substr(trim(Request::str('review_note')), 0, 500);
        if ($decision === 'rejected' && $note === '') { flash('danger', 'برای رد، علت را بنویسید تا تاجر بتواند اصلاح کند.'); back(); }
        DB::update($table, ['status' => $decision, 'reviewer_id' => Auth::id(), 'review_note' => $note ?: null, 'reviewed_at' => now()], 'id = ?', [$id]);
        if ($type === 'request') {
            $st = DB::find('tg_stages', (int)$row['stage_id']);
            if ($decision === 'approved') TraderGrowth::approve($uid, (int)$row['stage_id'], (int)Auth::id(), $note);
            elseif (DB::value("SELECT status FROM tg_user_stages WHERE user_id = ? AND stage_id = ?", [$uid, (int)$row['stage_id']]) === 'approved') TraderGrowth::revoke($uid, (int)$row['stage_id']);
            else TraderGrowth::refresh($uid, false);
            if ($decision !== 'pending') Notify::send($uid, 'growth', ($decision === 'approved' ? 'درخواست تأیید مرحله «' : 'درخواست مرحله «') . ($st['title'] ?? '') . ($decision === 'approved' ? '» تأیید شد' : '» نیاز به اصلاح دارد'), $note, url('/learn/growth'));
        } else {
            TraderGrowth::refresh($uid);
            if ($decision !== 'pending') Notify::send($uid, 'growth', $decision === 'approved' ? 'معامله «' . $row['product'] . '» تأیید شد' : 'معامله «' . $row['product'] . '» نیاز به اصلاح دارد', $note, url('/learn/growth#deals'));
        }
        Audit::log('growth.review_' . $type, $type === 'deal' ? 'tg_deal' : 'tg_request', $id, 'success', ['decision' => $decision]);
        flash('success', $decision === 'approved' ? 'تأیید شد.' : ($decision === 'rejected' ? 'رد شد و به تاجر اطلاع داده شد.' : 'به حالت در انتظار برگشت.'));
        $next = DB::value("SELECT id FROM $table WHERE status = 'pending' ORDER BY id LIMIT 1");
        redirect($next && Request::bool('next') ? '/admin/growth/review/' . $type . '/' . $next : '/admin/growth/reviews?tab=' . ($type === 'deal' ? 'deals' : 'requests'));
    }

    // ------------------------------------------------------------------ services catalog & API
    public function services(?array $test = null): string
    {
        $usage = [];
        foreach (DB::all("SELECT i.ref_id, s.sort, s.title FROM tg_items i JOIN tg_stages s ON s.id = i.stage_id WHERE i.kind = 'service' ORDER BY s.sort") as $r) $usage[(int)$r['ref_id']][] = fa($r['sort']) . '. ' . $r['title'];
        return view('admin/growth/services', [
            'title' => 'خدمات و اتصال به سامانه فروش', 'catalog' => DB::all('SELECT s.*, (SELECT COUNT(DISTINCT us.user_id) FROM tg_user_services us WHERE us.service_id = s.id AND us.is_active = 1) AS users FROM tg_services s ORDER BY s.sort, s.id'),
            'usage' => $usage, 'unmatched' => ServiceSync::unmatched(), 'run' => ServiceSync::running(), 'lastRun' => ServiceSync::lastRun(), 'configured' => ServiceSync::configured(),
            'tokenSet' => (string)env('SERVICES_API_TOKEN', '') !== '', 'test' => $test, 'edit' => Request::int('edit') ? DB::find('tg_services', Request::int('edit')) : null,
            'stats' => DB::one("SELECT COUNT(DISTINCT user_id) AS users, COUNT(*) AS rows_n, MAX(api_at) AS last_api FROM tg_user_services WHERE source = 'api'"),
            'never' => (int)DB::value("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND tg_stage > 0 AND tg_services_at IS NULL"),
        ]);
    }

    public function serviceSave(?int $id = null): never
    {
        $name = trim(Request::str('name'));
        if ($name === '') { flash('danger', 'نام خدمت را وارد کنید.'); back(); }
        $url = trim(Request::str('buy_url'));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) { flash('danger', 'لینک خرید باید با https:// شروع شود.'); back(); }
        $row = ['name' => mb_substr($name, 0, 200), 'category' => mb_substr(trim(Request::str('category')), 0, 100) ?: null, 'match_keys' => trim(Request::str('match_keys')) ?: null,
            'description' => mb_substr(trim(Request::str('description')), 0, 500) ?: null, 'buy_url' => $url ?: null, 'active' => Request::str('active', '1') === '0' ? 0 : 1, 'sort' => Request::int('sort'), 'updated_at' => now()];
        if ($id) { unset($row['sort']); DB::update('tg_services', $row, 'id = ?', [$id]); }
        else $id = DB::insert('tg_services', ['sort' => (int)DB::value('SELECT COALESCE(MAX(sort), 0) + 1 FROM tg_services')] + $row + ['created_at' => now()]);
        $n = ServiceSync::rematch();
        TraderGrowth::markAllDirty();
        Audit::log('growth.service_save', 'tg_service', $id);
        flash('success', 'خدمت ذخیره شد' . ($n ? ' و ' . fa($n) . ' رکورد خرید دوباره نگاشت شد.' : '.'));
        redirect('/admin/growth/services');
    }

    public function serviceDelete(int $id): never
    {
        $svc = DB::find('tg_services', $id) ?? throw new HttpException(404);
        $stages = (int)DB::value("SELECT COUNT(DISTINCT stage_id) FROM tg_items WHERE kind = 'service' AND ref_id = ?", [$id]);
        DB::transaction(function () use ($id) {
            DB::delete('tg_items', "kind = 'service' AND ref_id = ?", [$id]);
            DB::delete('tg_user_services', "service_id = ? AND source = 'manual'", [$id]);
            DB::run('UPDATE tg_user_services SET service_id = NULL WHERE service_id = ?', [$id]);
            DB::delete('tg_services', 'id = ?', [$id]);
        });
        Audit::log('growth.service_delete', 'tg_service', $id, 'success', ['stages' => $stages]);
        TraderGrowth::markAllDirty();
        flash('success', 'خدمت «' . $svc['name'] . '» حذف شد' . ($stages ? ' و از الزامات ' . fa($stages) . ' مرحله هم برداشته شد؛ درصد پیشرفت تاجران دوباره محاسبه می‌شود.' : '.'));
        redirect('/admin/growth/services');
    }

    /** Drag & drop order of the services catalog */
    public function serviceOrder(): never
    {
        $ids = Request::ints('ids');
        $valid = array_map('intval', DB::column('SELECT id FROM tg_services'));
        $n = 0;
        DB::transaction(function () use ($ids, $valid, &$n) {
            foreach ($ids as $id) if (in_array($id, $valid, true)) DB::update('tg_services', ['sort' => ++$n], 'id = ?', [$id]);
        });
        json_out(['ok' => true, 'n' => $n]);
    }

    /** Map an unmatched API service name to a catalog service (as a key) or create a new catalog service */
    public function mapName(): never
    {
        $name = trim(Request::str('service_name'));
        if ($name === '') throw new HttpException(422);
        $target = Request::int('service_id');
        if ($target) {
            $s = DB::find('tg_services', $target) ?? throw new HttpException(404);
            DB::update('tg_services', ['match_keys' => trim(((string)$s['match_keys']) . "\n" . $name), 'updated_at' => now()], 'id = ?', [$target]);
            $msg = '«' . $name . '» به خدمت «' . $s['name'] . '» متصل شد.';
        } else {
            DB::insert('tg_services', ['name' => mb_substr($name, 0, 200), 'match_keys' => $name, 'active' => 1, 'sort' => 100, 'created_at' => now()]);
            $msg = 'خدمت «' . $name . '» به فهرست خدمات اضافه شد؛ حالا می‌توانید آن را به مراحل وصل کنید.';
        }
        $n = ServiceSync::rematch();
        flash('success', $msg . ($n ? ' ' . fa($n) . ' رکورد خرید نگاشت شد.' : ''));
        redirect('/admin/growth/services');
    }

    public function apiSave(): never
    {
        $url = trim(Request::str('growth_api_url'));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) { flash('danger', 'آدرس API باید با https:// شروع شود.'); redirect('/admin/growth/services'); }
        // a sample number pasted into the address (…?phone=0912…) is removed and its name becomes the phone parameter
        $phoneParam = trim(Request::str('growth_api_phone_param'));
        if ($url !== '' && ($qs = parse_url($url, PHP_URL_QUERY))) {
            parse_str($qs, $qa);
            foreach ($qa as $k => $v) {
                if (is_string($v) && preg_match('/^\+?\d{9,13}$/', normalize_input($v))) { unset($qa[$k]); $phoneParam = (string)$k; }
                elseif (is_string($v) && in_array(strtolower((string)$k), ['name', 'customer_name'], true)) unset($qa[$k]);
            }
            $url = strtok($url, '?') . ($qa ? '?' . http_build_query($qa) : '');
            $_POST['growth_api_phone_param'] = $phoneParam;
        }
        $vals = ['growth_api_enabled' => Request::bool('growth_api_enabled') ? '1' : '0', 'growth_api_url' => $url,
            'growth_api_method' => Request::str('growth_api_method') === 'POST' ? 'POST' : 'GET',
            'growth_api_phone_format' => in_array(Request::str('growth_api_phone_format'), ['09', '98', '+98', '9'], true) ? Request::str('growth_api_phone_format') : '09',
            'growth_api_timeout' => (string)max(3, min(60, Request::int('growth_api_timeout', 15)))];
        foreach (['growth_api_phone_param', 'growth_api_name_param', 'growth_api_list_path', 'growth_api_field_name', 'growth_api_field_code', 'growth_api_field_status', 'growth_api_field_date', 'growth_api_ok_statuses'] as $k) {
            $vals[$k] = mb_substr(preg_replace('/[^\p{L}\p{N}_.,\-\s]/u', '', Request::str($k)) ?? '', 0, 300);
        }
        Settings::set($vals);
        Audit::log('growth.api_settings', 'settings', null);
        flash('success', 'تنظیمات اتصال ذخیره شد.');
        redirect('/admin/growth/services');
    }

    public function apiTest(): string
    {
        $phone = ServiceSync::canonPhone(Request::str('phone'));
        $test = ['phone' => $phone, 'name' => trim(Request::str('name'))];
        if (!preg_match('/^0\d{9,11}$/', $phone)) $test['result'] = ['ok' => false, 'error' => 'شماره معتبر نیست.', 'items' => [], 'body' => '', 'http' => 0, 'url' => ''];
        elseif (trim((string)setting('growth_api_url', '')) === '') $test['result'] = ['ok' => false, 'error' => 'ابتدا آدرس API را ذخیره کنید.', 'items' => [], 'body' => '', 'http' => 0, 'url' => ''];
        else $test['result'] = ServiceSync::request($phone, $test['name']);
        if (!empty($test['result']['items'])) {
            $m = ServiceSync::matcher();
            $cat = DB::pairs('SELECT id, name FROM tg_services');
            foreach ($test['result']['items'] as &$it) { $mid = ServiceSync::matchId($it['name'], $it['code'], $m); $it['match'] = $mid ? ($cat[$mid] ?? null) : null; }
            unset($it);
        }
        return $this->services($test);
    }

    public function syncStart(): never
    {
        if (!ServiceSync::configured()) { flash('danger', 'ابتدا اتصال به سامانه خدمات را تنظیم و فعال کنید.'); redirect('/admin/growth/services'); }
        $id = ServiceSync::startRun((int)Auth::id());
        Audit::log('growth.sync_all', 'tg_sync_run', $id);
        redirect('/admin/growth/services?run=' . $id . '#sync');
    }

    public function syncStep(int $id): never
    {
        $r = ServiceSync::step($id, 6.0);
        json_out(['ok' => true, 'status' => $r['status'] ?? 'missing', 'total' => (int)($r['total'] ?? 0), 'done' => (int)($r['done'] ?? 0), 'okn' => (int)($r['ok'] ?? 0), 'failed' => (int)($r['failed'] ?? 0), 'services' => (int)($r['services'] ?? 0)]);
    }

    public function syncCancel(int $id): never
    {
        ServiceSync::cancel($id);
        flash('success', 'بروزرسانی گروهی متوقف شد؛ اطلاعات دریافت‌شده تا این لحظه حفظ می‌شود.');
        redirect('/admin/growth/services#sync');
    }

    // ------------------------------------------------------------------ settings: general, ranks, tracks, seasons
    public function settings(): string
    {
        return view('admin/growth/settings', ['title' => 'تنظیمات نظام رشد', 'ranks' => TraderGrowth::ranks(), 'tracks' => TraderGrowth::tracks(false), 'seasons' => TraderGrowth::seasons(),
            'trackUse' => DB::pairs('SELECT tg_track_id, COUNT(*) FROM users WHERE tg_track_id IS NOT NULL AND deleted_at IS NULL GROUP BY tg_track_id'), 'n' => TraderGrowth::count()]);
    }

    public function settingsSave(): never
    {
        $segs = array_values(array_intersect(Request::arr('growth_segments'), ['merchant', 'agent', 'employee', 'custom']));
        Settings::set([
            'growth_segments' => implode(',', $segs ?: ['merchant']), 'growth_track_self' => Request::bool('growth_track_self') ? '1' : '0',
            'growth_show_badge' => Request::bool('growth_show_badge') ? '1' : '0', 'growth_currency' => mb_substr(trim(Request::str('growth_currency')), 0, 20) ?: 'تومان',
            'growth_deal_docs' => implode("\n", TraderGrowth::docLines(Request::str('growth_deal_docs'))),
        ]);
        Audit::log('growth.settings', 'settings', null);
        TraderGrowth::markAllDirty();
        flash('success', 'تنظیمات نظام رشد ذخیره شد.');
        redirect('/admin/growth/settings');
    }

    public function rankSave(?int $id = null): never
    {
        $title = trim(Request::str('title'));
        $from = max(1, Request::int('from_stage')); $to = max($from, Request::int('to_stage'));
        if ($title === '') { flash('danger', 'عنوان رتبه را وارد کنید.'); back(); }
        $row = ['title' => mb_substr($title, 0, 60), 'stars' => max(1, min(5, Request::int('stars', 1))), 'filled' => Request::bool('filled') ? 1 : 0, 'from_stage' => $from, 'to_stage' => $to,
            'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#f59e0b', 'sort' => $from];
        if ($id) DB::update('tg_ranks', $row, 'id = ?', [$id]); else DB::insert('tg_ranks', $row);
        TraderGrowth::flush();
        flash('success', 'رتبه ذخیره شد.');
        redirect('/admin/growth/settings#ranks');
    }

    public function rankDelete(int $id): never
    {
        DB::delete('tg_ranks', 'id = ?', [$id]);
        flash('success', 'رتبه حذف شد.');
        redirect('/admin/growth/settings#ranks');
    }

    public function trackSave(?int $id = null): never
    {
        $name = trim(Request::str('name'));
        if ($name === '') { flash('danger', 'نام مسیر را وارد کنید.'); back(); }
        $row = ['name' => mb_substr($name, 0, 100), 'sort' => Request::int('sort', 1), 'active' => Request::str('active', '1') === '0' ? 0 : 1];
        if ($id) DB::update('tg_tracks', $row, 'id = ?', [$id]); else DB::insert('tg_tracks', $row);
        TraderGrowth::markAllDirty();
        flash('success', 'مسیر تجاری ذخیره شد.');
        redirect('/admin/growth/settings#tracks');
    }

    public function trackDelete(int $id): never
    {
        if (DB::value('SELECT 1 FROM users WHERE tg_track_id = ? LIMIT 1', [$id]) || DB::value('SELECT 1 FROM tg_items WHERE track_id = ? LIMIT 1', [$id])) {
            DB::update('tg_tracks', ['active' => 0], 'id = ?', [$id]);
            flash('warning', 'این مسیر استفاده شده است؛ به جای حذف غیرفعال شد.');
        } else { DB::delete('tg_tracks', 'id = ?', [$id]); flash('success', 'مسیر حذف شد.'); }
        redirect('/admin/growth/settings#tracks');
    }

    public function seasonSave(?int $id = null): never
    {
        $title = trim(Request::str('title'));
        if ($title === '') { flash('danger', 'عنوان فصل را وارد کنید.'); back(); }
        $row = ['title' => mb_substr($title, 0, 150), 'sort' => Request::int('sort', 1), 'color' => preg_match('/^#[0-9a-f]{6}$/i', Request::str('color')) ? Request::str('color') : '#4f46e5'];
        if ($id) DB::update('tg_seasons', $row, 'id = ?', [$id]); else DB::insert('tg_seasons', $row);
        TraderGrowth::flush();
        flash('success', 'فصل ذخیره شد.');
        redirect('/admin/growth/settings#seasons');
    }

    public function seasonDelete(int $id): never
    {
        DB::run('UPDATE tg_stages SET season_id = NULL WHERE season_id = ?', [$id]);
        DB::delete('tg_seasons', 'id = ?', [$id]);
        flash('success', 'فصل حذف شد (مراحل آن بدون فصل ماندند).');
        redirect('/admin/growth/settings#seasons');
    }
}
