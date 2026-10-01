<?php
/**
 * گزارشِ فروشِ تیم‌ها
 *  - تیم = سرپرستِ تیم (جایگاهِ D) + نیروهای A / B / C که عضوِ همان تیم‌اند (users.team_id)
 *  - سرپرست به تیمی که سرپرستش است تعلق دارد (teams.leader_user_id) حتی اگر team_id خودش چیزِ دیگری باشد
 *  - فروشی که کارشناسش عضوِ هیچ تیمی نیست زیرِ «بدونِ تیم» می‌آید
 */

/**
 * بازه‌ی تاریخ بر اساسِ تقویمِ شمسی («این ماه» = از اولِ ماهِ شمسی، نه اولِ ماهِ میلادی).
 * @return array{0:string,1:string}|null  [از، تا] میلادی؛ null = همه‌ی تاریخ‌ها
 */
function tsr_date_range(string $preset, string $fromJ = '', string $toJ = ''): ?array
{
    $today = date('Y-m-d');
    [$jy, $jm] = array_map('intval', explode('/', normalize_digits(to_jalali($today))));
    $monthStart = static fn(int $y, int $m): string => (string) to_gregorian(sprintf('%04d/%02d/01', $y, $m));
    switch ($preset) {
        case 'today':
            return [$today, $today];
        case 'this_week': // هفته‌ی ایرانی از شنبه شروع می‌شود (N: دوشنبه=۱ … شنبه=۶، یکشنبه=۷)
            return [date('Y-m-d', strtotime('-' . (((int) date('N') + 1) % 7) . ' days')), $today];
        case 'this_month':
            return [$monthStart($jy, $jm), $today];
        case 'last_month':
            [$py, $pm] = $jm === 1 ? [$jy - 1, 12] : [$jy, $jm - 1];
            return [$monthStart($py, $pm), date('Y-m-d', strtotime($monthStart($jy, $jm) . ' -1 day'))];
        case 'custom':
            $f = $fromJ !== '' ? to_gregorian($fromJ) : null;
            $t = $toJ !== '' ? to_gregorian($toJ) : null;
            $f = $f ?? $monthStart($jy, $jm);
            $t = $t ?? $today;
            return [min($f, $t), max($f, $t)];
    }
    return null;
}

