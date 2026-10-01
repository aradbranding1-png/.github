<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  آمارِ واحدِ پذیرشِ نیرو — «یک منبع، یک تعریف» برای همه‌ی صفحه‌ها
 * ═══════════════════════════════════════════════════════════════════════
 *  هر عدد این‌جا یک تعریفِ ثابت دارد و از رکوردهای واقعیِ فرایند ساخته می‌شود؛ هر عدد قابلِ کلیک است و
 *  rm_people() دقیقاً همان رکوردهایی را که آن عدد را ساخته‌اند برمی‌گرداند.
 *
 *  ورود شماره      : reception_applicants.created_at در بازه
 *  اختصاص          : reception_applicants.assigned_at در بازه (به کارشناسِ همان ردیف)
 *  تماس (کالیزر)   : تماس‌های کالیزرِ کارشناس با «متقاضی» (reception_callizer_calls) — تماس با مشتری جداست و این‌جا نمی‌آید
 *  تماسِ ثبت‌شده   : reception_calls (تماس‌هایی که کارشناس در پرونده‌ی متقاضی ثبت کرده) — «موفق» = result = success
 *  دعوت            : رزروِ میتینگِ آنلاین / ثبتِ مصاحبه‌ی حضوری که «در بازه ثبت شده» (لغوشده‌ها حساب نمی‌شوند)
 *  جلسه            : همان رزرو/مصاحبه بر اساسِ «تاریخِ جلسه» در بازه
 *  حاضر / غایب      : نتیجه‌ی ثبت‌شده‌ی همان جلسه (آنلاین: attendance رزرو، حضوری: done / no_show)؛ بقیه = «ثبت‌نشده»
 *  پیگیری مجدد     : رویدادِ followup_set در مسیرِ پیگیری (افرادِ یکتا)
 *  تعیین تکلیف     : رویدادِ closed (به‌جز بایگانیِ خودکار)؛ «پیوست» = closed با نتیجه‌ی joined
 *  ارجاع به سرپرست : reception_applicants.referred_at در بازه (به هر سرپرست / تیم)
 *  جلسه‌ی بدونِ آمار: جلسه‌ای که وقتش گذشته، هیچ حاضر/غایبی برایش ثبت نشده و برگزارکننده هم اعلامِ حضور نکرده
 *                   ← «احتمالاً جلسه‌رونده حاضر نبوده» (برای پیدا کردنِ همین مشکل).
 *  همه‌ی تاریخ‌ها بر اساسِ تقویمِ شمسی انتخاب می‌شوند (rm_range).
 */

/** بازه: [از, تا (میلادی), برچسب] */
function rm_range(string $preset, string $fromJ = '', string $toJ = ''): array
{
    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('-' . (((int) date('N') + 1) % 7) . ' days')); // شنبه
    switch ($preset) {
        case 'yesterday':
            $d = date('Y-m-d', strtotime('-1 day'));
            return [$d, $d, 'دیروز'];
        case 'this_week':
            return [$weekStart, $today, 'این هفته'];
        case 'last_week':
            return [date('Y-m-d', strtotime($weekStart . ' -7 days')), date('Y-m-d', strtotime($weekStart . ' -1 day')), 'هفته‌ی گذشته'];
        case 'this_month':
            return [rx_jalali_month(0)[0], $today, 'این ماه'];
        case 'last_month':
            [$f, $t] = rx_jalali_month(-1);
            return [$f, $t, 'ماهِ گذشته'];
        case 'custom':
            $f = $fromJ !== '' ? to_gregorian($fromJ) : null;
            $t = $toJ !== '' ? to_gregorian($toJ) : null;
            if ($f && $t) return [min($f, $t), max($f, $t), 'بازه‌ی دلخواه'];
            return [$today, $today, 'امروز'];
        default:
            return [$today, $today, 'امروز'];
    }
}

function rm_presets(): array
{
    return ['today' => 'امروز', 'yesterday' => 'دیروز', 'this_week' => 'این هفته', 'last_week' => 'هفته‌ی گذشته', 'this_month' => 'این ماه', 'last_month' => 'ماهِ گذشته', 'custom' => 'بازه‌ی دلخواه'];
}

