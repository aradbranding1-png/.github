<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Jalali;
use App\Core\Notify;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\GrowthDocs;
use App\Services\ServiceSync;
use App\Services\TraderGrowth;

/** Trader side of نظام رشد تاجر: path, stage details, deals, stage requests, track choice */
final class GrowthLearnController
{
    /** Root, admins and training managers (مسئول آموزش) see the whole growth system */
    public static function staff(): bool
    {
        return \App\Core\Gate::isRoot() || can_any(['growth.view', 'team.view', 'dashboard.view']);
    }

    public function index(): string
    {
        $staff = self::staff();
        if ($staff && (Request::str('view') === 'overview' || !TraderGrowth::participates(Auth::user()))) return $this->overview();
        $uid = (int)Auth::id();
        $ev = TraderGrowth::refresh($uid) ?? TraderGrowth::evaluate($uid);
        $deals = DB::all('SELECT * FROM tg_deals WHERE user_id = ? ORDER BY COALESCE(deal_date, DATE(created_at)) DESC, id DESC', [$uid]);
        $requests = DB::all('SELECT q.*, s.title AS stage_title FROM tg_requests q JOIN tg_stages s ON s.id = q.stage_id WHERE q.user_id = ? ORDER BY q.id DESC', [$uid]);
        $dealsOpen = false;
        foreach ($ev['stages'] as $o) if ((int)$o['stage']['allow_deals'] === 1 && $o['no'] <= max(1, $ev['current'])) $dealsOpen = true;
        return view('learn/growth', [
            'title' => 'نظام رشد تاجر من', 'ev' => $ev, 'deals' => $deals, 'dealDocs' => GrowthDocs::forOwners('deal', array_map(fn($d) => (int)$d['id'], $deals)),
            'requests' => $requests, 'reqDocs' => GrowthDocs::forOwners('request', array_map(fn($r) => (int)$r['id'], $requests)),
            'dealsOpen' => $dealsOpen || (bool)$deals, 'tracks' => TraderGrowth::tracks(), 'dealLabels' => TraderGrowth::docLines((string)setting('growth_deal_docs')),
            'history' => DB::all('SELECT * FROM tg_history WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid]),
            'legacy' => DB::tableExists('growth_history') ? DB::all('SELECT h.*, s.name AS stage_name, s.color, g.name AS group_name FROM growth_history h JOIN growth_stages s ON s.id = h.stage_id JOIN `groups` g ON g.id = h.group_id WHERE h.user_id = ? ORDER BY h.id DESC LIMIT 20', [$uid]) : [],
            'phones' => count(ServiceSync::phones($uid)), 'canSync' => ServiceSync::configured(), 'staff' => $staff,
        ]);
    }

    /** Staff view: the stages as a roadmap with what each stage needs and how many traders are on it */
    private function overview(): string
    {
        $stages = TraderGrowth::stages();
        $items = TraderGrowth::items();
        $us = \App\Services\Scope::userSql('u.id');
        $segs = TraderGrowth::segments() ?: ['merchant'];
        $segIn = implode(',', array_fill(0, count($segs), '?'));
        $base = "u.deleted_at IS NULL AND u.segment IN ($segIn)" . $us['sql'];
        $args = array_merge($segs, $us['params']);
        $participants = (int)DB::value("SELECT COUNT(*) FROM users u WHERE $base", $args);
        $counts = DB::pairs("SELECT u.tg_stage, COUNT(*) FROM users u WHERE $base AND u.tg_stage > 0 GROUP BY u.tg_stage", $args);
        $avg = (float)(DB::value("SELECT AVG(u.tg_progress) FROM users u WHERE $base AND u.tg_stage > 0", $args) ?? 0);
        // titles of the courses each stage requires
        $cids = [];
        foreach ($items as $list) foreach ($list as $it) if ($it['kind'] === 'course' && $it['ref_id']) $cids[] = (int)$it['ref_id'];
        $cids = array_values(array_unique($cids));
        $courses = $cids ? DB::pairs('SELECT id, title FROM courses WHERE id IN (' . DB::in($cids) . ')', $cids) : [];
        $rankCounts = [];
        foreach (TraderGrowth::ranks() as $r) {
            $rankCounts[] = ['rank' => $r, 'n' => array_sum(array_map(fn($no) => (int)($counts[$no] ?? 0), range((int)$r['from_stage'], max((int)$r['from_stage'], (int)$r['to_stage']))))];
        }
        return view('learn/growth_overview', [
            'title' => 'نظام رشد تاجر', 'stages' => $stages, 'items' => $items, 'courses' => $courses, 'counts' => $counts,
            'participants' => $participants, 'started' => array_sum($counts), 'avg' => $avg, 'rankCounts' => $rankCounts,
            'scoped' => $us['sql'] !== '', 'isParticipant' => TraderGrowth::participates(Auth::user()),
        ]);
    }

    public function track(): never
    {
        $u = Auth::user();
        if (setting('growth_track_self', '1') !== '1' && !empty($u['tg_track_id'])) throw new HttpException(403, 'تغییر مسیر تجاری فقط توسط کارشناس امکان‌پذیر است.');
        $t = Request::int('track_id');
        if (!DB::value('SELECT 1 FROM tg_tracks WHERE id = ? AND active = 1', [$t])) throw new HttpException(422);
        DB::update('users', ['tg_track_id' => $t], 'id = ?', [(int)$u['id']]);
        TraderGrowth::refresh((int)$u['id'], false);
        flash('success', 'مسیر تجاری شما ثبت شد: ' . DB::value('SELECT name FROM tg_tracks WHERE id = ?', [$t]));
        redirect('/learn/growth');
    }

    private function dealRow(): array
    {
        $product = trim(Request::str('product'));
        $customer = trim(Request::str('customer'));
        if ($product === '' || $customer === '') { keep_old($_POST); flash('danger', 'محصول و مشتری را وارد کنید.'); redirect('/learn/growth#deals'); }
        $amount = preg_replace('/\D+/', '', normalize_input(Request::str('amount'))) ?? '';
        $date = null;
        if (trim(Request::str('deal_date')) !== '') {
            $date = Jalali::parse(normalize_input(Request::str('deal_date')));
            if (!$date) { keep_old($_POST); flash('danger', 'تاریخ معامله را به شکل ۱۴۰۵/۰۷/۰۱ وارد کنید.'); redirect('/learn/growth#deals'); }
            if ($date > date('Y-m-d')) { keep_old($_POST); flash('danger', 'تاریخ معامله نمی‌تواند در آینده باشد.'); redirect('/learn/growth#deals'); }
        }
        return ['product' => mb_substr($product, 0, 200), 'customer' => mb_substr($customer, 0, 200), 'market' => mb_substr(trim(Request::str('market')), 0, 150) ?: null,
            'amount' => $amount !== '' ? substr($amount, 0, 19) : null, 'deal_date' => $date, 'description' => mb_substr(trim(Request::str('description')), 0, 3000) ?: null];
    }

    public function dealStore(): never
    {
        $uid = (int)Auth::id();
        $ev = TraderGrowth::evaluate($uid);
        $open = false;
        foreach ($ev['stages'] as $o) if ((int)$o['stage']['allow_deals'] === 1 && $o['no'] <= max(1, $ev['current'])) $open = true;
        if (!$open) { flash('danger', 'ثبت معامله از مرحله «تجارت اول» فعال می‌شود.'); redirect('/learn/growth'); }
        if (!RateLimiter::hit('tg-deal:' . $uid, 20, 3600)) { flash('danger', 'تعداد ثبت معامله در این ساعت زیاد است؛ کمی بعد دوباره تلاش کنید.'); redirect('/learn/growth#deals'); }
        $row = $this->dealRow();
        $labels = TraderGrowth::docLines((string)setting('growth_deal_docs'));
        try {
            $files = GrowthDocs::collect($labels);
            if (!$files) { keep_old($_POST); flash('danger', 'حداقل یک مدرک (عکس، فیلم، فاکتور یا سند حمل) پیوست کنید.'); redirect('/learn/growth#deals'); }
            $id = DB::insert('tg_deals', $row + ['user_id' => $uid, 'status' => 'pending', 'created_at' => now()]);
            try { $n = GrowthDocs::store('deal', $id, $labels); }
            catch (\Throwable $e) { DB::delete('tg_docs', "owner_type = 'deal' AND owner_id = ?", [$id]); DB::delete('tg_deals', 'id = ?', [$id]); throw $e; }
        } catch (HttpException $e) {
            keep_old($_POST); flash('danger', $e->getMessage()); redirect('/learn/growth#deals');
        }
        Audit::log('growth.deal_submit', 'tg_deal', $id, 'success', ['docs' => $n]);
        $reviewers = array_map('intval', Notify::usersWithPermission('growth.approve'));
        Notify::send(array_diff($reviewers, [$uid]), 'growth', 'معامله جدید برای بررسی: ' . full_name(Auth::user()), $row['product'] . ' — ' . $row['customer'], url('/admin/growth/review/deal/' . $id));
        flash('success', 'معامله ثبت شد و پس از بررسی کارشناس در نظام رشد شما محاسبه می‌شود.');
        redirect('/learn/growth#deals');
    }

    private function ownDeal(int $id): array
    {
        $d = DB::one('SELECT * FROM tg_deals WHERE id = ? AND user_id = ?', [$id, (int)Auth::id()]) ?? throw new HttpException(404);
        if (!in_array($d['status'], ['pending', 'rejected'], true)) throw new HttpException(403, 'معامله تأییدشده قابل ویرایش نیست.');
        return $d;
    }

    public function dealUpdate(int $id): never
    {
        $d = $this->ownDeal($id);
        $row = $this->dealRow();
        $labels = TraderGrowth::docLines((string)setting('growth_deal_docs'));
        try { $n = GrowthDocs::store('deal', $id, $labels); }
        catch (HttpException $e) { flash('danger', $e->getMessage()); redirect('/learn/growth#deal-' . $id); }
        DB::update('tg_deals', $row + ['status' => 'pending', 'updated_at' => now()], 'id = ?', [$id]);
        if ($d['status'] === 'rejected') {
            Notify::send(array_diff(array_map('intval', Notify::usersWithPermission('growth.approve')), [(int)Auth::id()]), 'growth', 'معامله اصلاح‌شده برای بررسی: ' . full_name(Auth::user()), $row['product'], url('/admin/growth/review/deal/' . $id));
        }
        flash('success', 'معامله به‌روزرسانی شد' . ($n ? ' و ' . fa($n) . ' مدرک اضافه شد' : '') . ($d['status'] === 'rejected' ? '؛ دوباره برای بررسی ارسال شد.' : '.'));
        redirect('/learn/growth#deal-' . $id);
    }

    public function dealDelete(int $id): never
    {
        $this->ownDeal($id);
        DB::delete('tg_deals', 'id = ?', [$id]);
        DB::run("UPDATE files f JOIN tg_docs d ON d.file_id = f.id SET f.deleted_at = ? WHERE d.owner_type = 'deal' AND d.owner_id = ?", [now(), $id]);
        DB::delete('tg_docs', "owner_type = 'deal' AND owner_id = ?", [$id]);
        flash('success', 'معامله حذف شد.');
        redirect('/learn/growth#deals');
    }

    /** Ask the expert to approve a stage (expert / manual approval modes) */
    public function request(int $stageId): never
    {
        $uid = (int)Auth::id();
        $ev = TraderGrowth::evaluate($uid);
        $o = null;
        foreach ($ev['stages'] as $x) if ((int)$x['stage']['id'] === $stageId) $o = $x;
        if (!$o) throw new HttpException(404);
        if (!in_array($o['state'], ['current', 'reopened'], true) || !$o['can_request']) { flash('danger', 'در حال حاضر امکان ارسال درخواست برای این مرحله وجود ندارد.'); redirect('/learn/growth'); }
        $labels = TraderGrowth::docLines($o['stage']['request_docs']);
        try {
            $files = GrowthDocs::collect($labels);
            $have = array_unique(array_map(fn($f) => $f[1], $files));
            $missing = array_diff($labels, $have);
            if ($missing) { flash('danger', 'این مدارک را پیوست کنید: ' . implode('، ', $missing)); redirect('/learn/growth#stage-' . $o['no']); }
            $id = DB::insert('tg_requests', ['user_id' => $uid, 'stage_id' => $stageId, 'note' => mb_substr(trim(Request::str('note')), 0, 3000) ?: null, 'status' => 'pending', 'created_at' => now()]);
            try { GrowthDocs::store('request', $id, $labels); }
            catch (\Throwable $e) { DB::delete('tg_docs', "owner_type = 'request' AND owner_id = ?", [$id]); DB::delete('tg_requests', 'id = ?', [$id]); throw $e; }
        } catch (HttpException $e) {
            flash('danger', $e->getMessage()); redirect('/learn/growth#stage-' . $o['no']);
        }
        Audit::log('growth.request', 'tg_request', $id, 'success', ['stage' => $stageId]);
        Notify::send(array_diff(array_map('intval', Notify::usersWithPermission('growth.approve')), [$uid]), 'growth', 'درخواست تأیید مرحله «' . $o['stage']['title'] . '»: ' . full_name(Auth::user()), '', url('/admin/growth/review/request/' . $id));
        flash('success', 'درخواست تأیید مرحله «' . $o['stage']['title'] . '» برای کارشناس ارسال شد.');
        redirect('/learn/growth#stage-' . $o['no']);
    }

    /** Trader refreshes own purchased services (rate limited) */
    public function sync(): never
    {
        $uid = (int)Auth::id();
        if (!ServiceSync::configured()) { flash('warning', 'اتصال به سامانه خدمات هنوز فعال نشده است.'); redirect('/learn/growth'); }
        if (!RateLimiter::hit('tg-sync:' . $uid, 3, 3600)) { flash('warning', 'بروزرسانی خدمات حداکثر ۳ بار در ساعت امکان‌پذیر است.'); redirect('/learn/growth'); }
        $r = ServiceSync::syncUser($uid);
        flash($r['ok'] ? 'success' : 'warning', $r['ok'] ? 'خدمات شما بروزرسانی شد (' . fa($r['count']) . ' خدمت).' : 'بروزرسانی کامل نشد؛ لطفاً بعداً دوباره تلاش کنید.');
        redirect('/learn/growth#services');
    }
}