/** همه‌ی تیم‌ها: [team_id => [id, name, label, leader_id, leader_name]] */
function tsr_teams(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($pdo->query('SELECT t.id, t.name, t.leader_user_id, u.full_name AS leader_name FROM teams t LEFT JOIN users u ON u.id = t.leader_user_id ORDER BY t.id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
            $cache[(int) $t['id']] = [
                'id' => (int) $t['id'], 'name' => (string) ($t['name'] ?? ''), 'label' => team_display_name($t['name'] ?? null, (int) $t['id']),
                'leader_id' => (int) ($t['leader_user_id'] ?? 0), 'leader_name' => (string) ($t['leader_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('tsr_teams: ' . $e->getMessage());
    }
    return $cache;
}

/** کاربر ← تیم (سرپرست ← تیمی که سرپرستش است؛ بقیه ← users.team_id) */
function tsr_user_team_map(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($pdo->query('SELECT id, team_id FROM users WHERE team_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $cache[(int) $u['id']] = (int) $u['team_id'];
    } catch (Throwable $e) {}
    foreach (tsr_teams($pdo) as $t) if ($t['leader_id']) $cache[$t['leader_id']] = $t['id'];
    return $cache;
}

/** شناسه‌ی کاربرانِ یک تیم (سرپرست + اعضا) */
function tsr_team_user_ids(PDO $pdo, int $teamId): array
{
    $ids = [];
    foreach (tsr_user_team_map($pdo) as $uid => $tid) if ($tid === $teamId) $ids[] = $uid;
    return $ids;
}

/** جایگاهِ هر نفر در تیم: سرپرست = D، بقیه بر اساسِ نقش (A/B/C) */
function tsr_slot(PDO $pdo, int $uid, string $role): string
{
    foreach (tsr_teams($pdo) as $t) if ($t['leader_id'] === $uid) return 'D';
    if ($role === 'leader') return 'D';
    return in_array($role, ['A', 'B', 'C'], true) ? $role : 'سایر';
}

/**
 * جمعِ فروشِ هر تیم از روی فروشِ هر نفر.
 * $userRows: [['uid','full_name','role','cnt','amt', …], …]
 * @return array<int,array> مرتب بر اساسِ مبلغ؛ کلیدِ 0 = «بدونِ تیم»
 */
function tsr_aggregate(PDO $pdo, array $userRows): array
{
    $teams = tsr_teams($pdo);
    $map = tsr_user_team_map($pdo);
    $out = [];
    foreach ($userRows as $r) {
        $uid = (int) ($r['uid'] ?? 0);
        $tid = $map[$uid] ?? 0;
        if (!isset($teams[$tid])) $tid = 0;
        if ($uid === 0) $tid = -1; // فروشی که طبقِ قوانینِ سهم به هیچ کارشناسی نرسیده ← سازمان
        if (!isset($out[$tid])) {
            $out[$tid] = [
                'team_id' => $tid, 'label' => $tid === -1 ? 'سازمان آراد برندینگ' : ($tid ? $teams[$tid]['label'] : 'بدونِ تیم'), 'leader_name' => $tid > 0 ? $teams[$tid]['leader_name'] : '',
                'slots' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'سایر' => 0], 'cnt' => 0, 'amt' => 0, 'members' => [],
            ];
        }
        $slot = tsr_slot($pdo, $uid, (string) ($r['role'] ?? ''));
        $amt = (int) ($r['amt'] ?? 0);
        $out[$tid]['slots'][$slot] += $amt;
        $out[$tid]['cnt'] += (int) ($r['cnt'] ?? 0);
        $out[$tid]['amt'] += $amt;
        $out[$tid]['members'][] = $r + ['slot' => $slot];
    }
    uasort($out, static fn($a, $b) => $b['amt'] <=> $a['amt']);
    return $out;
}

/**
 * فروشِ یک تیم در بازه (برای «گزارش سرپرست») — همان تعریفِ «گزارش فروش»:
 * پیش‌پرداخت در روزِ تأییدِ سفارش + هر قسط/پرداختِ تأییدشده در روزِ تأییدش؛ خالص = بدونِ مالیات (به نسبتِ سفارش).
 * @return array{cnt:int, net:int, gross:int, slots:array<string,int>, users:array<int,array{cnt:int,net:int}>}
 */
function tsr_team_sales_period(PDO $pdo, int $teamId, string $from, string $to): array
{
    // همان تعریفِ «گزارش فروش» (includes/sales_credit.php): پیش‌پرداخت در روزِ تأییدِ سفارش + هر قسط/پرداختِ تأییدشده
    // در روزِ تأییدش، خالص (بدونِ مالیات)؛ فروشِ مشترک به نسبتِ تفکیکِ مالی
    $res = ['cnt' => 0, 'net' => 0, 'gross' => 0, 'slots' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'سایر' => 0], 'users' => []];
    $ids = tsr_team_user_ids($pdo, $teamId);
    if (!$ids) return $res;
    if (!function_exists('sales_by_user')) require_once __DIR__ . '/sales_credit.php';
    $roles = [];
    try {
        $roles = $pdo->query('SELECT id, role FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {}
    foreach (sales_by_user($pdo, $ids, $from, $to) as $uid => $r) {
        $slot = tsr_slot($pdo, (int) $uid, (string) ($roles[$uid] ?? ''));
        $res['cnt'] += $r['cnt'];
        $res['net'] += $r['net'];
        $res['gross'] += $r['gross'];
        $res['slots'][$slot] += $r['net'];
        $res['users'][(int) $uid] = ['cnt' => $r['cnt'], 'net' => $r['net']];
    }
    return $res;
}

/**
 * قانونِ تعدادِ نیروی تیمِ سرپرست: به ازای هر ۸ نیروی «عملیات» یک نیروی «توسعه» و به ازای هر ۲ نیروی «ستادی» یک نیروی «توسعه».
 *   توسعه‌ی لازم = ⌈عملیات ÷ ۸⌉ + ⌈ستادی ÷ ۲⌉   ←   رعایت شده اگر توسعه ≥ توسعه‌ی لازم
 * نیروی «نامشخص» (گروهِ شغلیِ تعیین‌نشده) در قانون حساب نمی‌شود و جدا هشدار داده می‌شود.
 * ops_max / staff_max: حداکثرِ مجاز با توسعه‌ی فعلی، اگر گروهِ دیگر ثابت بماند.
 * @param array{توسعه:int,عملیات:int,ستادی:int,نامشخص?:int} $grp
 */
function tsr_staff_rule(array $grp): array
{
    $dev = (int) ($grp['توسعه'] ?? 0);
    $ops = (int) ($grp['عملیات'] ?? 0);
    $stf = (int) ($grp['ستادی'] ?? 0);
    $needOps = (int) ceil($ops / 8);
    $needStf = (int) ceil($stf / 2);
    $needDev = $needOps + $needStf;
    $ok = $dev >= $needDev;
    $opsMax = 8 * max(0, $dev - $needStf);
    $stfMax = 2 * max(0, $dev - $needOps);
    $fa = static fn(int $n): string => to_persian_digits((string) $n);
    return [
        'ok' => $ok, 'ops_max' => $opsMax, 'staff_max' => $stfMax, 'need_dev' => $needDev, 'dev_short' => max(0, $needDev - $dev),
        'unknown' => (int) ($grp['نامشخص'] ?? 0),
        'text' => $ok ? 'رعایت شده'
            : 'رعایت نشده: توسعه‌ی لازم ' . $fa($needDev) . ' نفر (عملیات ' . $fa($ops) . ' ← ' . $fa($needOps) . ' + ستادی ' . $fa($stf) . ' ← ' . $fa($needStf) . ')'
              . ' — توسعه‌ی فعلی ' . $fa($dev) . ' (کمبود ' . $fa(max(0, $needDev - $dev)) . ')',
    ];
}