/** مراحلِ قیف (به همین ترتیب در داشبورد) */
function rm_steps(): array
{
    return [
        'imported'     => ['label' => 'شماره‌ی واردشده',          'icon' => 'fa-file-import'],
        'assigned'     => ['label' => 'اختصاص به کارشناس',         'icon' => 'fa-user-check'],
        'calls'        => ['label' => 'تماس با متقاضی (کالیزر)',        'icon' => 'fa-phone'],
        'logged'       => ['label' => 'تماسِ ثبت‌شده در پرونده',    'icon' => 'fa-phone-volume'],
        'success'      => ['label' => 'تماسِ موفق',                'icon' => 'fa-phone-flip'],
        'invited'      => ['label' => 'دعوت به جلسه',              'icon' => 'fa-envelope-open-text'],
        'met_online'   => ['label' => 'جلسه‌ی آنلاین',             'icon' => 'fa-video'],
        'met_inperson' => ['label' => 'جلسه‌ی حضوری',              'icon' => 'fa-building-user'],
        'present'      => ['label' => 'حاضر',                     'icon' => 'fa-user-check'],
        'absent'       => ['label' => 'غایب',                     'icon' => 'fa-user-xmark'],
        'recall'       => ['label' => 'پیگیری مجدد',              'icon' => 'fa-rotate'],
        'closed'       => ['label' => 'تعیین تکلیف',              'icon' => 'fa-flag-checkered'],
        'referred'     => ['label' => 'ارجاع به سرپرست',          'icon' => 'fa-user-tie'],
    ];
}

/** کارشناسانِ پذیرش [id => name] */
function rm_agent_names(PDO $pdo): array
{
    $out = [];
    foreach (function_exists('rp_agents') ? rp_agents($pdo) : [] as $a) $out[(int) $a['id']] = (string) $a['full_name'];
    return $out;
}

function rm_has_attendance(PDO $pdo): bool
{
    return function_exists('rp_bookings_have_attendance') && rp_bookings_have_attendance($pdo);
}

/**
 * همه‌ی شاخص‌ها در بازه، به تفکیکِ کارشناس + جمع.
 * @return array{total:array, per_agent:array<int,array>, extra:array}
 */
