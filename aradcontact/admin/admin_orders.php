<?php
/**
 * واحد مالی — دریافت و بررسیِ سفارش‌ها (تأیید / رد / در انتظار) + گزارشِ فروش.
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_admin();
$pdo = db();
require_once __DIR__ . '/../includes/services_functions.php';
require_once __DIR__ . '/../includes/orders_functions.php';
require_once __DIR__ . '/../includes/payment_duplicates.php';
require_once __DIR__ . '/../includes/team_sales.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
require_once __DIR__ . '/../includes/consent_functions.php';

$ready = services_module_ready($pdo) && orders_ready($pdo);
$statuses = orders_statuses();
$methods = orders_payment_methods();
$view = in_array($_GET['view'] ?? '', ['report', 'receivables', 'payments', 'consents'], true) ? $_GET['view'] : 'list';
$status = (string) ($_GET['status'] ?? ($view === 'list' ? 'pending' : ''));
if ($status !== 'all' && !isset($statuses[$status])) {
    $status = $view === 'list' ? 'pending' : 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));
$sellerId = (int) ($_GET['seller_id'] ?? 0);
$method = isset($methods[$_GET['method'] ?? '']) ? (string) $_GET['method'] : '';
$preset = (string) ($_GET['preset'] ?? ($view === 'report' ? 'this_month' : 'all'));
// بازه‌ها بر اساسِ تقویمِ شمسی («این ماه» = از اولِ ماهِ شمسی تا امروز؛ قبلاً اولِ ماهِ میلادی بود)
$__range = in_array($preset, ['today', 'this_week', 'this_month', 'last_month', 'custom'], true)
    ? tsr_date_range($preset, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')) : null;
if ($__range) { [$from, $to] = $__range; } else { $preset = 'all'; $from = ''; $to = ''; }
// فیلترِ تیم: '' = همه، 'none' = بدونِ تیم، عدد = همان تیم (سرپرست + اعضا)
$teamF = (string) ($_GET['team'] ?? '');
$teamsAll = $ready ? tsr_teams($pdo) : [];
if ($teamF !== '' && $teamF !== 'none' && !isset($teamsAll[(int) $teamF])) $teamF = '';

$rows = [];
$counts = array_fill_keys(array_keys($statuses), 0);
$sums = array_fill_keys(array_keys($statuses), 0);
$sellers = [];
$report = ['by_seller' => [], 'by_service' => [], 'by_day' => [], 'by_team' => [], 'detail' => null];

if ($ready) {
    $where = ['1=1'];
    $params = [];
    if ($from !== '') { $where[] = 'o.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to !== '')   { $where[] = 'o.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $__whereNS = $where; $__paramsNS = $params; // بدونِ فیلترِ کارشناس (برای فروشِ مشترک در گزارش)
    if ($sellerId > 0) { $where[] = 'o.seller_user_id = ?'; $params[] = $sellerId; }
    if ($method !== '') { $where[] = 'o.payment_method = ?'; $params[] = $method; }
    if ($method !== '') { $__whereNS[] = 'o.payment_method = ?'; $__paramsNS[] = $method; }
    // تیم: سفارش‌هایی که کارشناسشان عضوِ همان تیم است (در فروشِ مشترک: سهمِ اعضای همان تیم)
    $__teamUidSql = '';
    if ($teamF !== '') {
        $__allTeamUids = array_keys(array_filter(tsr_user_team_map($pdo), static fn($t) => isset($teamsAll[$t])));
        if ($teamF === 'none') {
            $__teamUidSql = $__allTeamUids ? ' NOT IN (' . implode(',', array_map('intval', $__allTeamUids)) . ')' : ' IS NOT NULL';
        } else {
            $__tu = tsr_team_user_ids($pdo, (int) $teamF);
            $__teamUidSql = ' IN (' . ($__tu ? implode(',', array_map('intval', $__tu)) : '0') . ')';
        }
        $where[] = 'o.seller_user_id' . $__teamUidSql;
    }
    if ($q !== '') {
        $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ? OR o.payment_ref LIKE ?)';
        $like = '%' . normalize_digits($q) . '%';
        array_push($params, '%' . $q . '%', $like, $like, $like);
        $__whereNS[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ? OR o.payment_ref LIKE ?)';
        array_push($__paramsNS, '%' . $q . '%', $like, $like, $like);
    }
    $base = 'FROM sales_orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users s ON s.id = o.seller_user_id WHERE ' . implode(' AND ', $where);
    // «فروش» (یک تعریف در همه‌ی گزارش‌ها — includes/sales_credit.php): رویدادهای تأییدشده (تأییدِ سفارش + هر قسط/پرداختِ تأییدشده)
    // در «روزِ واریز» (تاریخِ فیش)، خالص (بدونِ مالیات). بازه‌ی تاریخ روی همین روزِ واریز اعمال می‌شود؛ بقیه‌ی فیلترها روی سفارش.
    require_once __DIR__ . '/../includes/sales_credit.php';
    $__evW = ['1=1']; $__evP = [];
    if ($method !== '') { $__evW[] = 'o.payment_method = ?'; $__evP[] = $method; }
    if ($q !== '') { $__evW[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR o.order_number LIKE ? OR o.payment_ref LIKE ?)'; array_push($__evP, '%' . $q . '%', $like, $like, $like); }
    $__evSql = sales_user_events_sql($pdo, implode(' AND ', $__evW));
    $__evParams = sales_user_events_params($pdo, $from, $to, $__evP);
    $__evUidSql = ($sellerId > 0 ? ' AND x.uid = ' . (int) $sellerId : '') . ($__teamUidSql !== '' ? ' AND x.uid' . $__teamUidSql : '');

    $__extraPaid = sales_payments_ready($pdo) ? "COALESCE((SELECT SUM(p.amount) FROM sales_order_payments p WHERE p.order_id = o.id AND p.status = 'confirmed' AND p.kind = 'extra'"
        . (sales_has_legacy_col($pdo) ? ' AND COALESCE(o.is_legacy, 0) = 0' : '') . "), 0)" : '0';
    $st = $pdo->prepare("SELECT o.status, COUNT(*) cnt, COALESCE(SUM(CASE WHEN o.status='approved' THEN " . sales_net_sql("COALESCE(o.confirmed_amount,o.total_amount) + $__extraPaid") . " ELSE " . sales_net_sql('o.total_amount') . " END),0) amt $base GROUP BY o.status");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int) $r['cnt'];
            $sums[$r['status']] = (int) $r['amt'];
        }
    }

    $sellers = $pdo->query('SELECT DISTINCT u.id, u.full_name, u.mobile FROM sales_orders o JOIN users u ON u.id = o.seller_user_id ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $listSql = "SELECT o.*, c.full_name AS customer_name, c.mobile AS customer_mobile, s.full_name AS seller_name, s.role AS seller_role,
                (SELECT COUNT(*) FROM sales_order_files f WHERE f.order_id = o.id) AS files_cnt,
                (SELECT GROUP_CONCAT(i.title SEPARATOR '، ') FROM sales_order_items i WHERE i.order_id = o.id) AS items_txt
                $base";
    $listParams = $params;
    if ($status !== 'all') {
        $listSql .= ' AND o.status = ?';
        $listParams[] = $status;
    }

    if ($view === 'list') {
        $st = $pdo->prepare($listSql . ' ORDER BY ' . ($status === 'pending' ? 'o.submitted_at ASC' : 'o.id DESC') . ' LIMIT 300');
        $st->execute($listParams);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($view !== 'list' || isset($_GET['export'])) {
        $ap = array_merge($params, []);
        // فروش به تفکیکِ کارشناس — «فروشِ مشترک»: اگر مالی عددِ فروشِ سفارشی را بینِ چند نفر تفکیک کرده، هر نفر سهمِ خودش را می‌گیرد
        // (فقط نمایشی؛ سهم عملکرد جداست). اگر عددِ سفارش بعداً عوض شده باشد، تفکیک به همان نسبت اعمال می‌شود.
        $__splitReady = scr_ready($pdo);
        // هر رویدادِ فروش ← سهمِ هر نفر (بدونِ تفکیک: کلِ مبلغ برای ثبت‌کننده؛ با تفکیک: به نسبتِ تفکیکِ مالی)
        $__xInner = "SELECT x.order_id, x.uid, SUM(x.net) amt, MAX(x.shared) shared, SUM(x.kind = 'payment') pay_events FROM ($__evSql) x WHERE 1=1 $__evUidSql GROUP BY x.order_id, x.uid";
        $st = $pdo->prepare("SELECT y.uid, u.full_name, u.role, COUNT(*) cnt, SUM(y.amt) amt, SUM(y.shared) shared_cnt FROM ($__xInner) y
            LEFT JOIN users u ON u.id = y.uid GROUP BY y.uid, u.full_name, u.role ORDER BY amt DESC");
        $st->execute($__evParams);
        $report['by_seller'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // uid = 0: فروشی که طبقِ قوانینِ سهمِ عملکرد به هیچ کارشناسی نرسیده (ثبت‌کننده در آن سفارش جایگاهی ندارد) ← سازمان
        foreach ($report['by_seller'] as &$__bs) if ((int) $__bs['uid'] === 0) { $__bs['full_name'] = 'سازمان آراد برندینگ'; $__bs['role'] = ''; }
        unset($__bs);
        $report['by_team'] = tsr_aggregate($pdo, $report['by_seller']);

        // ─── ریزِ هر عدد: کلیک روی مبلغ/تعدادِ هر کارشناس (du) یا تیم (dt) ← همان سفارش‌هایی که این عدد را ساخته‌اند ───
        $detailUid = (int) ($_GET['du'] ?? 0); // -1 = سازمان آراد برندینگ
        $detailTeam = (string) ($_GET['dt'] ?? '');
        if ($detailUid > 0 || $detailUid === -1 || $detailTeam !== '') {
            $dUids = [];
            if ($detailUid === -1) {
                $dUids = [0];
            } elseif ($detailUid > 0) {
                $dUids = [$detailUid];
            } else {
                foreach ($report['by_team'][$detailTeam === 'none' ? 0 : (int) $detailTeam]['members'] ?? [] as $__m) $dUids[] = (int) $__m['uid'];
            }
            $dRows = [];
            if ($dUids) {
                $__in = implode(',', array_map('intval', $dUids));
                $st = $pdo->prepare("SELECT y.order_id, y.uid, y.amt, y.shared, y.pay_events FROM ($__xInner) y WHERE y.uid IN ($__in)");
                $st->execute($__evParams);
                $dRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $dOrders = $dSplits = $dNames = [];
            if ($dRows) {
                $__oin = implode(',', array_unique(array_map(static fn($x) => (int) $x['order_id'], $dRows)));
                foreach ($pdo->query("SELECT o.id, o.order_number, o.created_at, o.decided_at, o.seller_user_id, GREATEST(CAST(o.total_amount AS SIGNED) - CAST(o.tax_amount AS SIGNED), 0) order_amt,
                        c.full_name customer_name, c.mobile customer_mobile, s.full_name seller_name,
                        (SELECT GROUP_CONCAT(i.title ORDER BY i.id SEPARATOR '، ') FROM sales_order_items i WHERE i.order_id = o.id) items_txt
                        FROM sales_orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users s ON s.id = o.seller_user_id WHERE o.id IN ($__oin)") as $__o) {
                    $dOrders[(int) $__o['id']] = $__o;
                }
                if ($__splitReady) {
                    // واریزیِ اکسل با سهم عملکرد: سهم‌گیری که در سهم عملکرد جایگاه ندارد ← سهمش به سازمان رفته (همان منطقِ sales_user_events_sql)
                    $__imp = sales_has_import_col($pdo) && sales_snapshots_ready($pdo);
                    foreach ($pdo->query("SELECT sp.order_id, sp.user_id, u.full_name, sp.amount" . ($__imp ? ", o.import_ref, o.import_perf, snap.owners_json" : '') . "
                        FROM sales_order_credit_splits sp LEFT JOIN users u ON u.id = sp.user_id" . ($__imp ? ' JOIN sales_orders o ON o.id = sp.order_id LEFT JOIN ps_order_snapshots snap ON snap.order_id = sp.order_id' : '') . "
                        WHERE sp.order_id IN ($__oin) ORDER BY sp.id") as $__x) {
                        $__org = $__imp && (string) $__x['import_ref'] !== '' && (int) $__x['import_perf'] === 1 && $__x['owners_json'] !== null
                            && strpos((string) $__x['owners_json'], '"user_id":' . (int) $__x['user_id'] . ',') === false;
                        $dSplits[(int) $__x['order_id']][] = [(string) $__x['full_name'] . ($__org ? ' (در سهم عملکرد جایگاه ندارد ← سازمان)' : ''), (int) $__x['amount']];
                    }
                }
                foreach ($pdo->query('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', $dUids)) . ')') as $__u) $dNames[(int) $__u['id']] = $__u['full_name'];
                usort($dRows, static fn($a, $b) => strcmp((string) ($dOrders[(int) $b['order_id']]['created_at'] ?? ''), (string) ($dOrders[(int) $a['order_id']]['created_at'] ?? '')));
            }
            $report['detail'] = [
                'title' => $detailUid === -1 ? 'سازمان آراد برندینگ' : ($detailUid > 0 ? ($dNames[$detailUid] ?? ('کارشناس #' . $detailUid))
                    : ($detailTeam === 'none' ? 'بدونِ تیم' : ($detailTeam === '-1' ? 'سازمان آراد برندینگ' : ($teamsAll[(int) $detailTeam]['label'] ?? ('تیم ' . $detailTeam))))),
                'rows' => $dRows, 'orders' => $dOrders, 'splits' => $dSplits, 'names' => $dNames + [0 => 'سازمان آراد برندینگ'], 'is_team' => $detailUid === 0,
            ];
        }
        $st = $pdo->prepare("SELECT i.title, SUM(i.quantity) qty, SUM(i.amount) amt, COUNT(DISTINCT o.id) orders_cnt FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users s ON s.id = o.seller_user_id WHERE " . implode(' AND ', $where) . " AND o.status = 'approved' GROUP BY i.title ORDER BY amt DESC LIMIT 20");
        $st->execute($ap);
        $report['by_service'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $st = $pdo->prepare("SELECT DATE(x.at) d, SUM(x.net) amt FROM ($__evSql) x WHERE 1=1 $__evUidSql GROUP BY DATE(x.at) ORDER BY d");
        $st->execute($__evParams);
        $report['by_day'] = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    if (isset($_GET['export'])) {
        // خروجیِ اکسل (xlsx) — دقیقاً با همان فیلترها و جستجوی صفحه:
        //   ۱) «سفارش‌ها»: هر خدمتِ فروخته‌شده یک سطر + تیم و سرپرستِ کارشناس
        //   ۲) «تیم‌ها»: فروشِ تأییدشده‌ی هر تیم به تفکیکِ A / B / C / D (سرپرست)
        //   ۳) «کارشناسان»: فروشِ تأییدشده‌ی هر نفر (با فروشِ مشترک) و تیمش   ۴) «فیلترها»
        $st = $pdo->prepare($listSql . ' ORDER BY o.id DESC LIMIT 20000');
        $st->execute($listParams);
        $orderRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $itemsBy = [];
        $hasDept = function_exists('services_ticket_ready') && services_ticket_ready($pdo);
        foreach (array_chunk(array_map('intval', array_column($orderRows, 'id')), 1000) as $chunk) {
            if (!$chunk) continue;
            $in = implode(',', $chunk);
            $isql = 'SELECT i.order_id, i.title, i.unit, i.quantity, i.unit_price, i.amount, sv.category'
                . ($hasDept ? ', sv.ticket_department' : ", NULL AS ticket_department")
                . " FROM sales_order_items i LEFT JOIN services sv ON sv.id = i.service_id WHERE i.order_id IN ($in) ORDER BY i.order_id, i.id";
            foreach ($pdo->query($isql) as $it) {
                $itemsBy[(int) $it['order_id']][] = $it;
            }
        }
        // تاریخِ شمسی بدونِ «/» — مثلاً 14050707 (عدد، تا اکسل مرتب/فیلتر کند)
        $jcompact = static function (?string $g) {
            if (empty($g) || str_starts_with((string) $g, '0000')) return '';
            [$gy, $gm, $gd] = array_map('intval', explode('-', substr((string) $g, 0, 10)));
            [$jy, $jm, $jd] = gregorian_to_jalali_arr($gy, $gm, $gd);
            return (int) sprintf('%04d%02d%02d', $jy, $jm, $jd);
        };
        $__splitTxt = [];
        try {
            require_once __DIR__ . '/../includes/sales_credit.php';
            if (scr_ready($pdo) && $orderRows) {
                $__ids = implode(',', array_map(static fn($x) => (int) $x['id'], $orderRows));
                foreach ($pdo->query("SELECT sp.order_id, u.full_name, sp.amount FROM sales_order_credit_splits sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.order_id IN ($__ids) ORDER BY sp.id") as $__x) {
                    $__splitTxt[(int) $__x['order_id']][] = $__x['full_name'] . ': ' . $__x['amount'];
                }
            }
        } catch (Throwable $e) {}
        $__map = tsr_user_team_map($pdo);
        $__teamOf = static function (int $uid) use ($__map, $teamsAll): array {
            $t = $teamsAll[$__map[$uid] ?? 0] ?? null;
            return $t ? [$t['label'], $t['leader_name']] : ['بدونِ تیم', ''];
        };
        $oRows = [];
        foreach ($orderRows as $r) {
            [$tl, $tld] = $__teamOf((int) $r['seller_user_id']);
            $items = $itemsBy[(int) $r['id']] ?? [['title' => '', 'unit' => '', 'quantity' => '', 'unit_price' => '', 'amount' => '', 'category' => '', 'ticket_department' => '']];
            foreach ($items as $n => $it) {
                $first = $n === 0; // مبالغِ کلِ فاکتور فقط در سطرِ اول (تا جمعِ ستون در اکسل دوبار حساب نشود)
                $oRows[] = [
                    (string) $r['order_number'], $jcompact($r['created_at']), $jcompact($r['decided_at'] ?? null), (string) $r['customer_name'], (string) $r['customer_mobile'],
                    (string) $r['seller_name'], role_label((string) $r['seller_role']), $tl, $tld,
                    (string) $it['title'], (string) ($it['category'] ?? ''), (string) ($it['ticket_department'] ?? ''),
                    $it['quantity'] === '' ? '' : (float) $it['quantity'], (string) ($it['unit'] ?? ''),
                    $it['unit_price'] === '' ? '' : (int) $it['unit_price'], $it['amount'] === '' ? '' : (int) $it['amount'],
                    $first ? (int) $r['total_amount'] : '', $first ? (int) $r['paid_amount'] : '', $first && $r['confirmed_amount'] !== null ? (int) $r['confirmed_amount'] : '',
                    $methods[$r['payment_method']] ?? (string) $r['payment_method'], (string) $r['payment_ref'],
                    $statuses[$r['status']]['label'] ?? (string) $r['status'], $first ? (string) $r['finance_note'] : '',
                    $first ? implode(' | ', $__splitTxt[(int) $r['id']] ?? []) : '',
                ];
            }
        }
        $grand = array_sum(array_map(static fn($t) => $t['amt'], $report['by_team']));
        $tRows = [];
        $tTot = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'سایر' => 0, 'cnt' => 0, 'amt' => 0];
        foreach ($report['by_team'] as $t) {
            $tRows[] = [$t['label'], $t['leader_name'], $t['slots']['A'], $t['slots']['B'], $t['slots']['C'], $t['slots']['D'], $t['slots']['سایر'], $t['cnt'], $t['amt'],
                $grand > 0 ? round($t['amt'] / $grand * 100, 1) : 0];
            foreach (['A', 'B', 'C', 'D', 'سایر'] as $__sl) $tTot[$__sl] += $t['slots'][$__sl];
            $tTot['cnt'] += $t['cnt'];
            $tTot['amt'] += $t['amt'];
        }
        $sRows = [];
        foreach ($report['by_team'] as $t) {
            foreach ($t['members'] as $m) {
                $sRows[] = [$t['label'], (string) $m['full_name'], role_label((string) $m['role']), $m['slot'], (int) $m['cnt'], (int) $m['amt'], (int) ($m['shared_cnt'] ?? 0)];
            }
        }
        $fRows = [
            ['بازه', $from !== '' ? to_jalali($from) . ' تا ' . to_jalali($to) : 'همه‌ی تاریخ‌ها'],
            ['مبنای تاریخ', 'تاریخِ ثبتِ سفارش'],
            ['وضعیت (برگه‌ی سفارش‌ها)', $status === 'all' ? 'همه' : ($statuses[$status]['label'] ?? $status)],
            ['تیم', $teamF === '' ? 'همه' : ($teamF === 'none' ? 'بدونِ تیم' : ($teamsAll[(int) $teamF]['label'] ?? $teamF))],
            ['کارشناس', $sellerId > 0 ? (string) ($pdo->query('SELECT full_name FROM users WHERE id = ' . (int) $sellerId)->fetchColumn() ?: $sellerId) : 'همه'],
            ['روش پرداخت', $method !== '' ? ($methods[$method] ?? $method) : 'همه'],
            ['جستجو', $q !== '' ? $q : '—'],
            ['برگه‌های «تیم‌ها» و «کارشناسان»', 'فقط سفارش‌های تأییدشده (مبلغِ تأییدشده؛ فروشِ مشترک به نسبتِ تفکیکِ مالی)'],
            ['تاریخِ تهیه', to_jalali(date('Y-m-d')) . ' ' . date('H:i')],
        ];
        $fn = 'sales_' . ($from !== '' ? str_replace('/', '', normalize_digits(to_jalali($from))) . '_' . str_replace('/', '', normalize_digits(to_jalali($to))) : 'all')
            . ($teamF !== '' ? '_team_' . preg_replace('/\W/', '', $teamF) : '');
        xlsx_output($fn, [
            ['name' => 'سفارش‌ها', 'header' => ['شماره فاکتور', 'تاریخ ثبت', 'تاریخ تأیید مالی', 'مشتری', 'موبایل', 'کارشناس', 'نقش', 'تیم', 'سرپرستِ تیم',
                'خدمت', 'دسته خدمت', 'دپارتمان', 'تعداد', 'واحد', 'قیمت واحد', 'مبلغ این خدمت', 'مبلغ کل فاکتور', 'پرداختی', 'تأییدشده',
                'روش پرداخت', 'شماره پیگیری', 'وضعیت', 'توضیح مالی', 'فروش مشترک (تفکیک)'],
             'rows' => $oRows, 'widths' => [16, 11, 13, 24, 14, 22, 10, 18, 20, 30, 16, 14, 8, 10, 14, 16, 16, 14, 14, 14, 18, 14, 30, 30], 'text_cols' => [0, 4, 20]],
            ['name' => 'تیم‌ها', 'header' => ['تیم', 'سرپرست', 'A', 'B', 'C', 'D (سرپرست)', 'سایر', 'تعداد سفارش', 'جمعِ فروشِ تیم', 'درصد از کل'],
             'rows' => $tRows, 'footer' => $tRows ? [['جمع', '', $tTot['A'], $tTot['B'], $tTot['C'], $tTot['D'], $tTot['سایر'], $tTot['cnt'], $tTot['amt'], 100]] : [],
             'widths' => [22, 22, 16, 16, 16, 16, 12, 12, 18, 10]],
            ['name' => 'کارشناسان', 'header' => ['تیم', 'کارشناس', 'نقش', 'جایگاه', 'تعداد سفارش', 'مبلغ فروش', 'سفارشِ مشترک'],
             'rows' => $sRows, 'footer' => $sRows ? [['جمع', '', '', '', $tTot['cnt'], $tTot['amt'], '']] : [], 'widths' => [22, 26, 12, 8, 12, 18, 12]],
            ['name' => 'فیلترها', 'header' => ['فیلتر', 'مقدار'], 'rows' => $fRows, 'widths' => [32, 70]],
        ]);
        exit;
    }
}

$canDecide = user_can('finance_orders_decide', $user);

/** انتخابِ کارشناس با جستجو (نام + موبایل) — به‌جای لیستِ کشوییِ طولانی */
$sellerPicker = static function (array $sellers, int $sellerId, string $uid): string {
    $selLabel = '';
    $opts = '';
    foreach ($sellers as $sl) {
        $lbl = person_pick_label((string) $sl['full_name'], $sl['mobile'] ?? null);
        if ((int) $sl['id'] === $sellerId) $selLabel = $lbl;
        $opts .= '<option data-id="' . (int) $sl['id'] . '" value="' . e($lbl) . '"></option>';
    }
    return '<input type="text" class="form-control form-control-sm seller-pick" list="sellerList_' . $uid . '" data-target="sellerId_' . $uid . '"'
        . ' placeholder="نام یا موبایلِ کارشناس — خالی = همه" autocomplete="off" value="' . e($selLabel) . '">'
        . '<datalist id="sellerList_' . $uid . '">' . $opts . '</datalist>'
        . '<input type="hidden" name="seller_id" id="sellerId_' . $uid . '" value="' . ($sellerId > 0 ? $sellerId : 0) . '">';
};

