<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Notify;

/**
 * نظام رشد تاجر — dynamic stage engine.
 *
 * Every stage (grouped in seasons) has linked items: courses, specific events, "all events of a type",
 * "at least N events of a type" and services (purchased in the sales system). Items can be limited to a track
 * (تجارت داخلی / صادرات / تجارت بین‌الملل). Progress of a stage = weighted average of its components
 * (education, services, approved deals). Completion depends on the stage approval mode:
 *   auto   : progress >= pass percent
 *   expert : progress >= pass percent AND expert approval (request with documents)
 *   manual : expert approval only
 * Progress is always computed live, so newly added content lowers the percentage (10 of 11 → 90%) and an
 * incomplete earlier stage blocks advancement until its new items are done. The reached stage (high-water mark)
 * never drops automatically; an admin can place a trader on any stage (earlier stages are then waived).
 */
final class TraderGrowth
{
    public const APPROVALS = [
        'auto' => 'خودکار — با رسیدن به درصد عبور',
        'expert' => 'درصد عبور + تأیید کارشناس',
        'manual' => 'فقط تأیید کارشناس (با مدارک)',
    ];
    public const KINDS = [
        'course' => 'دوره آموزشی',
        'event' => 'جلسه مشخص',
        'event_all' => 'همه جلسات یک نوع',
        'event_count' => 'حداقل تعداد شرکت',
        'service' => 'خدمت',
    ];
    public const ICONS = ['lightbulb', 'graduation-cap', 'message-square', 'palette', 'handshake', 'truck', 'rocket', 'repeat', 'trophy', 'building-2', 'landmark', 'crown',
        'flag', 'mountain', 'target', 'briefcase', 'globe', 'store', 'package', 'coins', 'network', 'award', 'star', 'sparkles', 'book-open', 'users', 'chart-column', 'shield-check'];

    private static ?array $stages = null;
    private static ?array $items = null;
    private static ?array $ranks = null;
    private static array $userStage = [];

    public static function flush(): void
    {
        self::$stages = self::$items = self::$ranks = null;
    }

    // ------------------------------------------------------------------ configuration
    /** Active stages in order; each row gets 'no' (1-based position) */
    public static function stages(): array
    {
        if (self::$stages === null) {
            try {
                $rows = DB::all('SELECT s.*, se.title AS season_title, se.color AS season_color, se.sort AS season_sort FROM tg_stages s LEFT JOIN tg_seasons se ON se.id = s.season_id WHERE s.active = 1 ORDER BY s.sort, s.id');
            } catch (\Throwable) { $rows = []; }
            foreach ($rows as $i => &$r) $r['no'] = $i + 1;
            self::$stages = $rows;
        }
        return self::$stages;
    }

    public static function stageByNo(int $no): ?array
    {
        return self::stages()[$no - 1] ?? null;
    }

    public static function count(): int
    {
        return count(self::stages());
    }

    /** @return array<int,array> items grouped by stage id */
    public static function items(): array
    {
        if (self::$items === null) {
            self::$items = [];
            try { $rows = DB::all('SELECT * FROM tg_items ORDER BY stage_id, kind, id'); } catch (\Throwable) { $rows = []; }
            foreach ($rows as $r) self::$items[(int)$r['stage_id']][] = $r;
        }
        return self::$items;
    }

    public static function ranks(): array
    {
        if (self::$ranks === null) {
            try { self::$ranks = DB::all('SELECT * FROM tg_ranks ORDER BY from_stage, sort'); } catch (\Throwable) { self::$ranks = []; }
        }
        return self::$ranks;
    }