function rm_overview(PDO $pdo, string $from, string $to, int $agentId = 0): array
{
    $fromDt = $from . ' 00:00:00';
    $toDt = $to . ' 23:59:59';
    $keys = array_merge(array_keys(rm_steps()), ['inv_online', 'inv_inperson', 'pending', 'joined', 'present_online', 'absent_online', 'present_inperson', 'absent_inperson']);
    $per = [];
    $add = static function (array $rows, string $key) use (&$per): void {
        foreach ($rows as $r) {
            $a = (int) ($r['a'] ?? 0);
            $per[$a][$key] = ($per[$a][$key] ?? 0) + (int) $r['n'];
        }
    };
    $agentSql = static fn(string $col) => $agentId > 0 ? " AND $col = " . (int) $agentId : '';
    $q = static function (string $sql, array $params) use ($pdo): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('rm_overview: ' . $e->getMessage());
            return [];
        }
    };
    $att = rm_has_attendance($pdo);
    $ip = function_exists('reception_inperson_table_ready') && reception_inperson_table_ready($pdo);
    $slots = function_exists('reception_meeting_slots_ready') && reception_meeting_slots_ready($pdo);

    $add($q('SELECT assigned_agent_id a, COUNT(*) n FROM reception_applicants WHERE created_at BETWEEN ? AND ?' . $agentSql('assigned_agent_id') . ' GROUP BY assigned_agent_id', [$fromDt, $toDt]), 'imported');
    $add($q('SELECT assigned_agent_id a, COUNT(*) n FROM reception_applicants WHERE assigned_agent_id IS NOT NULL AND assigned_at BETWEEN ? AND ?' . $agentSql('assigned_agent_id') . ' GROUP BY assigned_agent_id', [$fromDt, $toDt]), 'assigned');
    if (function_exists('rx_cc_ready') && rx_cc_ready($pdo)) {
        $add($q('SELECT agent_user_id a, COUNT(*) n FROM reception_callizer_calls WHERE call_date BETWEEN ? AND ?' . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$from, $to]), 'calls');
    }
    $add($q('SELECT agent_user_id a, COUNT(*) n FROM reception_calls WHERE started_at BETWEEN ? AND ?' . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'logged');
    $add($q("SELECT agent_user_id a, COUNT(*) n FROM reception_calls WHERE result = 'success' AND started_at BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'success');

    // دعوت (بر اساسِ زمانِ ثبتِ دعوت) و جلسه (بر اساسِ تاریخِ جلسه)
    $inv = [];
    if ($slots) {
        $add($q("SELECT agent_user_id a, COUNT(DISTINCT applicant_id) n FROM reception_meeting_bookings WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'inv_online');
        $inv[] = "SELECT agent_user_id a, applicant_id app FROM reception_meeting_bookings WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id');
        $attCol = $att ? 'bk.attendance' : 'NULL';
        foreach ($q("SELECT bk.agent_user_id a, COUNT(*) n, SUM($attCol = 'attended') att, SUM($attCol = 'no_show') ns FROM reception_meeting_bookings bk JOIN reception_meeting_slots s ON s.id = bk.slot_id
                WHERE bk.status <> 'cancelled' AND s.slot_date BETWEEN ? AND ?" . $agentSql('bk.agent_user_id') . ' GROUP BY bk.agent_user_id', [$from, $to]) as $r) {
            $a = (int) $r['a'];
            $per[$a]['met_online'] = ($per[$a]['met_online'] ?? 0) + (int) $r['n'];
            $per[$a]['present'] = ($per[$a]['present'] ?? 0) + (int) $r['att'];
            $per[$a]['absent'] = ($per[$a]['absent'] ?? 0) + (int) $r['ns'];
            $per[$a]['present_online'] = ($per[$a]['present_online'] ?? 0) + (int) $r['att'];
            $per[$a]['absent_online'] = ($per[$a]['absent_online'] ?? 0) + (int) $r['ns'];
        }
    }
    if ($ip) {
        $add($q("SELECT agent_user_id a, COUNT(DISTINCT applicant_id) n FROM reception_inperson_interviews WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'inv_inperson');
        $inv[] = "SELECT agent_user_id a, applicant_id app FROM reception_inperson_interviews WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id');
        foreach ($q("SELECT agent_user_id a, COUNT(*) n, SUM(status = 'done') att, SUM(status = 'no_show') ns FROM reception_inperson_interviews
                WHERE status <> 'cancelled' AND interview_date BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$from, $to]) as $r) {
            $a = (int) $r['a'];
            $per[$a]['met_inperson'] = ($per[$a]['met_inperson'] ?? 0) + (int) $r['n'];
            $per[$a]['present'] = ($per[$a]['present'] ?? 0) + (int) $r['att'];
            $per[$a]['absent'] = ($per[$a]['absent'] ?? 0) + (int) $r['ns'];
            $per[$a]['present_inperson'] = ($per[$a]['present_inperson'] ?? 0) + (int) $r['att'];
            $per[$a]['absent_inperson'] = ($per[$a]['absent_inperson'] ?? 0) + (int) $r['ns'];
        }
    }
    if ($inv) {
        $params = [];
        foreach ($inv as $_) array_push($params, $fromDt, $toDt);
        $add($q('SELECT a, COUNT(DISTINCT app) n FROM (' . implode(' UNION ALL ', $inv) . ') x GROUP BY a', $params), 'invited');
    }
    // مسیرِ پیگیری: پیگیریِ مجدد و تعیین تکلیف
    $add($q("SELECT agent_user_id a, COUNT(DISTINCT applicant_id) n FROM reception_pipeline_events WHERE event = 'followup_set' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'recall');
    $add($q("SELECT agent_user_id a, COUNT(DISTINCT applicant_id) n FROM reception_pipeline_events WHERE event = 'closed' AND COALESCE(reason, '') <> 'archived' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'closed');
    $add($q("SELECT agent_user_id a, COUNT(DISTINCT applicant_id) n FROM reception_pipeline_events WHERE event = 'closed' AND reason = 'joined' AND created_at BETWEEN ? AND ?" . $agentSql('agent_user_id') . ' GROUP BY agent_user_id', [$fromDt, $toDt]), 'joined');
    $add($q('SELECT assigned_agent_id a, COUNT(*) n FROM reception_applicants WHERE referred_at BETWEEN ? AND ?' . $agentSql('assigned_agent_id') . ' GROUP BY assigned_agent_id', [$fromDt, $toDt]), 'referred');

    $blank = array_fill_keys($keys, 0);
    $total = $blank;
    $names = rm_agent_names($pdo);
    $out = [];
    foreach ($per as $a => $vals) {
        $row = array_merge($blank, $vals);
        $row['pending'] = max(0, $row['met_online'] + $row['met_inperson'] - $row['present'] - $row['absent']);
        foreach ($keys as $k) $total[$k] += $row[$k];
        if ($a <= 0) continue; // «بدونِ کارشناس» (شماره‌های عمومیِ واردشده) فقط در جمع
        $row['id'] = $a;
        $row['name'] = $names[$a] ?? ('#' . $a);
        $out[$a] = $row;
    }
    uasort($out, static fn($x, $y) => ($y['present'] <=> $x['present']) ?: ($y['invited'] <=> $x['invited']) ?: ($y['calls'] <=> $x['calls']));
    $total['rate'] = ($total['present'] + $total['absent']) > 0 ? round($total['present'] / ($total['present'] + $total['absent']) * 100, 1) : null;
    return ['total' => $total, 'per_agent' => $out];
}

/**
 * جلسه‌ها (هر «جلسه» = یک تایمِ آنلاینِ سرپرست، یا یک ساعتِ مصاحبه‌ی حضوریِ یک برگزارکننده) در بازه.
 * وضعیت: upcoming (هنوز نرسیده) | live (در حالِ برگزاری) | held (حاضر/غایب ثبت شده) | checked (برگزارکننده اعلامِ حضور کرده ولی آمار نزده)
 *        | host_absent (وقتش گذشته، هیچ آماری ثبت نشده و اعلامِ حضوری هم نیست ← احتمالاً جلسه‌رونده حاضر نبوده)
 */
function rm_sessions(PDO $pdo, string $from, string $to, int $hostId = 0, int $agentId = 0): array
{
    $rows = [];
    $att = rm_has_attendance($pdo);
    $grace = 60 * (int) (function_exists('rp_settings') ? (rp_settings($pdo)['await_minutes'] ?? 60) : 60);
    try {
        if (function_exists('reception_meeting_slots_ready') && reception_meeting_slots_ready($pdo)) {
            $attCol = $att ? 'bk.attendance' : 'NULL';
            $st = $pdo->prepare("SELECT 'online' kind, s.id ref, s.slot_date d, s.start_time t, s.supervisor_user_id host, s.capacity cap,
                    COUNT(bk.id) invited, SUM($attCol = 'attended') att, SUM($attCol = 'no_show') ns, 0 referred
                FROM reception_meeting_slots s JOIN reception_meeting_bookings bk ON bk.slot_id = s.id AND bk.status <> 'cancelled'
                WHERE s.slot_date BETWEEN ? AND ?" . ($hostId > 0 ? ' AND s.supervisor_user_id = ' . (int) $hostId : '') . ($agentId > 0 ? ' AND bk.agent_user_id = ' . (int) $agentId : '') . '
                GROUP BY s.id, s.slot_date, s.start_time, s.supervisor_user_id, s.capacity');
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (function_exists('reception_inperson_table_ready') && reception_inperson_table_ready($pdo)) {
            $ref = rx_ready($pdo) ? 'SUM(referred_supervisor_id IS NOT NULL)' : '0';
            $st = $pdo->prepare("SELECT 'inperson' kind, 0 ref, interview_date d, COALESCE(TIME_FORMAT(interview_time, '%H:%i'), '09:00') t, COALESCE(supervisor_user_id, 0) host, NULL cap,
                    COUNT(*) invited, SUM(status = 'done') att, SUM(status = 'no_show') ns, $ref referred
                FROM reception_inperson_interviews WHERE status <> 'cancelled' AND interview_date BETWEEN ? AND ?" . ($hostId > 0 ? ' AND supervisor_user_id = ' . (int) $hostId : '') . ($agentId > 0 ? ' AND agent_user_id = ' . (int) $agentId : '') . '
                GROUP BY interview_date, t, host');
            $st->execute([$from, $to]);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
    } catch (Throwable $e) {
        error_log('rm_sessions: ' . $e->getMessage());
    }
    $checkins = rx_checkins($pdo, $from, $to);
    $names = [];
    $hostIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int) $r['host'], $rows))));
    if ($hostIds) {
        foreach ($pdo->query('SELECT id, full_name FROM users WHERE id IN (' . implode(',', $hostIds) . ')')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $names[(int) $u['id']] = $u['full_name'];
    }
    $now = time();
    foreach ($rows as &$r) {
        $r['t'] = rx_hhmm((string) $r['t']);
        $r['host'] = (int) $r['host'];
        $r['host_name'] = $names[$r['host']] ?? ($r['host'] ? '#' . $r['host'] : 'بدونِ برگزارکننده');
        foreach (['invited', 'att', 'ns', 'referred'] as $k) $r[$k] = (int) $r[$k];
        $r['cap'] = $r['cap'] !== null ? (int) $r['cap'] : null;
        $r['pending'] = max(0, $r['invited'] - $r['att'] - $r['ns']);
        $r['checkin'] = $checkins[$r['kind'] . '|' . $r['host'] . '|' . $r['d'] . '|' . $r['t']] ?? null;
        $start = strtotime($r['d'] . ' ' . $r['t'] . ':00') ?: 0;
        if ($start > $now) $r['state'] = 'upcoming';
        elseif ($r['att'] + $r['ns'] > 0) $r['state'] = 'held';
        elseif ($r['checkin']) $r['state'] = 'checked';
        elseif ($now < $start + $grace) $r['state'] = 'live';
        else $r['state'] = 'host_absent';
        $r['key'] = $r['kind'] . '|' . $r['host'] . '|' . $r['d'] . '|' . $r['t'] . '|' . (int) $r['ref'];
    }
    unset($r);
    usort($rows, static fn($a, $b) => strcmp($a['d'] . $a['t'], $b['d'] . $b['t']));
    return $rows;
}

function rm_session_states(): array
{
    return [
        'upcoming'    => ['label' => 'هنوز نرسیده',                       'color' => 'secondary'],
        'live'        => ['label' => 'در حالِ برگزاری',                   'color' => 'info'],
        'held'        => ['label' => 'برگزار شد (حضور ثبت شده)',           'color' => 'success'],
        'checked'     => ['label' => 'جلسه‌رونده آمد؛ حضورِ افراد ثبت نشده', 'color' => 'warning'],
        'host_absent' => ['label' => 'بدونِ آمار — احتمالاً جلسه‌رونده حاضر نبوده', 'color' => 'danger'],
    ];
}

/** جمعِ جلسه‌ها به تفکیکِ نوع و برگزارکننده */
function rm_session_summary(array $sessions): array
{
    $blank = ['sessions' => 0, 'held' => 0, 'host_absent' => 0, 'checked' => 0, 'upcoming' => 0, 'cap' => 0, 'invited' => 0, 'att' => 0, 'ns' => 0, 'pending' => 0, 'referred' => 0];
    $out = ['all' => $blank, 'online' => $blank, 'inperson' => $blank, 'hosts' => []];
    foreach ($sessions as $s) {
        foreach (['all', $s['kind']] as $k) {
            $out[$k]['sessions']++;
            $out[$k][$s['state'] === 'held' ? 'held' : ($s['state'] === 'host_absent' ? 'host_absent' : ($s['state'] === 'checked' ? 'checked' : 'upcoming'))]++;
            $out[$k]['cap'] += (int) ($s['cap'] ?? 0);
            foreach (['invited', 'att', 'ns', 'pending', 'referred'] as $f) $out[$k][$f] += $s[$f];
        }
        $h = $s['host'];
        if (!isset($out['hosts'][$h])) $out['hosts'][$h] = $blank + ['name' => $s['host_name'], 'id' => $h];
        $out['hosts'][$h]['sessions']++;
        $out['hosts'][$h][$s['state'] === 'held' ? 'held' : ($s['state'] === 'host_absent' ? 'host_absent' : ($s['state'] === 'checked' ? 'checked' : 'upcoming'))]++;
        foreach (['invited', 'att', 'ns', 'pending', 'referred'] as $f) $out['hosts'][$h][$f] += $s[$f];
    }
    foreach (['all', 'online', 'inperson'] as $k) {
        $d = $out[$k]['att'] + $out[$k]['ns'];
        $out[$k]['rate'] = $d > 0 ? round($out[$k]['att'] / $d * 100, 1) : null;
    }
    uasort($out['hosts'], static fn($a, $b) => $b['sessions'] <=> $a['sessions']);
    return $out;
}

/** ارجاع به سرپرست / تیم در بازه (همه‌ی مسیرها) + چند تا بعد از مصاحبه‌ی حضوری */
function rm_referrals(PDO $pdo, string $from, string $to, int $agentId = 0): array
{
    $out = [];
    try {
        $st = $pdo->prepare('SELECT ra.supervisor_user_id s, u.full_name, t.id AS team_id, t.name AS team_name, COUNT(*) n
            FROM reception_applicants ra JOIN users u ON u.id = ra.supervisor_user_id LEFT JOIN teams t ON t.leader_user_id = ra.supervisor_user_id
            WHERE ra.referred_at BETWEEN ? AND ?' . ($agentId > 0 ? ' AND ra.assigned_agent_id = ' . (int) $agentId : '') . '
            GROUP BY ra.supervisor_user_id, u.full_name, t.id, t.name ORDER BY n DESC');
        $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int) $r['s']] = $r + ['after_interview' => 0];
        if (rx_ready($pdo)) {
            $st = $pdo->prepare('SELECT referred_supervisor_id s, COUNT(*) n FROM reception_inperson_interviews WHERE referred_at BETWEEN ? AND ?' . ($agentId > 0 ? ' AND agent_user_id = ' . (int) $agentId : '') . ' GROUP BY referred_supervisor_id');
            $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) if (isset($out[(int) $r['s']])) $out[(int) $r['s']]['after_interview'] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        error_log('rm_referrals: ' . $e->getMessage());
    }
    return $out;
}

/**
 * افرادِ پشتِ هر عدد (برای کلیک روی عدد). $metric: کلیدهای rm_steps + inv_online / inv_inperson / pending / joined
 *   + session (با $opts['session'] = کلیدِ جلسه) + host_absent / ref_sup (با $opts['sup'])
 * @return array{rows:array, title:string}
 */
function rm_people(PDO $pdo, string $metric, string $from, string $to, int $agentId = 0, array $opts = []): array
{
    $fromDt = $from . ' 00:00:00';
    $toDt = $to . ' 23:59:59';
    $ag = static fn(string $col) => $agentId > 0 ? " AND $col = " . (int) $agentId : '';
    $base = "SELECT ra.id, CONCAT(ra.first_name, ' ', ra.last_name) name, ra.mobile, ag.full_name agent_name";
    $att = rm_has_attendance($pdo) ? 'bk.attendance' : 'NULL';
    $titles = rm_steps() + ['inv_online' => ['label' => 'دعوت به میتینگِ آنلاین'], 'inv_inperson' => ['label' => 'دعوت به مصاحبه‌ی حضوری'],
        'pending' => ['label' => 'جلسه بدونِ ثبتِ حضور/غیاب'], 'joined' => ['label' => 'پیوستند'], 'session' => ['label' => 'افرادِ این جلسه'], 'ref_sup' => ['label' => 'ارجاع به این سرپرست']];
    $online = "SELECT ra.id, CONCAT(ra.first_name, ' ', ra.last_name) name, ra.mobile, ag.full_name agent_name, CONCAT(s.slot_date, ' ', s.start_time) at,
            CONCAT('آنلاین — ', COALESCE(ho.full_name, ''), ' — ', CASE $att WHEN 'attended' THEN 'حاضر' WHEN 'no_show' THEN 'غایب' ELSE 'ثبت‌نشده' END) detail
        FROM reception_meeting_bookings bk JOIN reception_meeting_slots s ON s.id = bk.slot_id JOIN reception_applicants ra ON ra.id = bk.applicant_id
        LEFT JOIN users ag ON ag.id = bk.agent_user_id LEFT JOIN users ho ON ho.id = s.supervisor_user_id WHERE bk.status <> 'cancelled'";
    $inperson = "SELECT ra.id, CONCAT(ra.first_name, ' ', ra.last_name) name, ra.mobile, ag.full_name agent_name, CONCAT(ii.interview_date, ' ', COALESCE(ii.interview_time, '')) at,
            CONCAT('حضوری — ', COALESCE(ho.full_name, ''), ' — ', CASE ii.status WHEN 'done' THEN 'حاضر' WHEN 'no_show' THEN 'غایب' ELSE 'ثبت‌نشده' END" . (rx_ready($pdo) ? ", COALESCE(CONCAT(' — ارجاع به ', (SELECT full_name FROM users WHERE id = ii.referred_supervisor_id)), '')" : '') . ") detail
        FROM reception_inperson_interviews ii JOIN reception_applicants ra ON ra.id = ii.applicant_id
        LEFT JOIN users ag ON ag.id = ii.agent_user_id LEFT JOIN users ho ON ho.id = ii.supervisor_user_id WHERE ii.status <> 'cancelled'";
    $sql = null;
    $params = [];
    switch ($metric) {
        case 'imported':
            $sql = "$base, ra.created_at at, CONCAT('منبع: ', ra.source" . (rx_ready($pdo) ? ", COALESCE(CONCAT(' / ', ra.lead_source), ''), COALESCE(CONCAT(' — ', ra.city), '')" : '') . ") detail
                FROM reception_applicants ra LEFT JOIN users ag ON ag.id = ra.assigned_agent_id WHERE ra.created_at BETWEEN ? AND ?" . $ag('ra.assigned_agent_id');
            $params = [$fromDt, $toDt];
            break;
        case 'assigned':
            $sql = "$base, ra.assigned_at at, ra.status detail FROM reception_applicants ra LEFT JOIN users ag ON ag.id = ra.assigned_agent_id
                WHERE ra.assigned_agent_id IS NOT NULL AND ra.assigned_at BETWEEN ? AND ?" . $ag('ra.assigned_agent_id');
            $params = [$fromDt, $toDt];
            break;
        case 'calls':
            // تماس‌های کالیزر با متقاضی (تماس با مشتری جداست)
            $sql = "SELECT COALESCE(ra.id, 0) id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ra.first_name, ''), ' ', COALESCE(ra.last_name, ''))), ''), '—') name, r.phone mobile,
                    ag.full_name agent_name, CONCAT(r.call_date, ' ', COALESCE(r.event_time, '')) at,
                    IF(r.connected = 1, CONCAT('برقرار — ', COALESCE(r.duration_seconds, 0), ' ثانیه'), 'بی‌پاسخ') detail
                FROM reception_callizer_calls r LEFT JOIN reception_applicants ra ON ra.id = r.applicant_id LEFT JOIN users ag ON ag.id = r.agent_user_id
                WHERE r.call_date BETWEEN ? AND ?" . $ag('r.agent_user_id');
            $params = [$from, $to];
            if (!function_exists('rx_cc_ready') || !rx_cc_ready($pdo)) return ['rows' => [], 'title' => (string) ($titles['calls']['label'] ?? '')];
            break;
        case 'logged':
        case 'success':
            $sql = "$base, rc.started_at at, COALESCE(rc.result, '') detail FROM reception_calls rc JOIN reception_applicants ra ON ra.id = rc.applicant_id
                LEFT JOIN users ag ON ag.id = rc.agent_user_id WHERE rc.started_at BETWEEN ? AND ?" . ($metric === 'success' ? " AND rc.result = 'success'" : '') . $ag('rc.agent_user_id');
            $params = [$fromDt, $toDt];
            break;
        case 'inv_online':
            $sql = "$online AND bk.created_at BETWEEN ? AND ?" . $ag('bk.agent_user_id');
            $params = [$fromDt, $toDt];
            break;
        case 'inv_inperson':
            $sql = "$inperson AND ii.created_at BETWEEN ? AND ?" . $ag('ii.agent_user_id');
            $params = [$fromDt, $toDt];
            break;
        case 'invited':
            $sql = "($online AND bk.created_at BETWEEN ? AND ?" . $ag('bk.agent_user_id') . ") UNION ALL ($inperson AND ii.created_at BETWEEN ? AND ?" . $ag('ii.agent_user_id') . ')';
            $params = [$fromDt, $toDt, $fromDt, $toDt];
            break;
        case 'met_online':
            $sql = "$online AND s.slot_date BETWEEN ? AND ?" . $ag('bk.agent_user_id');
            $params = [$from, $to];
            break;
        case 'met_inperson':
            $sql = "$inperson AND ii.interview_date BETWEEN ? AND ?" . $ag('ii.agent_user_id');
            $params = [$from, $to];
            break;
        case 'present':
        case 'absent':
        case 'pending':
            $oc = ['present' => "$att = 'attended'", 'absent' => "$att = 'no_show'", 'pending' => "$att IS NULL"][$metric];
            $ic = ['present' => "ii.status = 'done'", 'absent' => "ii.status = 'no_show'", 'pending' => "ii.status = 'scheduled'"][$metric];
            $sql = "($online AND s.slot_date BETWEEN ? AND ? AND $oc" . $ag('bk.agent_user_id') . ") UNION ALL ($inperson AND ii.interview_date BETWEEN ? AND ? AND $ic" . $ag('ii.agent_user_id') . ')';
            $params = [$from, $to, $from, $to];
            break;
        case 'session':
            [$kind, $host, $d, $t, $ref] = array_pad(explode('|', (string) ($opts['session'] ?? '')), 5, '');
            if ($kind === 'online') { // تایمِ آنلاین با شناسه‌اش (ساعت به‌صورتِ متن ذخیره شده)
                $sql = "$online AND s.id = ?";
                $params = [(int) $ref];
            } else {
                $sql = "$inperson AND ii.interview_date = ? AND COALESCE(ii.supervisor_user_id, 0) = ? AND COALESCE(TIME_FORMAT(ii.interview_time, '%H:%i'), '09:00') = ?";
                $params = [$d, (int) $host, $t];
            }
            break;
        case 'recall':
        case 'closed':
        case 'joined':
            $cond = ['recall' => "e.event = 'followup_set'", 'closed' => "e.event = 'closed' AND COALESCE(e.reason, '') <> 'archived'", 'joined' => "e.event = 'closed' AND e.reason = 'joined'"][$metric];
            $sql = "$base, e.created_at at, COALESCE(e.reason, e.note, '') detail FROM reception_pipeline_events e JOIN reception_applicants ra ON ra.id = e.applicant_id
                LEFT JOIN users ag ON ag.id = e.agent_user_id WHERE $cond AND e.created_at BETWEEN ? AND ?" . $ag('e.agent_user_id');
            $params = [$fromDt, $toDt];
            break;
        case 'referred':
        case 'ref_sup':
            $sql = "$base, ra.referred_at at, CONCAT('سرپرست: ', COALESCE(su.full_name, '')) detail FROM reception_applicants ra LEFT JOIN users ag ON ag.id = ra.assigned_agent_id
                LEFT JOIN users su ON su.id = ra.supervisor_user_id WHERE ra.referred_at BETWEEN ? AND ?" . $ag('ra.assigned_agent_id')
                . ($metric === 'ref_sup' ? ' AND ra.supervisor_user_id = ' . (int) ($opts['sup'] ?? 0) : '');
            $params = [$fromDt, $toDt];
            break;
    }
    if ($sql === null) return ['rows' => [], 'title' => ''];
    try {
        $st = $pdo->prepare("SELECT * FROM ($sql) z ORDER BY at DESC LIMIT 2000");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('rm_people ' . $metric . ': ' . $e->getMessage());
        $rows = [];
    }
    // این عددها «افرادِ یکتا به ازای هر کارشناس» هستند ← لیست هم همان‌طور (هر نفر یک بار برای هر کارشناس)
    if (in_array($metric, ['invited', 'inv_online', 'inv_inperson', 'recall', 'closed', 'joined'], true)) {
        $seen = [];
        $rows = array_values(array_filter($rows, static function ($r) use (&$seen) {
            $k = $r['id'] . '|' . ($r['agent_name'] ?? '');
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        }));
    }
    return ['rows' => $rows, 'title' => (string) ($titles[$metric]['label'] ?? $metric)];
}
