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
        if (!isset($out[$tid])) {
            $out[$tid] = [
                'team_id' => $tid, 'label' => $tid ? $teams[$tid]['label'] : 'بدونِ تیم', 'leader_name' => $tid ? $teams[$tid]['leader_name'] : '',
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
 * فروشِ یک تیم در بازه (برای «گزارش سرپرست») — همان معیارِ ستونِ «فروشِ خالص» نیروها در آن صفحه:
 * سفارش‌های تأییدشده با تاریخِ تأییدِ مالی در بازه؛ خالص = مبلغِ فاکتور منهای مالیات.
 * @return array{cnt:int, net:int, gross:int, slots:array<string,int>, users:array<int,array{cnt:int,net:int}>}
 */
function tsr_team_sales_period(PDO $pdo, int $teamId, string $from, string $to): array
{
    $res = ['cnt' => 0, 'net' => 0, 'gross' => 0, 'slots' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'سایر' => 0], 'users' => []];
    $ids = tsr_team_user_ids($pdo, $teamId);
    if (!$ids) return $res;
    $in = implode(',', array_map('intval', $ids));
    try {
        $st = $pdo->prepare("SELECT o.seller_user_id uid, u.role, COUNT(*) cnt, COALESCE(SUM(o.total_amount - o.tax_amount),0) net,
                COALESCE(SUM(COALESCE(o.confirmed_amount, o.total_amount)),0) gross
            FROM sales_orders o LEFT JOIN users u ON u.id = o.seller_user_id
            WHERE o.seller_user_id IN ($in) AND o.status = 'approved' AND DATE(o.decided_at) BETWEEN ? AND ? GROUP BY o.seller_user_id, u.role");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $slot = tsr_slot($pdo, (int) $r['uid'], (string) $r['role']);
            $res['cnt'] += (int) $r['cnt'];
            $res['net'] += (int) $r['net'];
            $res['gross'] += (int) $r['gross'];
            $res['slots'][$slot] += (int) $r['net'];
            $res['users'][(int) $r['uid']] = ['cnt' => (int) $r['cnt'], 'net' => (int) $r['net']];
        }
    } catch (Throwable $e) {
        error_log('tsr_team_sales_period: ' . $e->getMessage());
    }
    return $res;
}