    public static function tracks(bool $activeOnly = true): array
    {
        try { return DB::all('SELECT * FROM tg_tracks' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id'); } catch (\Throwable) { return []; }
    }

    public static function seasons(): array
    {
        try { return DB::all('SELECT * FROM tg_seasons ORDER BY sort, id'); } catch (\Throwable) { return []; }
    }

    public static function segments(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)setting('growth_segments', 'merchant')))));
    }

    public static function participates(?array $u): bool
    {
        return $u && empty($u['deleted_at']) && in_array((string)($u['segment'] ?? ''), self::segments(), true);
    }

    public static function docLines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$text) ?: [])));
    }

    // ------------------------------------------------------------------ ranks & badges
    public static function rankFor(int $stageNo): ?array
    {
        if ($stageNo <= 0) return null;
        $best = null;
        foreach (self::ranks() as $r) {
            if ($stageNo >= (int)$r['from_stage'] && $stageNo <= (int)$r['to_stage']) return $r;
            if ($stageNo >= (int)$r['from_stage']) $best = $r;
        }
        return $best;
    }

    public static function stars(array $rank): string
    {
        $filled = (int)$rank['filled'] === 1;
        $one = '<svg class="tg-star' . ($filled ? ' on' : '') . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8l2.83 5.94 6.52.8-4.8 4.52 1.23 6.47L12 17.38l-5.78 3.15 1.23-6.47-4.8-4.52 6.52-.8z"/></svg>';
        return '<span class="tg-stars">' . str_repeat($one, max(1, min(5, (int)$rank['stars']))) . '</span>';
    }

    /** Rank badge (stars + title) for a stage number; '' when the user is not in the growth system */
    public static function badge(int $stageNo, bool $withTitle = true, string $class = ''): string
    {
        $r = self::rankFor($stageNo);
        if (!$r) return '';
        $st = self::stageByNo($stageNo);
        $tip = 'رتبه ' . $r['title'] . ' · مرحله ' . fa($stageNo) . ($st ? ' (' . $st['title'] . ')' : '');
        return '<span class="tg-rank ' . e($class) . '" style="--rc:' . e($r['color']) . '" title="' . e($tip) . '">' . self::stars($r) . ($withTitle ? '<b>' . e($r['title']) . '</b>' : '') . '</span>';
    }

    /** Cached stage number of a user (for name badges in lists that did not select tg_stage) */
    public static function cachedStage(int $uid): int
    {
        if (!array_key_exists($uid, self::$userStage)) {
            try { self::$userStage[$uid] = (int)(DB::value('SELECT tg_stage FROM users WHERE id = ?', [$uid]) ?? 0); } catch (\Throwable) { self::$userStage[$uid] = 0; }
        }
        return self::$userStage[$uid];
    }

    // ------------------------------------------------------------------ evaluation
    /**
     * Full live evaluation of one trader.
     * @return array{user:array,participant:bool,track_id:?int,stages:array,achieved:int,current:int,all_done:bool,count:int,rank:?array,overall:float,cur:?array,remaining:array,deals:array,needs_track:bool}
     */
    public static function evaluate(int $uid, ?array $u = null): array
    {
        $u ??= DB::one('SELECT * FROM users WHERE id = ?', [$uid]) ?? [];
        $stages = self::stages();
        $items = self::items();
        $track = !empty($u['tg_track_id']) ? (int)$u['tg_track_id'] : null;

        // --- user facts
        $courseDone = array_flip(array_map('intval', DB::column("SELECT course_id FROM enrollments WHERE user_id = ? AND status = 'completed'", [$uid])));
        $courseProg = DB::pairs('SELECT course_id, progress_pct FROM enrollments WHERE user_id = ?', [$uid]);
        $attended = array_flip(array_map('intval', DB::column('SELECT event_id FROM event_registrations WHERE user_id = ? AND joined_at IS NOT NULL', [$uid])));
        $attByType = DB::pairs('SELECT e.type, COUNT(*) FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.joined_at IS NOT NULL AND e.deleted_at IS NULL GROUP BY e.type', [$uid]);
        $svcRows = DB::all('SELECT * FROM tg_user_services WHERE user_id = ? AND is_active = 1 AND service_id IS NOT NULL ORDER BY source = \'manual\' DESC, purchased_at DESC', [$uid]);
        $received = [];
        foreach ($svcRows as $r) $received[(int)$r['service_id']] ??= $r;
        $dealsApproved = (int)DB::value("SELECT COUNT(*) FROM tg_deals WHERE user_id = ? AND status = 'approved'", [$uid]);
        $dealsPending = (int)DB::value("SELECT COUNT(*) FROM tg_deals WHERE user_id = ? AND status = 'pending'", [$uid]);
        $marks = DB::pairs('SELECT stage_id, status FROM tg_user_stages WHERE user_id = ?', [$uid]);
        $reqs = [];
        foreach (DB::all('SELECT * FROM tg_requests WHERE user_id = ? ORDER BY id', [$uid]) as $r) $reqs[(int)$r['stage_id']] = $r;

        // --- referenced content
        $courseIds = $eventIds = $svcIds = $types = [];
        foreach ($stages as $s) foreach ($items[(int)$s['id']] ?? [] as $it) {
            match ($it['kind']) {
                'course' => $courseIds[] = (int)$it['ref_id'],
                'event' => $eventIds[] = (int)$it['ref_id'],
                'service' => $svcIds[] = (int)$it['ref_id'],
                'event_all' => $types[] = (string)$it['ref_type'],
                default => null,
            };
        }
        $courseIds = array_values(array_unique(array_filter($courseIds)));
        $eventIds = array_values(array_unique(array_filter($eventIds)));
        $svcIds = array_values(array_unique(array_filter($svcIds)));
        $courses = $courseIds ? DB::pairs('SELECT id, title FROM courses WHERE deleted_at IS NULL AND id IN (' . DB::in($courseIds) . ')', array_values(array_unique($courseIds))) : [];
        $events = [];
        if ($eventIds) foreach (DB::all('SELECT id, type, title, starts_at FROM events WHERE deleted_at IS NULL AND id IN (' . DB::in($eventIds) . ')', array_values(array_unique($eventIds))) as $e) $events[(int)$e['id']] = $e;
        $catalog = [];
        if ($svcIds) foreach (DB::all('SELECT * FROM tg_services WHERE active = 1 AND id IN (' . DB::in($svcIds) . ')', array_values(array_unique($svcIds))) as $sv) $catalog[(int)$sv['id']] = $sv;
        $byType = [];
        $types = array_values(array_unique($types));
        if ($types && $u) {
            $rows = DB::all("SELECT e.id, e.type, e.title, e.starts_at FROM events e
                              WHERE e.deleted_at IS NULL AND e.status = 'active' AND e.type IN (" . DB::in($types) . ")
                                AND (e.segments IS NULL OR e.segments = '' OR FIND_IN_SET(?, e.segments) > 0)
                                AND (e.group_id IS NULL OR EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = e.group_id AND gm.user_id = ?)
                                     OR EXISTS (SELECT 1 FROM event_registrations r0 WHERE r0.event_id = e.id AND r0.user_id = ?))
                              ORDER BY e.starts_at", array_merge($types, [(string)($u['segment'] ?? ''), $uid, $uid]));
            foreach ($rows as $e) $byType[$e['type']][] = $e;
        }

        $needsTrack = false;
        $out = [];
        $leading = true; $lead = 0;
        foreach ($stages as $s) {
            $edu = ['w' => 0, 'wd' => 0, 'units' => 0, 'done' => 0, 'rows' => []];
            $svc = ['w' => 0, 'wd' => 0, 'units' => 0, 'done' => 0, 'rows' => []];
            foreach ($items[(int)$s['id']] ?? [] as $it) {
                if ($it['track_id'] !== null) {
                    if ($track === null) { $needsTrack = true; continue; }
                    if ((int)$it['track_id'] !== $track) continue;
                }
                $w = max(1, (int)$it['weight']);
                $ref = (int)$it['ref_id'];
                switch ($it['kind']) {
                    case 'course':
                        if (!isset($courses[$ref])) break;
                        $d = isset($courseDone[$ref]);
                        $edu['rows'][] = ['kind' => 'course', 'id' => $ref, 'title' => $courses[$ref], 'done' => $d, 'pct' => isset($courseProg[$ref]) ? (float)$courseProg[$ref] : null, 'url' => '/learn/course/' . $ref, 'weight' => $w, 'track_id' => $it['track_id']];
                        $edu['units']++; $edu['w'] += $w; if ($d) { $edu['done']++; $edu['wd'] += $w; }
                        break;
                    case 'event':
                        if (!isset($events[$ref])) break;
                        $d = isset($attended[$ref]);
                        $e = $events[$ref];
                        $edu['rows'][] = ['kind' => 'event', 'id' => $ref, 'etype' => $e['type'], 'title' => $e['title'], 'date' => $e['starts_at'], 'done' => $d, 'url' => '/learn/event/' . $ref, 'weight' => $w, 'track_id' => $it['track_id']];
                        $edu['units']++; $edu['w'] += $w; if ($d) { $edu['done']++; $edu['wd'] += $w; }
                        break;
                    case 'event_all':
                        $type = (string)$it['ref_type'];
                        $list = $byType[$type] ?? [];
                        $sub = []; $dn = 0;
                        foreach ($list as $e) { $d = isset($attended[(int)$e['id']]); if ($d) $dn++; $sub[] = ['id' => (int)$e['id'], 'title' => $e['title'], 'date' => $e['starts_at'], 'done' => $d]; }
                        $n = count($list);
                        $edu['rows'][] = ['kind' => 'event_all', 'etype' => $type, 'title' => 'همه ' . (EventService::TYPES[$type]['plural'] ?? $type), 'done' => $n > 0 && $dn >= $n, 'count' => $dn, 'total' => $n, 'sub' => $sub, 'url' => '/learn/events/' . $type, 'weight' => $w, 'track_id' => $it['track_id']];
                        $edu['units'] += $n; $edu['done'] += $dn; $edu['w'] += $w * $n; $edu['wd'] += $w * $dn;
                        break;
                    case 'event_count':
                        $type = (string)$it['ref_type'];
                        $need = max(1, (int)$it['min_count']);
                        $dn = min((int)($attByType[$type] ?? 0), $need);
                        $edu['rows'][] = ['kind' => 'event_count', 'etype' => $type, 'title' => 'شرکت در ' . fa($need) . ' ' . (EventService::TYPES[$type]['label'] ?? $type), 'done' => $dn >= $need, 'count' => $dn, 'total' => $need, 'url' => '/learn/events/' . $type, 'weight' => $w, 'track_id' => $it['track_id']];
                        $edu['units'] += $need; $edu['done'] += $dn; $edu['w'] += $w * $need; $edu['wd'] += $w * $dn;
                        break;
                    case 'service':
                        if (!isset($catalog[$ref])) break;
                        $d = isset($received[$ref]);
                        $svc['rows'][] = ['kind' => 'service', 'id' => $ref, 'title' => $catalog[$ref]['name'], 'category' => $catalog[$ref]['category'], 'buy_url' => $catalog[$ref]['buy_url'], 'done' => $d, 'info' => $received[$ref] ?? null, 'weight' => $w, 'track_id' => $it['track_id']];
                        $svc['units']++; $svc['w'] += $w; if ($d) { $svc['done']++; $svc['wd'] += $w; }
                        break;
                }
            }
            $edu['pct'] = $edu['w'] ? $edu['wd'] * 100 / $edu['w'] : null;
            $svc['pct'] = $svc['w'] ? $svc['wd'] * 100 / $svc['w'] : null;
            $needDeals = (int)$s['min_deals'];
            $deal = $needDeals > 0 ? ['count' => $dealsApproved, 'need' => $needDeals, 'pending' => $dealsPending, 'pct' => min($dealsApproved, $needDeals) * 100 / $needDeals] : null;

            $parts = [];
            if ($edu['pct'] !== null && (int)$s['edu_weight'] > 0) $parts['edu'] = [(int)$s['edu_weight'], $edu['pct']];
            if ($svc['pct'] !== null && (int)$s['svc_weight'] > 0) $parts['svc'] = [(int)$s['svc_weight'], $svc['pct']];
            if ($deal && (int)$s['deal_weight'] > 0) $parts['deal'] = [(int)$s['deal_weight'], $deal['pct']];
            // a component with items but zero weight still counts equally when it is the only thing defined
            if (!$parts) {
                if ($edu['pct'] !== null) $parts['edu'] = [1, $edu['pct']];
                if ($svc['pct'] !== null) $parts['svc'] = [1, $svc['pct']];
                if ($deal) $parts['deal'] = [1, $deal['pct']];
            }
            $sumW = array_sum(array_column($parts, 0));
            $progress = $sumW ? array_sum(array_map(fn($p) => $p[0] * $p[1], $parts)) / $sumW : null;
            $shares = [];
            foreach ($parts as $k => $p) $shares[$k] = round($p[0] * 100 / $sumW);

            $mark = $marks[(int)$s['id']] ?? null;
            $req = $reqs[(int)$s['id']] ?? null;
            $approved = $mark === 'approved';
            $waived = $mark === 'waived';
            $pass = max(1, (int)$s['pass_percent']);
            $meets = $progress !== null && $progress + 0.001 >= $pass;
            $complete = match ($s['approval']) {
                'expert' => $approved && ($progress === null || $meets),
                'manual' => $approved,
                default => $meets,
            };
            if ($waived) $complete = true;
            $shown = $progress ?? ($complete ? 100.0 : 0.0);
            if ($s['approval'] === 'manual' && $progress === null) $shown = $approved ? 100.0 : 0.0;
            $pendingReq = $req && $req['status'] === 'pending';
            $canRequest = !$complete && $s['approval'] !== 'auto' && !$pendingReq && ($s['approval'] === 'manual' || $progress === null || $meets);

            $out[] = [
                'stage' => $s, 'no' => (int)$s['no'], 'edu' => $edu, 'svc' => $svc, 'deal' => $deal, 'shares' => $shares,
                'progress' => $progress, 'shown' => round(max(0, min(100, $shown)), 1), 'meets' => $meets, 'complete' => $complete,
                'approved' => $approved, 'waived' => $waived, 'request' => $req, 'pending_request' => $pendingReq, 'can_request' => $canRequest,
                'defined' => $progress !== null || $s['approval'] === 'manual',
            ];
            if ($leading && $complete) $lead++; else $leading = false;
        }

        $n = count($stages);
        $achieved = min($n, max((int)($u['tg_achieved'] ?? 0), $lead));
        $allDone = $n > 0 && $achieved >= $n;
        $current = $n ? min($n, $achieved + 1) : 0;
        foreach ($out as &$o) {
            $o['reopened'] = $o['no'] <= $achieved && !$o['complete'];
            $o['state'] = $o['complete'] ? 'done' : ($o['reopened'] ? 'reopened' : ($o['no'] === $current ? 'current' : 'locked'));
        }
        unset($o);
        $cur = $out[$current - 1] ?? null;
        $overall = $n ? ($achieved + ($allDone || !$cur ? 0 : $cur['shown'] / 100)) * 100 / $n : 0;

        $ev = [
            'user' => $u, 'participant' => self::participates($u), 'track_id' => $track, 'stages' => $out, 'achieved' => $achieved,
            'current' => $current, 'all_done' => $allDone, 'count' => $n, 'rank' => self::rankFor($current), 'overall' => round(min(100, $overall), 1),
            'cur' => $cur, 'deals' => ['approved' => $dealsApproved, 'pending' => $dealsPending], 'needs_track' => $needsTrack && $track === null,
            'services_at' => $u['tg_services_at'] ?? null,
        ];
        $ev['remaining'] = self::remaining($ev);
        return $ev;
    }

    /** Human-readable list of what is left before the next stage */
    public static function remaining(array $ev): array
    {
        $out = [];
        foreach ($ev['stages'] as $o) {
            if ($o['reopened']) $out[] = ['icon' => 'triangle-alert', 'tone' => 'warning', 'text' => 'مرحله ' . fa($o['no']) . ' «' . $o['stage']['title'] . '»: موارد جدید اضافه شده است؛ تا تکمیل آن‌ها، ارتقا به مرحله بعد متوقف است.', 'stage' => $o['no']];
        }
        $c = $ev['cur'];
        if (!$c || $ev['all_done']) return $out;
        if ($ev['needs_track']) $out[] = ['icon' => 'route', 'tone' => 'info', 'text' => 'مسیر تجاری خود (تجارت داخلی، صادرات یا تجارت بین‌الملل) را انتخاب کنید تا آموزش‌های مخصوص مسیرتان نمایش داده شود.'];
        if (!$c['defined'] && $c['stage']['approval'] !== 'manual') $out[] = ['icon' => 'hourglass', 'tone' => 'gray', 'text' => 'الزامات این مرحله هنوز توسط مدیر تعریف نشده است.'];
        foreach ($c['edu']['rows'] as $r) {
            if ($r['done']) continue;
            $out[] = match ($r['kind']) {
                'course' => ['icon' => 'book-open', 'tone' => 'primary', 'text' => 'تکمیل دوره «' . $r['title'] . '»' . ($r['pct'] ? ' (پیشرفت فعلی ' . fa((int)$r['pct']) . '٪)' : ''), 'url' => $r['url']],
                'event' => ['icon' => 'video', 'tone' => 'purple', 'text' => 'شرکت در «' . $r['title'] . '»', 'url' => $r['url']],
                'event_all' => ['icon' => 'video', 'tone' => 'purple', 'text' => 'شرکت در ' . fa($r['total'] - $r['count']) . ' جلسه دیگر از ' . $r['title'], 'url' => $r['url']],
                default => ['icon' => 'users', 'tone' => 'info', 'text' => 'شرکت در ' . fa($r['total'] - $r['count']) . ' جلسه دیگر (' . $r['title'] . ')', 'url' => $r['url']],
            };
        }
        foreach ($c['svc']['rows'] as $r) if (!$r['done']) $out[] = ['icon' => 'package', 'tone' => 'warning', 'text' => 'دریافت خدمت «' . $r['title'] . '»', 'url' => $r['buy_url'] ?: null];
        if ($c['deal'] && $c['deal']['count'] < $c['deal']['need']) {
            $left = $c['deal']['need'] - $c['deal']['count'];
            $out[] = ['icon' => 'handshake', 'tone' => 'success', 'text' => 'ثبت و تأیید ' . fa($left) . ' معامله دیگر' . ($c['deal']['pending'] ? ' (' . fa($c['deal']['pending']) . ' معامله در انتظار بررسی است)' : '')];
        }
        $a = $c['stage']['approval'];
        if ($a !== 'auto' && !$c['approved']) {
            if ($c['pending_request']) $out[] = ['icon' => 'hourglass', 'tone' => 'info', 'text' => 'درخواست تأیید شما در انتظار بررسی کارشناس است.'];
            elseif ($c['can_request']) $out[] = ['icon' => 'send', 'tone' => 'primary', 'text' => 'ارسال درخواست تأیید مرحله' . (self::docLines($c['stage']['request_docs']) ? ' همراه با مدارک' : '') . ' برای کارشناس'];
            else $out[] = ['icon' => 'user-check', 'tone' => 'gray', 'text' => 'پس از رسیدن به ' . fa((int)$c['stage']['pass_percent']) . '٪، درخواست تأیید کارشناس را ارسال کنید.'];
        } elseif ($c['progress'] !== null && !$c['meets'] && (int)$c['stage']['pass_percent'] < 100) {
            array_unshift($out, ['icon' => 'percent', 'tone' => 'gray', 'text' => 'برای عبور از این مرحله رسیدن به ' . fa((int)$c['stage']['pass_percent']) . '٪ کافی است.']);
        }
        return $out;
    }

    // ------------------------------------------------------------------ persistence
    /** Evaluate and store the cached stage/progress; records history and notifies on advancement */
    public static function refresh(int $uid, bool $notify = true): ?array
    {
        try {
            $u = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
        } catch (\Throwable) { return null; }
        if (!$u || !array_key_exists('tg_stage', $u)) return null;
        if (!self::participates($u) || !self::count() || $u['status'] === 'pending') {
            DB::update('users', ['tg_stage' => 0, 'tg_progress' => 0, 'tg_dirty' => 0, 'tg_calc_at' => now()], 'id = ?', [$uid]);
            self::$userStage[$uid] = 0;
            return null;
        }
        $ev = self::evaluate($uid, $u);
        $oldAch = (int)$u['tg_achieved'];
        $oldStage = (int)$u['tg_stage'];
        DB::update('users', ['tg_stage' => $ev['current'], 'tg_achieved' => $ev['achieved'], 'tg_progress' => $ev['cur'] ? $ev['cur']['shown'] : 100, 'tg_dirty' => 0, 'tg_calc_at' => now()], 'id = ?', [$uid]);
        self::$userStage[$uid] = $ev['current'];
        if ($ev['achieved'] > $oldAch) {
            DB::insert('tg_history', ['user_id' => $uid, 'from_stage' => $oldStage, 'to_stage' => $ev['current'], 'kind' => 'auto', 'note' => $ev['all_done'] ? 'تکمیل همه مراحل' : 'تکمیل مرحله ' . $ev['achieved'], 'created_at' => now()]);
            if ($notify && $u['tg_calc_at'] !== null) {
                $oldRank = self::rankFor($oldStage);
                $title = $ev['all_done'] ? 'تبریک! همه مراحل نظام رشد تاجر را کامل کردید' : 'تبریک! به مرحله ' . fa($ev['current']) . ' «' . ($ev['cur']['stage']['title'] ?? '') . '» رسیدید';
                $body = ($ev['rank'] && (!$oldRank || (int)$oldRank['id'] !== (int)$ev['rank']['id'])) ? 'رتبه جدید شما: ' . $ev['rank']['title'] : 'نظام رشد تاجر';
                Notify::send($uid, 'growth', $title, $body, url('/learn/growth'));
            }
        }
        return $ev;
    }

    /** New content / config changed: every trader must be recalculated (a few now, the rest by cron / on visit) */
    public static function markAllDirty(bool $processNow = true): void
    {
        try { DB::run('UPDATE users SET tg_dirty = 1 WHERE deleted_at IS NULL'); } catch (\Throwable) { return; }
        self::flush();
        if ($processNow) self::processDirty(150, 3.0);
    }

    public static function dirtyCount(): int
    {
        try { return (int)DB::value('SELECT COUNT(*) FROM users WHERE tg_dirty = 1 AND deleted_at IS NULL'); } catch (\Throwable) { return 0; }
    }

    public static function processDirty(int $max = 500, float $seconds = 20.0): int
    {
        $t = microtime(true);
        try { $ids = DB::column('SELECT id FROM users WHERE tg_dirty = 1 AND deleted_at IS NULL ORDER BY id LIMIT ' . max(1, $max)); } catch (\Throwable) { return 0; }
        $n = 0;
        foreach ($ids as $id) {
            self::refresh((int)$id);
            $n++;
            if (microtime(true) - $t > $seconds) break;
        }
        return $n;
    }

    // ------------------------------------------------------------------ admin actions
    /** Place a trader on a stage (earlier stages are waived); allows moving down too */
    public static function place(int $uid, int $no, ?int $by, string $note = ''): void
    {
        $stages = self::stages();
        $n = count($stages);
        $no = max(1, min($n, $no));
        $old = (int)DB::value('SELECT tg_stage FROM users WHERE id = ?', [$uid]);
        DB::transaction(function () use ($uid, $no, $by, $note, $stages) {
            foreach ($stages as $s) {
                if ((int)$s['no'] < $no) {
                    if (DB::value('SELECT status FROM tg_user_stages WHERE user_id = ? AND stage_id = ?', [$uid, (int)$s['id']]) !== 'approved') {
                        DB::upsert('tg_user_stages', ['user_id' => $uid, 'stage_id' => (int)$s['id'], 'status' => 'waived', 'by_user' => $by, 'note' => mb_substr($note ?: 'تعیین مرحله توسط مدیر', 0, 250), 'created_at' => now()], ['status', 'by_user', 'note', 'created_at']);
                    }
                } else {
                    DB::delete('tg_user_stages', 'user_id = ? AND stage_id = ?', [$uid, (int)$s['id']]);
                }
            }
            DB::update('users', ['tg_achieved' => $no - 1, 'tg_stage' => $no], 'id = ?', [$uid]);
        });
        DB::insert('tg_history', ['user_id' => $uid, 'from_stage' => $old, 'to_stage' => $no, 'kind' => 'admin', 'by_user' => $by, 'note' => mb_substr($note ?: 'تعیین مرحله توسط مدیر', 0, 250), 'created_at' => now()]);
        $ev = self::refresh($uid, false);
        $st = self::stageByNo($ev['current'] ?? $no);
        Notify::send($uid, 'growth', 'مرحله رشد شما: ' . fa($ev['current'] ?? $no) . ' «' . ($st['title'] ?? '') . '»', $note, url('/learn/growth'));
    }

    public static function approve(int $uid, int $stageId, ?int $by, string $note = ''): void
    {
        DB::upsert('tg_user_stages', ['user_id' => $uid, 'stage_id' => $stageId, 'status' => 'approved', 'by_user' => $by, 'note' => mb_substr($note, 0, 250) ?: null, 'created_at' => now()], ['status', 'by_user', 'note', 'created_at']);
        self::refresh($uid);
    }

    public static function revoke(int $uid, int $stageId): void
    {
        DB::delete('tg_user_stages', 'user_id = ? AND stage_id = ?', [$uid, $stageId]);
        self::refresh($uid, false);
    }

    /** Renumber stage sort 1..n after reordering */
    public static function renumber(): void
    {
        $ids = DB::column('SELECT id FROM tg_stages ORDER BY active DESC, sort, id');
        foreach ($ids as $i => $id) DB::update('tg_stages', ['sort' => $i + 1], 'id = ?', [(int)$id]);
        self::flush();
    }

    /** Does a new/removed event of this type change anybody's requirements? */
    public static function eventTypeUsed(string $type): bool
    {
        try { return (bool)DB::value("SELECT 1 FROM tg_items WHERE kind = 'event_all' AND ref_type = ? LIMIT 1", [$type]); } catch (\Throwable) { return false; }
    }

    // ------------------------------------------------------------------ deal stats (for experts)
    public static function dealStats(int $uid): array
    {
        $rows = DB::all("SELECT customer, amount, deal_date, product FROM tg_deals WHERE user_id = ? AND status = 'approved'", [$uid]);
        $cust = []; $months = []; $products = []; $sum = 0.0;
        foreach ($rows as $r) {
            $k = mb_strtolower(trim((string)$r['customer']));
            $cust[$k] = ($cust[$k] ?? 0) + 1;
            $products[mb_strtolower(trim((string)$r['product']))] = 1;
            if ($r['deal_date']) $months[substr((string)$r['deal_date'], 0, 7)] = 1;
            $sum += (float)$r['amount'];
        }
        return ['count' => count($rows), 'customers' => count($cust), 'repeat_customers' => count(array_filter($cust, fn($c) => $c > 1)), 'months' => count($months), 'products' => count($products), 'sum' => $sum];
    }
}