// ─── مطالبات، اقساط و پرداخت‌های در انتظارِ تأیید ───
$recv = [];
$recvSum = ['balance' => 0, 'overdue' => 0, 'due_week' => 0, 'unscheduled' => 0, 'orders' => 0, 'customers' => 0];
$pendingPayments = [];
$pendingConsents = [];
if ($ready) {
    $recv = fin_receivables($pdo, $sellerId > 0 ? ['only_seller_ids' => [$sellerId]] : []);
    $recvSum = fin_receivables_summary($recv);
    $pendingPayments = fin_pending_payments($pdo);
    $pendingConsents = consent_pending_list($pdo);
    if ($view === 'receivables' && isset($_GET['export_debtors'])) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="debtors_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['مشتری', 'موبایل', 'تعداد فاکتور', 'جمع فاکتور', 'پرداخت‌شده', 'مانده', 'معوق', 'قسط بعدی', 'مبلغ قسط بعدی', 'کارشناس']);
        foreach (fin_group_by_customer($recv) as $d) {
            fputcsv($out, [$d['customer_name'], $d['customer_mobile'], $d['orders'], $d['total'], $d['paid'], $d['balance'], $d['overdue'],
                $d['next_due'] ? to_jalali($d['next_due']['date']) : '', $d['next_due']['amount'] ?? '', implode('، ', array_keys($d['sellers']))]);
        }
        fclose($out);
        exit;
    }
}
$pageTitle = 'سفارشات و بررسی مالی';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.fo{--l:#e7e2d3}
.fo .hero{background:linear-gradient(135deg,#052e1c 0%,#14532d 55%,#22c55e 140%);border-radius:18px;padding:18px 22px;color:#ecfdf5;margin-bottom:16px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}
.fo .hero h5{margin:0;font-weight:800;color:#ecfdf5}.fo .hero p{margin:.3rem 0 0;font-size:.8rem;color:#d1fae5}
.fo .kpi{border:1px solid var(--l);border-radius:14px;background:#fff;padding:12px;display:block;text-decoration:none;color:inherit;height:100%}
.fo .kpi.active{outline:2px solid #22c55e}
.fo .kpi .n{font-weight:800;font-size:1.35rem}
.fo .kpi .a{font-size:.75rem;color:#57534e}
.fo .card{border:1px solid var(--l);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.fo .chip{border:1px solid var(--l);border-radius:20px;padding:.25rem .8rem;font-size:.78rem;color:#1c1917;background:#fff;text-decoration:none;display:inline-block}
.fo .chip.active{background:linear-gradient(135deg,#bbf7d0,#22c55e);border-color:transparent;font-weight:700}
.fo table td,.fo table th{font-size:.8rem;vertical-align:middle}
.fo .nav-pills .nav-link{border-radius:999px;font-size:.85rem}
.fo .nav-pills .nav-link.active{background:#16a34a}
</style>

<div class="fo">
  <div class="hero">
    <div>
      <h5><i class="fa-solid fa-file-invoice-dollar"></i> سفارشات و بررسی مالی</h5>
      <p>سفارش‌هایی که کارشناسان از پرونده‌ی مشتری ثبت کرده‌اند؛ فیش را بررسی و سفارش را تأیید، رد یا در انتظار قرار دهید.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-arrow-right"></i> پنل مدیریت</a>
    </div>
  </div>

  <?php if (!$ready): ?>
    <?= services_module_not_ready_html() ?>
  <?php else: ?>

  <ul class="nav nav-pills gap-2 mb-3">
    <li class="nav-item"><a class="nav-link <?= $view === 'list' ? 'active' : '' ?>" href="admin_orders.php"><i class="fa-solid fa-inbox"></i> صفِ بررسی و سفارش‌ها</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'receivables' ? 'active' : '' ?>" href="admin_orders.php?view=receivables"><i class="fa-solid fa-hand-holding-dollar"></i> مطالبات و اقساط
      <?php if ($recvSum['overdue'] + $recvSum['unscheduled'] > 0): ?><span class="badge text-bg-danger ms-1"><?= format_toman($recvSum['overdue'] + $recvSum['unscheduled']) ?> معوق</span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'payments' ? 'active' : '' ?>" href="admin_orders.php?view=payments"><i class="fa-solid fa-money-bill-transfer"></i> پرداخت‌های در انتظارِ تأیید
      <?php if ($pendingPayments): ?><span class="badge text-bg-warning ms-1"><?= to_persian_digits((string) count($pendingPayments)) ?></span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'consents' ? 'active' : '' ?>" href="admin_orders.php?view=consents"><i class="fa-solid fa-file-signature"></i> پیام‌های رضایتِ در انتظارِ بررسی
      <?php if ($pendingConsents): ?><span class="badge text-bg-danger ms-1"><?= to_persian_digits((string) count($pendingConsents)) ?></span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'report' ? 'active' : '' ?>" href="admin_orders.php?view=report"><i class="fa-solid fa-chart-column"></i> گزارش فروش</a></li>
  </ul>

  <?php if ($view === 'receivables'): ?>
    <div class="card p-3 mb-3">
      <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
        <input type="hidden" name="view" value="receivables">
        <div style="min-width:260px"><label class="form-label small mb-1">کارشناس</label><?= $sellerPicker($sellers, $sellerId, 'recv') ?></div>
        <button class="btn btn-sm btn-success">اعمال</button>
        <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(array_merge($_GET, ['view' => 'receivables', 'export_debtors' => 1]))) ?>"><i class="fa-solid fa-file-csv"></i> خروجیِ اکسلِ بدهکاران</a>
      </form>
    </div>
    <?php $recvBase = '../'; $recvShowSeller = true; require __DIR__ . '/../includes/receivables_view.php'; ?>
  <?php elseif ($view === 'consents'): ?>
    <div class="small text-muted mb-2">اسکرین‌شاتِ پیامِ رضایتِ مشتری که کارشناس (هم‌زمان با فیش یا بعد از آن) بارگذاری کرده و هنوز تأیید یا رد نشده — قدیمی‌ترها اول. با کلیک روی تصویر، اندازه‌ی کامل باز می‌شود.</div>
    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>بارگذاری</th><th>مشتری</th><th>فاکتور</th><th>مبلغِ پرداختی</th><th>کارشناس</th><th>اسکرین‌شات</th><th>تصمیم</th></tr></thead>
          <tbody>
          <?php if (!$pendingConsents): ?><tr><td colspan="7" class="text-center text-muted py-4">پیامِ رضایتی در انتظارِ بررسی نیست 🎉</td></tr><?php endif; ?>
          <?php foreach ($pendingConsents as $c): $__img = strpos((string) $c['mime'], 'image/') === 0; $__u = '../order_consent.php?order_id=' . (int) $c['order_id']; ?>
            <tr>
              <td class="small text-nowrap"><?= to_jalali(substr((string) $c['uploaded_at'], 0, 10)) ?><div class="text-muted"><?= e(to_persian_digits(substr((string) $c['uploaded_at'], 11, 5))) ?></div></td>
              <td class="fw-semibold"><?= e((string) $c['customer_name']) ?><div class="small text-muted" dir="ltr"><?= e((string) $c['customer_mobile']) ?></div></td>
              <td class="text-nowrap"><a href="../order_view.php?id=<?= (int) $c['order_id'] ?>#order-consent"><?= e(to_persian_digits((string) $c['order_number'])) ?></a>
                <div><?= orders_status_badge((string) $c['order_status']) ?></div></td>
              <td class="text-nowrap fw-bold"><?= format_toman((int) $c['paid_amount']) ?></td>
              <td class="small"><?= e((string) ($c['seller_name'] ?? '—')) ?><?php if (($c['uploader_name'] ?? '') !== '' && $c['uploader_name'] !== $c['seller_name']): ?><div class="text-muted">بارگذاری: <?= e((string) $c['uploader_name']) ?></div><?php endif; ?></td>
              <td><a href="<?= e($__u) ?>" target="_blank" class="border rounded-3 overflow-hidden d-inline-block bg-light text-center" style="width:72px;height:72px">
                <?php if ($__img): ?><img src="<?= e($__u) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover"><?php else: ?><i class="fa-solid fa-file-pdf fs-3 text-danger mt-3"></i><?php endif; ?></a></td>
              <td class="text-nowrap">
                <form method="post" action="../order_consent.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $c['order_id'] ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="back" value="consents">
                  <button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i> تأیید</button></form>
                <form method="post" action="../order_consent.php" class="d-inline" onsubmit="var r=prompt('دلیلِ رد:'); if(!r) return false; this.note.value=r; return true;"><?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $c['order_id'] ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="note"><input type="hidden" name="back" value="consents">
                  <button class="btn btn-sm btn-outline-danger">رد</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php elseif ($view === 'payments'): ?>
    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>ثبت</th><th>مشتری</th><th>فاکتور</th><th>مبلغ</th><th>تاریخ پرداخت</th><th>روش / پیگیری</th><th>ثبت‌کننده</th><th>فیش</th><th></th></tr></thead>
          <tbody>
          <?php if (!$pendingPayments): ?><tr><td colspan="9" class="text-center text-muted py-4">پرداختی در انتظارِ تأیید نیست.</td></tr><?php endif; ?>
          <?php foreach ($pendingPayments as $p): ?>
            <tr>
              <td class="small text-nowrap"><?= to_jalali($p['created_at']) ?></td>
              <td class="fw-semibold"><?= e((string) $p['customer_name']) ?></td>
              <td class="text-nowrap"><?= e(to_persian_digits((string) $p['order_number'])) ?></td>
              <td class="text-nowrap fw-bold"><?= format_toman((int) $p['amount']) ?>
                <?php
                  $__pd = pdup_ready($pdo) ? pdup_payment_candidates($pdo, ['customer_id' => (int) $p['customer_id'], 'amount' => (int) $p['amount'],
                      'ref' => (string) ($p['ref'] ?? ''), 'payment_id' => (int) $p['id'], 'created_at' => (string) $p['created_at']], pdup_payment_hashes($pdo, (int) $p['id'])) : [];
                  if ($__pd): $__pc = $__pd[0]['level'] === 'certain';
                ?>
                  <div><span class="badge <?= $__pc ? 'text-bg-danger' : 'text-bg-warning' ?>" title="<?= e(implode(' | ', array_map(static fn($d) => 'فاکتور ' . $d['payment']['order_number'] . ' — ' . number_format((int) $d['payment']['amount']) . ' — ثبت: ' . ($d['payment']['recorder_name'] ?? '—'), $__pd))) ?>"><i class="fa-solid fa-clone"></i> <?= $__pc ? 'واریزیِ تکراری' : 'احتمالِ تکراری' ?></span></div>
                <?php endif; ?></td>
              <td class="text-nowrap"><?= $p['paid_at'] ? to_jalali($p['paid_at']) : '—' ?></td>
              <td class="small"><?= e($methods[$p['method']] ?? (string) $p['method']) ?><?= $p['ref'] ? '<div dir="ltr" class="text-muted">' . e($p['ref']) . '</div>' : '' ?></td>
              <td class="small"><?= e((string) ($p['recorder_name'] ?? '—')) ?></td>
              <td><span class="badge <?= (int) $p['files_cnt'] > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="fa-solid fa-receipt"></i> <?= to_persian_digits((string) $p['files_cnt']) ?></span></td>
              <td><a class="btn btn-sm btn-success text-nowrap" href="../order_view.php?id=<?= (int) $p['order_id'] ?>#finance"><i class="fa-solid fa-scale-balanced"></i> بررسی</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>

  <div class="row g-2 mb-3">
    <?php $qs = $_GET; unset($qs['status']); ?>
    <?php foreach ($statuses as $k => $m): $q2 = $qs; $q2['status'] = $k; ?>
      <div class="col-6 col-md-3">
        <a class="kpi <?= $status === $k ? 'active' : '' ?>" href="?<?= e(http_build_query($q2)) ?>">
          <div class="d-flex justify-content-between"><span class="small text-muted"><i class="fa-solid <?= e($m['icon']) ?>"></i> <?= e($m['label']) ?></span><span class="n text-<?= e($m['color']) ?>"><?= to_persian_digits((string) $counts[$k]) ?></span></div>
          <div class="a"><?= format_toman($sums[$k]) ?></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card p-3 mb-3">
    <form method="get" class="row g-2 align-items-end">
      <?php if ($view === 'report'): ?><input type="hidden" name="view" value="report"><?php endif; ?>
      <div class="col-12 d-flex gap-2 flex-wrap">
        <?php foreach (['all' => 'همه‌ی تاریخ‌ها', 'today' => 'امروز', 'this_week' => 'این هفته', 'this_month' => 'این ماه', 'last_month' => 'ماه گذشته', 'custom' => 'بازه دلخواه'] as $pk => $pl): $q3 = $_GET; $q3['preset'] = $pk; ?>
          <a class="chip <?= $preset === $pk ? 'active' : '' ?>" href="?<?= e(http_build_query($q3)) ?>"><?= $pl ?></a>
        <?php endforeach; ?>
        <?php if ($from !== ''): ?><span class="small text-muted align-self-center">بازه: <?= to_jalali($from) ?> تا <?= to_jalali($to) ?></span><?php endif; ?>
      </div>
      <input type="hidden" name="preset" value="<?= e($preset) ?>">
      <?php if ($preset === 'custom'): ?>
        <div class="col-md-2"><label class="form-label small mb-1">از</label><input name="from" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label small mb-1">تا</label><input name="to" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
      <?php endif; ?>
      <div class="col-md-3"><label class="form-label small mb-1">جستجو</label><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="مشتری، موبایل، شماره فاکتور، پیگیری"></div>
      <div class="col-md-2"><label class="form-label small mb-1">وضعیت</label>
        <select name="status" class="form-select form-select-sm">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>همه</option>
          <?php foreach ($statuses as $sk => $sm): ?><option value="<?= e($sk) ?>" <?= $status === $sk ? 'selected' : '' ?>><?= e($sm['label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small mb-1">کارشناس</label><?= $sellerPicker($sellers, $sellerId, 'main') ?></div>
      <div class="col-md-2"><label class="form-label small mb-1">تیم</label>
        <select name="team" class="form-select form-select-sm"><option value="">همه‌ی تیم‌ها</option>
          <?php foreach ($teamsAll as $__t): ?><option value="<?= (int) $__t['id'] ?>" <?= $teamF === (string) $__t['id'] ? 'selected' : '' ?>><?= e($__t['label'] . ($__t['leader_name'] !== '' ? ' — ' . $__t['leader_name'] : '')) ?></option><?php endforeach; ?>
          <option value="none" <?= $teamF === 'none' ? 'selected' : '' ?>>بدونِ تیم</option>
        </select></div>
      <div class="col-md-2"><label class="form-label small mb-1">روش پرداخت</label>
        <select name="method" class="form-select form-select-sm"><option value="">همه</option>
          <?php foreach ($methods as $mk => $ml): ?><option value="<?= e($mk) ?>" <?= $method === $mk ? 'selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-sm btn-success">اعمال</button>
        <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1, 'status' => $status]))) ?>" title="با همین فیلترها و جستجو: سفارش‌ها + فروشِ تیم‌ها + فروشِ کارشناسان"><i class="fa-solid fa-file-excel"></i> خروجی اکسل</a>
      </div>
    </form>
  </div>

  <?php if ($view === 'list'): ?>
    <?php $__consents = consent_statuses_for($pdo, array_column($rows, 'id')); ?>
    <div class="d-flex gap-2 mb-2 small">
      <a class="chip <?= $status === 'all' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($qs, ['status' => 'all']))) ?>">همه‌ی وضعیت‌ها</a>
    </div>
    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>شماره</th><th>ثبت / ارسال</th><th>مشتری</th><th>خدمات</th><th>کارشناس</th><th>مبلغ فاکتور</th><th>پرداختی</th><th>روش</th><th>فیش</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4"><?= $status === 'pending' ? 'سفارشی در صفِ بررسی نیست 🎉' : 'سفارشی پیدا نشد.' ?></td></tr><?php endif; ?>
          <?php foreach ($rows as $r): $diff = (int) $r['paid_amount'] - (int) $r['total_amount']; ?>
            <tr>
              <td class="fw-semibold text-nowrap"><?= e(to_persian_digits($r['order_number'])) ?></td>
              <td class="small text-nowrap"><?= to_jalali($r['submitted_at'] ?? $r['created_at']) ?><div class="text-muted"><?= e(substr((string) ($r['submitted_at'] ?? $r['created_at']), 11, 5)) ?></div></td>
              <td><?= e($r['customer_name'] ?? '—') ?><div class="small text-muted" dir="ltr"><?= e((string) $r['customer_mobile']) ?></div></td>
              <td class="small" style="max-width:220px"><?= e(mb_strimwidth((string) $r['items_txt'], 0, 90, '…')) ?></td>
              <td class="small"><?= e($r['seller_name'] ?? '—') ?><div class="text-muted"><?= e(role_label((string) $r['seller_role'])) ?></div></td>
              <td class="text-nowrap"><?= format_toman((int) $r['total_amount']) ?></td>
              <td class="text-nowrap"><?= format_toman((int) $r['paid_amount']) ?><?php if ($diff < 0): ?><div class="small text-warning">کسری <?= format_toman(abs($diff)) ?></div><?php endif; ?></td>
              <td class="small"><?= e($methods[$r['payment_method']] ?? (string) $r['payment_method']) ?><?php if ($r['payment_ref']): ?><div class="text-muted" dir="ltr"><?= e($r['payment_ref']) ?></div><?php endif; ?></td>
              <td><span class="badge <?= (int) $r['files_cnt'] > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="fa-solid fa-receipt"></i> <?= to_persian_digits((string) $r['files_cnt']) ?></span></td>
              <td><?= !empty($r['is_legacy']) && $r['status'] === 'approved' ? '<span class="badge text-bg-info"><i class="fa-solid fa-hand-holding-dollar"></i> اقساطِ قبلی</span>' : orders_status_badge((string) $r['status']) ?>
                <?php $__cs = $__consents[(int) $r['id']] ?? '';
                  if ($__cs === 'pending'): ?><div><a href="../order_view.php?id=<?= (int) $r['id'] ?>#order-consent" class="badge text-bg-danger text-decoration-none"><i class="fa-solid fa-file-signature"></i> پیامِ رضایت: در انتظارِ بررسی</a></div>
                <?php elseif ($__cs === 'approved'): ?><div><span class="badge text-bg-success"><i class="fa-solid fa-file-signature"></i> رضایت تأیید شد</span></div><?php endif; ?>
                <?php
                  // هشدارِ واریزیِ تکراری (فقط برای سفارش‌های در انتظار — همان‌هایی که مالی باید تصمیم بگیرد)
                  $__dups = ($r['status'] === 'pending' && pdup_ready($pdo)) ? pdup_candidates($pdo, $r) : [];
                  if ($__dups):
                      $__cert = $__dups[0]['level'] === 'certain';
                      $__with = [];
                      foreach ($__dups as $__d) $__with[] = to_persian_digits((string) $__d['order']['order_number']) . ' (' . ($__d['order']['seller_name'] ?? '—') . ')';
                ?>
                  <div class="mt-1"><a href="../order_view.php?id=<?= (int) $r['id'] ?>#dup-check" class="badge <?= $__cert ? 'text-bg-danger' : 'text-bg-warning' ?> text-decoration-none" title="<?= e('مشابهِ: ' . implode('، ', $__with)) ?>"><i class="fa-solid fa-clone"></i> <?= $__cert ? 'واریزیِ تکراری' : 'احتمالِ تکراری' ?></a></div>
                <?php endif; ?>
              </td>
              <td><a href="../order_view.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm <?= $r['status'] === 'pending' && $canDecide ? 'btn-success' : 'btn-outline-primary' ?> text-nowrap"><?= $r['status'] === 'pending' && $canDecide ? '<i class="fa-solid fa-scale-balanced"></i> بررسی' : '<i class="fa-solid fa-eye"></i>' ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <?php
      // لینکِ «ریزِ همین عدد» (همان فیلترها + کارشناس/تیمِ انتخاب‌شده)
      $__dLink = static fn(array $x): string => '?' . http_build_query(array_merge(array_diff_key($_GET, ['du' => 1, 'dt' => 1, 'export' => 1]), $x)) . '#sales-detail';
      $__dl = static fn(array $x, string $label, string $cls = ''): string => '<a class="text-decoration-none ' . $cls . '" style="border-bottom:1px dashed" title="نمایشِ سفارش‌های همین عدد" href="' . e($__dLink($x)) . '">' . $label . '</a>';
    ?>
    <?php if ($report['detail']): $D = $report['detail']; $__dSum = 0; ?>
      <div class="card p-3 mb-3" id="sales-detail" style="border:2px solid #22c55e">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-magnifying-glass-dollar text-success"></i> ریزِ فروشِ «<?= e($D['title']) ?>» <span class="small text-muted fw-normal">— سفارش‌های تأییدشده‌ای که عددِ این <?= $D['is_team'] ? 'تیم' : 'کارشناس' ?> را در همین بازه و فیلترها ساخته‌اند</span></h6>
          <a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_diff_key($_GET, ['du' => 1, 'dt' => 1]))) ?>"><i class="fa-solid fa-xmark"></i> بستن</a>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
          <thead class="table-light"><tr><th>#</th><th>شماره فاکتور</th><th>تاریخ ثبت / تأیید</th><th>مشتری</th><th>خدمات</th><?php if ($D['is_team']): ?><th>کارشناس</th><?php endif; ?><th class="text-end">کلِ سفارش (خالص)</th><th class="text-end">فروشِ <?= $D['is_team'] ? 'کارشناس' : 'این کارشناس' ?> در این بازه (خالص)</th><th>توضیح</th></tr></thead><tbody>
          <?php if (!$D['rows']): ?><tr><td colspan="9" class="text-center text-muted py-3">سفارشی پیدا نشد.</td></tr><?php endif; ?>
          <?php foreach ($D['rows'] as $__i => $x): $o = $D['orders'][(int) $x['order_id']] ?? null; if (!$o) continue; $__dSum += (int) $x['amt']; ?>
            <tr>
              <td><?= to_persian_digits((string) ($__i + 1)) ?></td>
              <td class="text-nowrap"><a href="../order_view.php?id=<?= (int) $o['id'] ?>" target="_blank" class="fw-semibold"><?= e(to_persian_digits((string) $o['order_number'])) ?></a></td>
              <td class="text-nowrap"><?= to_jalali((string) $o['created_at']) ?><div class="text-muted">تأیید: <?= $o['decided_at'] ? to_jalali((string) $o['decided_at']) : '—' ?></div></td>
              <td><?= e((string) $o['customer_name']) ?><div class="text-muted" dir="ltr" style="text-align:right"><?= e((string) $o['customer_mobile']) ?></div></td>
              <td style="max-width:260px"><?= e((string) $o['items_txt']) ?></td>
              <?php if ($D['is_team']): ?><td><?= e($D['names'][(int) $x['uid']] ?? '—') ?></td><?php endif; ?>
              <td class="text-end text-nowrap"><?= format_toman((int) $o['order_amt']) ?></td>
              <td class="text-end text-nowrap fw-bold"><?= format_toman((int) $x['amt']) ?><?php if ((int) ($x['pay_events'] ?? 0) > 0): ?><div class="text-success fw-normal" style="font-size:11px"><i class="fa-solid fa-coins"></i> شاملِ قسط/پرداختِ تأییدشده در این بازه</div><?php endif; ?></td>
              <td class="small"><?php if ((int) $x['shared']): ?>
                  <span class="badge text-bg-light border" style="color:#6d28d9"><i class="fa-solid fa-people-group"></i> فروشِ مشترک</span>
                  <div class="text-muted mt-1">مالی عددِ این سفارش را بینِ این افراد تقسیم کرده:</div>
                  <?php foreach ($D['splits'][(int) $o['id']] ?? [] as [$__sn, $__sa]): ?><div class="text-muted">• <bdi><?= e($__sn) ?></bdi>: <?= format_toman($__sa) ?></div><?php endforeach; ?>
                  <?php if ((int) $x['amt'] < 1000): ?><div class="text-danger mt-1"><i class="fa-solid fa-triangle-exclamation"></i> سهمِ این کارشناس تقریباً صفر ثبت شده؛ اگر اشتباه است، در صفحه‌ی سفارش «فروشِ مشترک» را اصلاح کنید.</div><?php endif; ?>
                <?php else: ?><span class="text-muted">کلِ مبلغ به نامِ ثبت‌کننده‌ی سفارش</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <?php if ($D['rows']): ?><tfoot class="table-light fw-bold"><tr><td colspan="<?= $D['is_team'] ? 7 : 6 ?>">جمع (همان عددی که روی آن زدید)</td><td class="text-end"><?= format_toman($__dSum) ?></td><td></td></tr></tfoot><?php endif; ?>
        </table></div>
      </div>
    <?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-12">
        <div class="card p-3"><h6 class="fw-bold mb-2">فروشِ تأییدشده به تفکیکِ روزِ واریز</h6><canvas id="chDay" height="90"></canvas></div>
      </div>
      <?php $__grand = array_sum(array_map(static fn($t) => $t['amt'], $report['by_team'])); ?>
      <div class="col-lg-12">
        <div class="card p-3" id="by-team">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h6 class="fw-bold mb-0"><i class="fa-solid fa-people-group text-success"></i> فروش به تفکیکِ تیم <span class="small text-muted fw-normal">(تیم = سرپرست «D» + نیروهای A / B / C همان تیم — سفارش‌های تأییدشده)</span></h6>
            <span class="small text-muted">روی نامِ تیم بزنید تا اعضا باز شوند؛ روی هر <span style="border-bottom:1px dashed">مبلغ یا تعداد</span> بزنید تا سفارش‌هایش را ببینید.</span>
          </div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>تیم</th><th>سرپرست</th><th class="text-end">A</th><th class="text-end">B</th><th class="text-end">C</th><th class="text-end">D (سرپرست)</th><th>تعداد</th><th class="text-end">جمعِ فروشِ تیم</th><th style="min-width:110px">سهم از کل</th></tr></thead><tbody>
            <?php if (!$report['by_team']): ?><tr><td colspan="9" class="text-center text-muted py-3">فروشِ تأییدشده‌ای در این بازه نیست.</td></tr><?php endif; ?>
            <?php foreach ($report['by_team'] as $t): $__pct = $__grand > 0 ? round($t['amt'] / $__grand * 100, 1) : 0; $__q = $_GET; $__q['team'] = $t['team_id'] ?: 'none'; ?>
              <tr>
                <td><details><summary class="fw-semibold"><?= e($t['label']) ?></summary>
                  <table class="table table-sm small mb-0 mt-1"><tbody>
                    <?php foreach ($t['members'] as $m): ?><tr><td><?= e((string) $m['full_name']) ?><?php if ((int) ($m['shared_cnt'] ?? 0) > 0): ?> <span class="badge text-bg-light border" style="color:#6d28d9" title="شاملِ سهم از فروشِ مشترک">مشترک</span><?php endif; ?></td><td><span class="badge text-bg-light border"><?= e($m['slot']) ?></span></td><td><?= $__dl(['du' => ((int) $m['uid'] ?: -1)], to_persian_digits((string) $m['cnt']) . ' سفارش') ?></td><td class="text-end"><?= $__dl(['du' => ((int) $m['uid'] ?: -1)], format_toman((int) $m['amt'])) ?></td></tr><?php endforeach; ?>
                  </tbody></table>
                  <?php if ((int) $t['team_id'] === -1): ?><div class="small text-muted mt-1">سفارش‌هایی که ثبت‌کننده‌شان طبقِ قوانینِ سهمِ عملکرد در آن سفارش جایگاهی ندارد (همان «سازمان آراد برندینگ» در برگه‌ی سهمِ سفارش).</div>
                  <?php else: ?><a class="small" href="?<?= e(http_build_query($__q)) ?>">فقط همین تیم ←</a><?php endif; ?></details></td>
                <td class="small"><?= e($t['leader_name'] ?: '—') ?></td>
                <?php foreach (['A', 'B', 'C', 'D'] as $__sl): ?><td class="text-end small"><?= format_toman($t['slots'][$__sl]) ?></td><?php endforeach; ?>
                <td><?= $__dl(['dt' => $t['team_id'] ?: 'none'], to_persian_digits((string) $t['cnt'])) ?></td>
                <td class="text-end fw-bold"><?= $__dl(['dt' => $t['team_id'] ?: 'none'], format_toman($t['amt'])) ?></td>
                <td><div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $__pct ?>%"></div></div><span class="small text-muted"><?= to_persian_digits((string) $__pct) ?>٪</span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (count($report['by_team']) > 1): $__tt = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'cnt' => 0];
              foreach ($report['by_team'] as $t) { foreach (['A', 'B', 'C', 'D'] as $__sl) $__tt[$__sl] += $t['slots'][$__sl]; $__tt['cnt'] += $t['cnt']; } ?>
              <tfoot class="table-light fw-bold"><tr><td colspan="2">جمع</td><?php foreach (['A', 'B', 'C', 'D'] as $__sl): ?><td class="text-end small"><?= format_toman($__tt[$__sl]) ?></td><?php endforeach; ?>
                <td><?= to_persian_digits((string) $__tt['cnt']) ?></td><td class="text-end"><?= format_toman($__grand) ?></td><td></td></tr></tfoot>
            <?php endif; ?>
          </table></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card p-3 h-100">
          <h6 class="fw-bold mb-1">فروش به تفکیکِ کارشناس</h6>
          <div class="small text-muted mb-2">مبلغ‌ها <b>خالص (بدونِ مالیات)</b>؛ فروش = پیش‌پرداختِ تأییدشده در روزِ واریز (طبقِ فیش) + هر قسط/پرداختِ بعدی در روزِ واریزِ همان پرداخت. روی تعداد یا مبلغِ هر کارشناس بزنید تا سفارش‌هایش را ببینید. «مشترک» یعنی مالی عددِ آن سفارش را بینِ چند کارشناس تقسیم کرده و این‌جا فقط سهمِ همین نفر آمده.</div>
          <table class="table table-sm mb-0"><thead class="table-light"><tr><th>کارشناس</th><th>واحد</th><th>تیم</th><th>تعداد</th><th class="text-end">مبلغ</th></tr></thead><tbody>
            <?php if (!$report['by_seller']): ?><tr><td colspan="5" class="text-center text-muted py-3">فروشِ تأییدشده‌ای در این بازه نیست.</td></tr><?php endif; ?>
            <?php $__umap = tsr_user_team_map($pdo); ?>
            <?php foreach ($report['by_seller'] as $r): ?><tr><td><?= e((string) $r['full_name']) ?><?php if ((int) ($r['shared_cnt'] ?? 0) > 0): ?> <span class="badge text-bg-light border" style="color:#6d28d9" title="سفارش‌هایی که عددِ فروششان با کارشناسانِ دیگر تفکیک شده"><i class="fa-solid fa-people-group"></i> <?= to_persian_digits((string) (int) $r['shared_cnt']) ?> مشترک</span><?php endif; ?></td><td class="small"><?= e(role_label((string) $r['role'])) ?></td><td class="small"><?= e($teamsAll[$__umap[(int) ($r['uid'] ?? 0)] ?? 0]['label'] ?? '—') ?></td><td><?= $__dl(['du' => ((int) ($r['uid'] ?? 0) ?: -1)], to_persian_digits((string) $r['cnt'])) ?></td><td class="text-end"><?= $__dl(['du' => ((int) ($r['uid'] ?? 0) ?: -1)], format_toman((int) $r['amt'])) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card p-3 h-100">
          <h6 class="fw-bold mb-2">پرفروش‌ترین خدمات</h6>
          <table class="table table-sm mb-0"><thead class="table-light"><tr><th>خدمت</th><th>تعداد سفارش</th><th>مقدار</th><th class="text-end">مبلغ</th></tr></thead><tbody>
            <?php if (!$report['by_service']): ?><tr><td colspan="4" class="text-center text-muted py-3">—</td></tr><?php endif; ?>
            <?php foreach ($report['by_service'] as $r): ?><tr><td class="small"><?= e($r['title']) ?></td><td><?= to_persian_digits((string) $r['orders_cnt']) ?></td><td><?= to_persian_digits((string) (float) $r['qty']) ?></td><td class="text-end"><?= format_toman((int) $r['amt']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    new Chart(document.getElementById('chDay'), {
      type: 'bar',
      data: { labels: <?= json_encode(array_map('to_jalali', array_map('strval', array_keys($report['by_day']))), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{ label: 'تومان', data: <?= json_encode(array_map('intval', array_values($report['by_day']))) ?>, backgroundColor: '#22c55e' }] },
      options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
    </script>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>
<script>
// انتخابِ کارشناس با جستجو: متنِ انتخاب‌شده → شناسه‌ی کارشناس (خالی = همه)
document.querySelectorAll('.seller-pick').forEach(function (inp) {
  var hidden = document.getElementById(inp.dataset.target), list = document.getElementById(inp.getAttribute('list'));
  function sync() {
    var v = inp.value.trim(), id = 0;
    if (v !== '') {
      for (var i = 0; i < list.options.length; i++) { if (list.options[i].value === v) { id = list.options[i].getAttribute('data-id'); break; } }
    }
    hidden.value = id;
    inp.classList.toggle('is-invalid', v !== '' && !id);
  }
  inp.addEventListener('input', sync); inp.addEventListener('change', sync);
  if (inp.form) inp.form.addEventListener('submit', sync);
});
</script>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
