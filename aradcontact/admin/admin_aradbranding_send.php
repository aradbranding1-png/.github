<?php
/**
 * «ارسال تیکت‌ها» (آراد برندینگ):
 *   - وضعیتِ ارسال به تفکیکِ مشتری: ارسال‌شده/نشده، با تیک برای «ارسال شود» / «ارسال نشود» / برگرداندن
 *   - فهرستِ تک‌تکِ تیکت‌ها + ارسالِ گروهی
 * تنظیماتِ اتصال و قالب ← admin_aradbranding_ticket.php («تنظیمات تیکت»)
 */
require_once __DIR__ . '/../includes/auth.php';
$admin = require_login();
$pdo = db();
require_once __DIR__ . '/../includes/services_functions.php';
require_once __DIR__ . '/../includes/orders_functions.php';
require_once __DIR__ . '/../includes/contracts_functions.php';
require_once __DIR__ . '/../includes/aradbranding_ticket.php';

if (!abt_can_manage($admin) && !user_can('finance_settings', $admin)) {
    http_response_code(403);
    exit('دسترسی ندارید.');
}
if (!abt_ready($pdo)) {
    flash_set('danger', 'جدول‌های تیکتِ آراد برندینگ ساخته نشدند.');
    redirect('admin_dashboard.php');
}
$canSend = abt_can_manage($admin);
// تیکت‌های تکراریِ «اسنادِ قرارداد» (هر بار تلاشِ ناموفق یک تیکتِ جدید ساخته بود): فقط تازه‌ترین تیکتِ ارسال‌نشده‌ی هر قرارداد می‌ماند
try {
    $__dup = $pdo->query("SELECT t.id FROM aradbranding_tickets t JOIN aradbranding_tickets n
        ON n.item_id IS NULL AND n.order_id = t.order_id AND n.service_title = t.service_title AND n.id > t.id
        WHERE t.item_id IS NULL AND t.status IN ('queued','failed','skipped')")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($__dup) $pdo->exec('DELETE FROM aradbranding_tickets WHERE id IN (' . implode(',', array_map('intval', array_unique($__dup))) . ')');
} catch (Throwable $e) {}
try { require_once __DIR__ . '/../includes/edu_provision.php'; edu_bundle_fix_all($pdo); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect('admin_aradbranding_send.php');
    }
    if (!$canSend) {
        flash_set('danger', 'فقط کسانی که سفارش را تأیید می‌کنند می‌توانند تیکت ارسال کنند.');
        redirect('admin_aradbranding_send.php');
    }
    // ─── وضعیتِ ارسال به تفکیکِ مشتری: ارسال / «ارسال نشود» / برگرداندن، برای مشتریانِ تیک‌خورده ───
    if (in_array($_POST['action'] ?? '', ['cust_send', 'cust_skip', 'cust_unskip', 'cust_delete'], true)) {
        $a = (string) $_POST['action'];
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['customer_ids'] ?? [])))));
        $back = 'admin_aradbranding_send.php?' . http_build_query(['f' => $_POST['f'] ?? '', 'q' => $_POST['q'] ?? '', 'p' => (int) ($_POST['p'] ?? 1), 'per' => (int) ($_POST['per'] ?? 50)]);
        if (!$ids) { flash_set('warning', 'هیچ مشتری‌ای تیک نخورده است.'); redirect($back); }
        $in = implode(',', $ids);
        $now = date('Y-m-d H:i:s');
        if ($a === 'cust_delete') {
            // حذفِ همه‌ی تیکت‌های ارسال‌شده‌ی این مشتری‌ها از سامانه‌ی آراد برندینگ (هر بار حداکثر ۶۰ تیکت)
            if (!abt_connection_ready(abt_settings($pdo))) { flash_set('danger', 'اتصال فعال/تنظیم نیست.'); redirect($back); }
            @set_time_limit(300);
            $reason = mb_substr(trim((string) ($_POST['delete_reason'] ?? '')), 0, 200);
            $rows = $pdo->query("SELECT * FROM aradbranding_tickets WHERE customer_id IN ($in) AND status IN ('sent','manual') ORDER BY id ASC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ok = 0; $fail = [];
            foreach ($rows as $t) {
                $o = orders_get($pdo, (int) $t['order_id']) ?: ['id' => (int) $t['order_id'], 'customer_id' => (int) $t['customer_id']];
                try {
                    $r = abt_delete_remote($pdo, $o, $t, (int) $admin['id'], $reason !== '' ? $reason : 'حذفِ گروهی از «ارسال تیکت‌ها»');
                } catch (Throwable $e) {
                    $r = ['ok' => false, 'message' => $e->getMessage()];
                }
                if ($r['ok']) $ok++; else $fail[] = ($t['service_title'] ?? '#' . $t['id']) . ': ' . $r['message'];
            }
            $left = (int) $pdo->query("SELECT COUNT(*) FROM aradbranding_tickets WHERE customer_id IN ($in) AND status IN ('sent','manual')")->fetchColumn();
            flash_set($fail ? 'warning' : 'success', to_persian_digits((string) $ok) . ' تیکت از آراد برندینگ حذف شد (حالا در «حذف‌شده» هستند و هر وقت خواستید با «ارسال شود» دوباره می‌روند).'
                . ($fail ? ' ' . to_persian_digits((string) count($fail)) . ' مورد حذف نشد — نمونه: ' . $fail[0] : '')
                . ($left ? ' هنوز ' . to_persian_digits((string) $left) . ' تیکتِ ارسال‌شده از همین مشتری‌ها مانده (دوباره بزنید).' : ''));
        } elseif ($a === 'cust_skip') {
            $n = $pdo->exec("UPDATE aradbranding_tickets SET status = 'skipped', updated_at = " . $pdo->quote($now) . " WHERE customer_id IN ($in) AND status IN ('queued','failed')");
            flash_set('success', to_persian_digits((string) (int) $n) . ' تیکت «ارسال نشود» شد (برای ' . to_persian_digits((string) count($ids)) . ' مشتری).');
        } elseif ($a === 'cust_unskip') {
            // «ارسال نشود» ← صف؛ و تیکت‌های «ارسال‌شده/ثبتِ دستی» ← بازگشایی برای ارسالِ مجدد (شناسه‌ی یکتای تازه)
            $n = $pdo->exec("UPDATE aradbranding_tickets SET status = 'queued', updated_at = " . $pdo->quote($now) . " WHERE customer_id IN ($in) AND status = 'skipped'");
            $reopened = 0;
            foreach ($pdo->query("SELECT * FROM aradbranding_tickets WHERE customer_id IN ($in) AND status IN ('sent','manual','bundled') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
                $o = orders_get($pdo, (int) $t['order_id']) ?: ['id' => (int) $t['order_id'], 'customer_id' => (int) $t['customer_id']];
                try {
                    $r = abt_resend($pdo, $o, $t, (int) $admin['id'], false);
                    if ($r['ok']) $reopened++;
                } catch (Throwable $e) {
                    error_log('cust_unskip resend: ' . $e->getMessage());
                    $reopened++; // وضعیت پیش از خطای تاریخچه عوض شده است
                }
            }
            flash_set('success', to_persian_digits((string) ((int) $n + $reopened)) . ' تیکت به «آماده‌ی ارسال» برگشت'
                . ($reopened ? ' (' . to_persian_digits((string) $reopened) . ' تیکتِ ارسال‌شده برای ارسالِ مجدد بازگشایی شد)' : '') . '؛ این مشتری‌ها حالا در «ارسال‌نشده» هستند.');
        } else {
            if (!abt_connection_ready(abt_settings($pdo))) { flash_set('danger', 'اتصال فعال/تنظیم نیست.'); redirect($back); }
            @set_time_limit(300);
            $rows = $pdo->query("SELECT * FROM aradbranding_tickets WHERE customer_id IN ($in) AND status IN ('queued','failed','skipped','deleted') AND order_id IN (SELECT id FROM sales_orders WHERE status = 'approved') ORDER BY id ASC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ok = 0; $fail = [];
            foreach ($rows as $t) {
                $o = orders_get($pdo, (int) $t['order_id']);
                if (!$o) continue;
                $r = abt_send($pdo, $o, $t, (int) $admin['id']);
                if ($r['ok']) $ok++; else $fail[] = $r['message'];
            }
            $left = (int) $pdo->query("SELECT COUNT(*) FROM aradbranding_tickets WHERE customer_id IN ($in) AND status IN ('queued','failed','skipped','deleted') AND order_id IN (SELECT id FROM sales_orders WHERE status = 'approved')")->fetchColumn();
            flash_set($fail ? 'warning' : 'success', to_persian_digits((string) $ok) . ' تیکت ارسال شد.'
                . ($fail ? ' ' . to_persian_digits((string) count($fail)) . ' ناموفق — نمونه: ' . $fail[0] : '')
                . ($left ? ' هنوز ' . to_persian_digits((string) $left) . ' تیکت از همین مشتری‌ها مانده (دوباره بزنید).' : ''));
        }
        redirect($back);
    }
    if (($_POST['action'] ?? '') === 'acc_sms_done') {
        abt_account_sms_done($pdo, (int) ($_POST['customer_id'] ?? 0), (int) $admin['id']);
        flash_set('success', 'ثبت شد: اطلاعاتِ ورود برای مشتری فرستاده شد.');
        redirect('admin_aradbranding_send.php?tab=accounts&' . http_build_query(['aq' => (string) ($_POST['aq'] ?? '')]));
    }
    if (($_POST['action'] ?? '') === 'send_queued') {
        // ارسالِ گروهیِ تیکت‌های «آماده‌ی ارسال» و «ناموفق» — هر بار حداکثر ۲۵ تیکت (قدیمی‌ترین اول)
        @set_time_limit(300);
        $ok = 0; $fail = [];
        $rows = $pdo->query("SELECT * FROM aradbranding_tickets WHERE status IN ('queued','failed') AND order_id IN (SELECT id FROM sales_orders WHERE status = 'approved') ORDER BY id ASC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $t) {
            $o = orders_get($pdo, (int) $t['order_id']);
            if (!$o) continue;
            $r = abt_send($pdo, $o, $t, (int) $admin['id']);
            if ($r['ok']) $ok++; else $fail[] = $r['message'];
        }
        $left = (int) $pdo->query("SELECT COUNT(*) FROM aradbranding_tickets WHERE status IN ('queued','failed') AND order_id IN (SELECT id FROM sales_orders WHERE status = 'approved')")->fetchColumn();
        flash_set($fail ? 'warning' : 'success', to_persian_digits((string) $ok) . ' تیکت ارسال شد.'
            . ($fail ? ' ' . to_persian_digits((string) count($fail)) . ' مورد ناموفق — نمونه: ' . $fail[0] : '')
            . ($left ? ' هنوز ' . to_persian_digits((string) $left) . ' تیکت مانده (دوباره بزنید).' : ''));
        redirect('admin_aradbranding_send.php?tab=list');
    }
    redirect('admin_aradbranding_send.php');
}

$s = abt_settings($pdo);
$ready = abt_connection_ready($s);
$tab = in_array((string) ($_GET['tab'] ?? ''), ['list', 'accounts'], true) ? (string) $_GET['tab'] : 'customers';
// صفحه‌بندی
$perPage = in_array((int) ($_GET['per'] ?? 0), [25, 50, 100, 200], true) ? (int) $_GET['per'] : 50;
$pageNo = max(1, (int) ($_GET['p'] ?? 1));
$totalRows = 0;
/** نوارِ صفحه‌بندی (پارامترهای فعلیِ آدرس حفظ می‌شوند) */
function abt_pager(int $total, int $perPage, int $pageNo): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    $fa = static fn($n) => to_persian_digits((string) $n);
    $url = static function (array $set) { $q = array_merge($_GET, $set); return '?' . e(http_build_query($q)); };
    $h = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">';
    $from = $total ? ($pageNo - 1) * $perPage + 1 : 0;
    $to = min($total, $pageNo * $perPage);
    $h .= '<div class="small text-muted">' . $fa($from) . ' تا ' . $fa($to) . ' از ' . $fa($total) . ' — نمایش در هر صفحه: ';
    foreach ([25, 50, 100, 200] as $pp) {
        $h .= '<a class="chip ' . ($pp === $perPage ? 'active' : '') . '" href="' . $url(['per' => $pp, 'p' => 1]) . '">' . $fa($pp) . '</a> ';
    }
    $h .= '</div>';
    if ($pages > 1) {
        $h .= '<nav><ul class="pagination pagination-sm mb-0 flex-wrap">';
        $h .= '<li class="page-item ' . ($pageNo <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . $url(['p' => max(1, $pageNo - 1)]) . '">قبلی</a></li>';
        $shown = [];
        foreach ([1, 2, $pageNo - 2, $pageNo - 1, $pageNo, $pageNo + 1, $pageNo + 2, $pages - 1, $pages] as $n) {
            if ($n >= 1 && $n <= $pages) $shown[$n] = true;
        }
        ksort($shown);
        $prev = 0;
        foreach (array_keys($shown) as $n) {
            if ($prev && $n > $prev + 1) $h .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            $h .= '<li class="page-item ' . ($n === $pageNo ? 'active' : '') . '"><a class="page-link" href="' . $url(['p' => $n]) . '">' . $fa($n) . '</a></li>';
            $prev = $n;
        }
        $h .= '<li class="page-item ' . ($pageNo >= $pages ? 'disabled' : '') . '"><a class="page-link" href="' . $url(['p' => min($pages, $pageNo + 1)]) . '">بعدی</a></li>';
        $h .= '</ul></nav>';
    }
    return $h . '</div>';
}
$statuses = abt_statuses();
$filter = (string) ($_GET['status'] ?? '');
$sql = 'SELECT t.*, o.order_number, c.full_name AS customer_name, u.full_name AS sender_name
    FROM aradbranding_tickets t
    LEFT JOIN sales_orders o ON o.id = t.order_id
    LEFT JOIN customers c ON c.id = t.customer_id
    LEFT JOIN users u ON u.id = t.sent_by';
$params = [];
// فقط تیکت‌های سفارش‌های «تأییدشده» (سفارشِ لغو/رد/در انتظار یا تکراری در این فهرست نمی‌آید)
$sql .= " WHERE o.status = 'approved'";
if (isset($statuses[$filter])) {
    $sql .= ' AND t.status = ?';
    $params[] = $filter;
}
$tickets = [];
if ($tab === 'list') {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM aradbranding_tickets t JOIN sales_orders o ON o.id = t.order_id WHERE o.status = 'approved'" . ($params ? ' AND t.status = ?' : ''));
    $cnt->execute($params);
    $totalRows = (int) $cnt->fetchColumn();
    $pageNo = min($pageNo, max(1, (int) ceil($totalRows / $perPage)));
    $sql .= ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
// شمارشِ هر تب با همان شرطِ فهرست (فقط تیکت‌های سفارش‌های «تأییدشده») تا عددِ تب با ردیف‌های فهرست یکی باشد
$counts = [];
$countAll = 0;
foreach ($pdo->query("SELECT t.status, COUNT(*) n FROM aradbranding_tickets t JOIN sales_orders o ON o.id = t.order_id WHERE o.status = 'approved' GROUP BY t.status")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $counts[(string) $r['status']] = (int) $r['n'];
    $countAll += (int) $r['n'];
}

$custRows = [];
$custSum = ['all' => 0, 'done' => 0, 'pending' => 0, 'deleted' => 0, 'skipped' => 0];
$deletedTickets = 0;
$cf = (string) ($_GET['f'] ?? '');
$cq = trim((string) ($_GET['q'] ?? ''));
if ($tab === 'customers') {
    $csql = "SELECT t.customer_id, c.full_name, c.mobile, COUNT(*) AS total,
            SUM(t.status IN ('sent','manual','bundled')) AS sent, SUM(t.status = 'queued') AS queued, SUM(t.status = 'failed') AS failed, SUM(t.status = 'skipped') AS skipped, SUM(t.status = 'deleted') AS deleted,
            GROUP_CONCAT(DISTINCT o.order_number ORDER BY o.id SEPARATOR '، ') AS orders,
            GROUP_CONCAT(CONCAT(t.status, '|', REPLACE(COALESCE(t.service_title, ''), '|', ' '), '|', t.order_id, '|', COALESCE(t.external_id, ''), '|', COALESCE(t.external_url, ''), '|', COALESCE(o.order_number, '')) ORDER BY t.order_id, t.id SEPARATOR '§') AS items,
            MAX(t.updated_at) AS last_at
        FROM aradbranding_tickets t
        LEFT JOIN customers c ON c.id = t.customer_id
        LEFT JOIN sales_orders o ON o.id = t.order_id
        WHERE t.customer_id IS NOT NULL AND o.status = 'approved'" . ($cq !== '' ? ' AND (c.full_name LIKE ? OR c.mobile LIKE ?)' : '') . "
        GROUP BY t.customer_id, c.full_name, c.mobile
        ORDER BY (SUM(t.status IN ('queued','failed')) > 0) DESC, MAX(t.updated_at) DESC";
    try { $pdo->exec('SET SESSION group_concat_max_len = 1000000'); } catch (Throwable $e) {} // فهرستِ کاملِ تیکت‌ها بریده نشود
    $cst = $pdo->prepare($csql);
    $cst->execute($cq !== '' ? ['%' . $cq . '%', '%' . $cq . '%'] : []);
    foreach ($cst->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $pend = (int) $r['queued'] + (int) $r['failed'];
        // حذف‌شده از آراد برندینگ: تیکت آن‌جا نیست؛ تا دوباره فرستاده نشود جدا شمرده می‌شود
        $r['state'] = $pend > 0 ? 'pending' : ((int) $r['deleted'] > 0 ? 'deleted' : (((int) $r['skipped'] > 0 && (int) $r['sent'] === 0) ? 'skipped' : 'done'));
        $custSum['all']++;
        if ($r['state'] !== 'deleted') $custSum[$r['state']]++;
        // «حذف‌شده»: هر مشتری‌ای که دست‌کم یک تیکتِ حذف‌شده دارد (حتی اگر تیکتِ در صف هم داشته باشد)
        if ((int) $r['deleted'] > 0) { $custSum['deleted']++; $deletedTickets += (int) $r['deleted']; }
        if ($cf === 'deleted' ? (int) $r['deleted'] === 0 : ($cf !== '' && $cf !== $r['state'])) continue;
        $custRows[] = $r;
    }
    $totalRows = count($custRows);
    $pageNo = min($pageNo, max(1, (int) ceil($totalRows / $perPage)));
    $custRows = array_slice($custRows, ($pageNo - 1) * $perPage, $perPage);
}

// اکانت‌هایی که آراد کانتکت در آراد برندینگ ساخته است (نام کاربری/رمز برای ارسالِ دوباره به مشتری)
$accRows = [];
$accCount = 0;
$aq = trim((string) ($_GET['aq'] ?? ''));
try {
    $accCount = (int) $pdo->query("SELECT COUNT(*) FROM aradbranding_accounts WHERE status = 'created'")->fetchColumn();
    if ($tab === 'accounts') {
        $ast = $pdo->prepare("SELECT a.*, c.full_name, u.full_name AS creator_name, su.full_name AS sms_name, o.order_number
            FROM aradbranding_accounts a
            LEFT JOIN customers c ON c.id = a.customer_id
            LEFT JOIN users u ON u.id = a.created_by
            LEFT JOIN users su ON su.id = a.sms_done_by
            LEFT JOIN sales_orders o ON o.id = a.order_id
            WHERE a.status = 'created'" . ($aq !== '' ? ' AND (c.full_name LIKE ? OR a.mobile LIKE ? OR a.username LIKE ?)' : '') . "
            ORDER BY (a.sms_done_at IS NULL) DESC, a.created_at DESC");
        $ast->execute($aq !== '' ? ['%' . $aq . '%', '%' . $aq . '%', '%' . $aq . '%'] : []);
        $accRows = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalRows = count($accRows);
        $pageNo = min($pageNo, max(1, (int) ceil($totalRows / $perPage)));
        $accRows = array_slice($accRows, ($pageNo - 1) * $perPage, $perPage);
    }
} catch (Throwable $e) {
    error_log('abt accounts list: ' . $e->getMessage());
}
$accLoginUrl = trim((string) ($s['acc_login_url'] ?? '')) ?: 'https://my.aradbranding.me';

$pageTitle = 'ارسال تیکت‌ها';
require_once __DIR__ . '/../includes/layout_top.php';
?>
<style>
.abt-page .card{ border-radius:14px; border:1px solid rgba(201,162,75,.28); }
.abt-page .chip{ display:inline-block; padding:3px 10px; border-radius:20px; border:1px solid #e7e2d3; font-size:12px; text-decoration:none; color:#44403c; }
.abt-page .chip.active{ background:#1c1917; color:#fff; border-color:#1c1917; }
</style>
<div class="abt-page">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-0"><i class="fa-solid fa-paper-plane"></i> ارسال تیکت‌ها</h4>
      <div class="text-muted small">ببینید تیکتِ چه مشتری‌هایی در آراد برندینگ ثبت شده و چه کسانی نه؛ با تیک، ارسال یا «ارسال نشود» کنید.</div>
    </div>
    <div class="d-flex gap-2">
      <span class="badge <?= $ready ? 'text-bg-success' : 'text-bg-warning' ?> align-self-center"><?= $ready ? 'اتصال فعال' : 'اتصال غیرفعال / تنظیم‌نشده' ?></span>
      <a href="admin_services_list.php" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-list-check"></i> فهرست خدمات</a>
      <?php if (user_can('finance_settings', $admin)): ?><a href="admin_aradbranding_ticket.php" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-sliders"></i> تنظیمات تیکت</a><?php endif; ?>
      <a href="admin_dashboard.php" class="btn btn-sm btn-outline-secondary">→ پنل مدیریت</a>
    </div>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'customers' ? 'active' : '' ?>" href="admin_aradbranding_send.php"><i class="fa-solid fa-users"></i> به تفکیکِ مشتری</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="admin_aradbranding_send.php?tab=list"><i class="fa-solid fa-list"></i> همه‌ی تیکت‌ها</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'accounts' ? 'active' : '' ?>" href="admin_aradbranding_send.php?tab=accounts"><i class="fa-solid fa-user-plus"></i> اکانت‌های ساخته‌شده <span class="badge text-bg-dark"><?= to_persian_digits((string) $accCount) ?></span></a></li>
  </ul>

<?php if ($tab === 'customers'): ?>

  <div class="row g-2 mb-3">
    <?php foreach (['' => ['همه‌ی مشتریان', 'dark', $custSum['all']], 'done' => ['همه‌ی تیکت‌ها ارسال شده', 'success', $custSum['done']], 'pending' => ['ارسال‌نشده (در صف/ناموفق)', 'warning', $custSum['pending']], 'deleted' => ['حذف‌شده از آراد برندینگ', 'danger', $custSum['deleted']], 'skipped' => ['«ارسال نشود»', 'secondary', $custSum['skipped']]] as $__k => [$__l, $__c, $__n]): ?>
      <div class="col-6 col-md">
        <a href="?<?= e(http_build_query(['f' => $__k, 'q' => $cq, 'per' => $perPage])) ?>" class="card p-3 text-decoration-none h-100 <?= $cf === $__k ? 'border-2 border-' . $__c : '' ?>">
          <div class="small text-muted"><?= e($__l) ?></div>
          <div class="fs-3 fw-bold text-<?= $__c ?>"><?= to_persian_digits((string) $__n) ?></div>
          <?php if ($__k === 'deleted' && $deletedTickets): ?><div class="small text-muted"><?= to_persian_digits((string) $deletedTickets) ?> تیکت</div><?php endif; ?>
        </a>
      </div>
    <?php endforeach; ?>
    <div class="col-6 col-md">
      <a href="?tab=accounts" class="card p-3 text-decoration-none h-100">
        <div class="small text-muted">اکانت‌های ساخته‌شده در آراد برندینگ</div>
        <div class="fs-3 fw-bold text-primary"><?= to_persian_digits((string) $accCount) ?></div>
      </a>
    </div>
  </div>
  <?php if ($cf === 'deleted'): ?>
    <div class="alert alert-light border small py-2"><i class="fa-solid fa-circle-info"></i> این تیکت‌ها از سایتِ آراد برندینگ حذف شده‌اند. برای ارسالِ دوباره، مشتری را تیک بزنید و «ارسال شود» را بزنید (با شناسه‌ی تازه، تیکتِ جدید ساخته می‌شود). اگر نمی‌خواهید دوباره بروند، از صفحه‌ی سفارش «ارسال نشود» کنید.</div>
  <?php endif; ?>
  <form method="get" class="d-flex gap-2 mb-2">
    <input type="hidden" name="f" value="<?= e($cf) ?>"><input type="hidden" name="per" value="<?= $perPage ?>">
    <input name="q" value="<?= e($cq) ?>" class="form-control form-control-sm" style="max-width:280px" placeholder="جستجوی نام یا موبایلِ مشتری">
    <button class="btn btn-sm btn-outline-dark">جستجو</button>
  </form>
  <form method="post" id="custForm">
    <?= csrf_field() ?><input type="hidden" name="f" value="<?= e($cf) ?>"><input type="hidden" name="q" value="<?= e($cq) ?>"><input type="hidden" name="p" value="<?= $pageNo ?>"><input type="hidden" name="per" value="<?= $perPage ?>">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2 p-2 rounded-3" style="background:#fffbeb;border:1px solid #fde68a">
      <span class="small fw-bold">برای مشتریانِ تیک‌خورده:</span>
      <button name="action" value="cust_send" class="btn btn-sm btn-success" <?= $ready && $canSend ? '' : 'disabled' ?> onclick="return confirm('تیکت‌های ارسال‌نشده یا حذف‌شده‌ی مشتریانِ تیک‌خورده (حداکثر ۴۰ تیکت در هر بار) در آراد برندینگ ثبت شود؟');"><i class="fa-solid fa-paper-plane"></i> ارسال شود</button>
      <button name="action" value="cust_skip" class="btn btn-sm btn-outline-secondary" onclick="return confirm('تیکت‌های در صف/ناموفقِ این مشتری‌ها «ارسال نشود» شوند؟');"><i class="fa-solid fa-ban"></i> ارسال نشود</button>
      <button name="action" value="cust_unskip" class="btn btn-sm btn-outline-primary" onclick="return confirm('تیکت‌های این مشتری‌ها به صفِ ارسال برمی‌گردند.\nتوجه: اگر تیکتی قبلاً ارسال شده، با ارسالِ دوباره یک تیکتِ تازه در آراد برندینگ ساخته می‌شود؛ فقط وقتی این کار را بکنید که تیکتِ قبلی آنجا حذف شده باشد.');"><i class="fa-solid fa-rotate-left"></i> برگرداندن به صفِ ارسال</button>
      <button name="action" value="cust_delete" class="btn btn-sm btn-outline-danger" <?= $ready && $canSend ? '' : 'disabled' ?> onclick="var r = prompt('همه‌ی تیکت‌های ارسال‌شده‌ی مشتریانِ تیک‌خورده از سامانه‌ی آراد برندینگ حذف شوند؟\n(هر بار حداکثر ۶۰ تیکت؛ بعداً با «ارسال شود» می‌توانید دوباره بفرستید)\n\nدلیلِ حذف (اختیاری):', ''); if (r === null) return false; document.getElementById('custDelReason').value = r; return true;"><i class="fa-solid fa-trash-can"></i> حذفِ همه‌ی تیکت‌ها از آراد برندینگ</button>
      <input type="hidden" name="delete_reason" id="custDelReason" value="">
      <span class="small text-muted ms-auto"><span id="custSel">۰</span> مشتری انتخاب شده</span>
    </div>
    <div class="card p-0"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
      <thead class="table-light"><tr>
        <th style="width:34px"><input type="checkbox" class="form-check-input" id="custAll"></th>
        <th>مشتری</th><th>سفارش‌ها</th><th>تیکت‌ها (هر خدمت)</th><th>وضعیت</th><th>آخرین تغییر</th>
      </tr></thead><tbody>
      <?php if (!$custRows): ?><tr><td colspan="6" class="text-center text-muted py-4">موردی نیست.</td></tr><?php endif; ?>
      <?php foreach ($custRows as $r):
        $__stc = ['done' => ['همه ارسال شده', 'success'], 'pending' => ['ارسال‌نشده', 'warning'], 'deleted' => ['حذف‌شده از آراد برندینگ', 'danger'], 'skipped' => ['ارسال نشود', 'secondary']][$r['state']]; ?>
        <tr>
          <td><input type="checkbox" class="form-check-input cust-cb" name="customer_ids[]" value="<?= (int) $r['customer_id'] ?>"></td>
          <td><a href="../customer_view.php?id=<?= (int) $r['customer_id'] ?>" target="_blank" class="fw-bold text-decoration-none"><?= e((string) ($r['full_name'] ?: '—')) ?></a><div class="text-muted" dir="ltr" style="text-align:right"><?= e((string) $r['mobile']) ?></div></td>
          <td class="text-muted"><?= e((string) $r['orders']) ?></td>
          <td>
            <?php $__lastOid = null; $__multi = count(array_unique(array_map(static fn($x) => explode('|', $x)[2] ?? '', explode('§', (string) $r['items'])))) > 1;
            // هشدار: چند سفارشِ تأییدشده با دقیقاً همان خدمات (احتمالِ ثبتِ تکراری)
            $__sets = [];
            foreach (explode('§', (string) $r['items']) as $__x) { $__p = explode('|', $__x); $__sets[$__p[5] ?? ($__p[2] ?? '')][] = $__p[1] ?? ''; }
            $__sig = [];
            foreach ($__sets as $__on => $__ts) { sort($__ts); $__sig[md5(implode('#', $__ts))][] = $__on; }
            foreach ($__sig as $__ons) if (count($__ons) > 1): ?>
              <div class="alert alert-warning py-1 px-2 small mb-1"><i class="fa-solid fa-triangle-exclamation"></i> سفارش‌های <?= e(to_persian_digits(implode('، ', $__ons))) ?> دقیقاً همان خدمات را دارند و هر دو/همه تأیید شده‌اند — احتمالاً سفارشِ تکراری است؛ پیش از ارسال بررسی کنید.</div>
            <?php endif;
            foreach (explode('§', (string) $r['items']) as $__it): [$__s, $__t, $__oid, $__xid, $__xurl, $__onum] = array_pad(explode('|', $__it, 6), 6, ''); $__m = $statuses[$__s] ?? ['label' => $__s, 'color' => 'light', 'icon' => 'fa-circle'];
              $__tl = abt_ticket_link(['external_id' => $__xid, 'external_url' => $__xurl]);
              if ($__multi && $__oid !== $__lastOid): $__lastOid = $__oid; ?>
                <div class="small fw-bold text-muted mt-1 mb-1"><i class="fa-solid fa-receipt"></i> سفارشِ <?= e(to_persian_digits($__onum)) ?>:</div>
              <?php endif; ?>
              <span class="d-inline-flex align-items-center gap-1 mb-1">
                <a href="../order_view.php?id=<?= (int) $__oid ?>#abt" target="_blank" class="badge text-bg-<?= $__m['color'] ?> text-decoration-none" title="<?= e($__m['label']) ?>"><i class="fa-solid <?= $__m['icon'] ?>"></i> <?= e(mb_strimwidth($__t, 0, 34, '…')) ?></a>
                <?php if ($__tl): ?><a href="<?= e($__tl) ?>" target="_blank" rel="noopener" class="small" title="مشاهده‌ی تیکتِ <?= e($__xid) ?> در آراد برندینگ"><i class="fa-solid fa-arrow-up-right-from-square"></i></a><?php endif; ?>
              </span>
            <?php endforeach; ?>
          </td>
          <td class="text-nowrap"><span class="badge text-bg-<?= $__stc[1] ?>"><?= $__stc[0] ?></span>
            <div class="text-muted mt-1"><?= to_persian_digits((string) (int) $r['sent']) ?> از <?= to_persian_digits((string) (int) $r['total']) ?> ارسال شده</div>
            <?php if ((int) $r['deleted'] > 0): ?><div class="text-danger mt-1"><?= to_persian_digits((string) (int) $r['deleted']) ?> حذف‌شده</div><?php endif; ?></td>
          <td class="text-nowrap text-muted"><?= $r['last_at'] ? to_jalali(substr((string) $r['last_at'], 0, 10)) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <?= abt_pager($totalRows, $perPage, $pageNo) ?>
  </form>
  <script>
  (function () {
    var all = document.getElementById('custAll'), out = document.getElementById('custSel');
    var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
    function upd() { out.textContent = fa(document.querySelectorAll('.cust-cb:checked').length); }
    if (all) all.addEventListener('change', function () { document.querySelectorAll('.cust-cb').forEach(function (c) { c.checked = all.checked; }); upd(); });
    document.querySelectorAll('.cust-cb').forEach(function (c) { c.addEventListener('change', upd); });
  })();
  </script>
<?php elseif ($tab === 'accounts'): ?>

  <div class="text-muted small mb-2">کسانی که آراد کانتکت برایشان در آراد برندینگ حسابِ کاربری (تاجر) ساخته است. اگر مشتری اطلاعاتِ ورود را گم کرد، از همین‌جا نام کاربری و رمز را کپی کنید و دوباره برایش بفرستید. ردیف‌هایی که هنوز «پیامک شد» نخورده‌اند بالاتر هستند.</div>
  <form method="get" class="d-flex gap-2 mb-2">
    <input type="hidden" name="tab" value="accounts"><input type="hidden" name="per" value="<?= $perPage ?>">
    <input name="aq" value="<?= e($aq) ?>" class="form-control form-control-sm" style="max-width:280px" placeholder="جستجوی نام، موبایل یا نام کاربری">
    <button class="btn btn-sm btn-outline-dark">جستجو</button>
  </form>
  <div class="card p-0"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
    <thead class="table-light"><tr><th>مشتری</th><th>نام کاربری</th><th>رمز عبور</th><th>سفارش</th><th>ساخته‌شده</th><th>اطلاع به مشتری</th><th></th></tr></thead>
    <tbody>
    <?php if (!$accRows): ?><tr><td colspan="7" class="text-center text-muted py-4">حسابی ساخته نشده است.</td></tr><?php endif; ?>
    <?php foreach ($accRows as $a):
      $__msg = 'سلام ' . trim((string) ($a['full_name'] ?? '')) . "\nاطلاعاتِ ورود به حسابِ شما در آراد برندینگ:\nآدرس ورود: " . $accLoginUrl
        . "\nنام کاربری: " . $a['username'] . "\nرمز عبور: " . ($a['password'] ?: '—') . "\nبا سپاس\nآراد برندینگ"; ?>
      <tr>
        <td><a href="../customer_view.php?id=<?= (int) $a['customer_id'] ?>" target="_blank" class="fw-bold text-decoration-none"><?= e((string) ($a['full_name'] ?: '—')) ?></a><div class="text-muted" dir="ltr" style="text-align:right"><?= e((string) $a['mobile']) ?></div></td>
        <td dir="ltr" class="text-end"><code><?= e((string) $a['username']) ?></code></td>
        <td dir="ltr" class="text-end text-nowrap">
          <code class="acc-pass" data-pass="<?= e((string) $a['password']) ?>">••••••</code>
          <button type="button" class="btn btn-link btn-sm p-0 acc-show" title="نمایش رمز"><i class="fa-solid fa-eye"></i></button>
        </td>
        <td><?php if (!empty($a['order_id'])): ?><a href="../order_view.php?id=<?= (int) $a['order_id'] ?>#abt-account" target="_blank"><bdi dir="ltr"><?= e(to_persian_digits((string) ($a['order_number'] ?: '#' . $a['order_id']))) ?></bdi></a><?php else: ?>—<?php endif; ?></td>
        <td class="text-nowrap text-muted"><?= to_jalali(substr((string) $a['created_at'], 0, 10)) ?><div><?= e((string) ($a['creator_name'] ?? '')) ?></div>
          <?php if (!empty($a['welcome_ticket_id'])): ?><div class="text-success">تیکتِ اطلاعاتِ حساب: <?= e((string) $a['welcome_ticket_id']) ?></div>
          <?php elseif (!empty($a['welcome_error'])): ?><div class="text-danger" title="<?= e((string) $a['welcome_error']) ?>">تیکتِ اطلاعاتِ حساب نرفت</div><?php endif; ?></td>
        <td class="text-nowrap"><?php if ($a['sms_done_at']): ?><span class="badge text-bg-success">پیامک شد</span><div class="text-muted"><?= to_jalali(substr((string) $a['sms_done_at'], 0, 10)) ?> — <?= e((string) ($a['sms_name'] ?? '')) ?></div>
          <?php else: ?><span class="badge text-bg-warning">هنوز اطلاع داده نشده</span><?php endif; ?></td>
        <td class="text-nowrap">
          <button type="button" class="btn btn-sm btn-outline-dark acc-copy" data-msg="<?= e($__msg) ?>"><i class="fa-regular fa-copy"></i> کپیِ پیامِ ورود</button>
          <?php if ($canSend && !$a['sms_done_at']): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="acc_sms_done"><input type="hidden" name="customer_id" value="<?= (int) $a['customer_id'] ?>"><input type="hidden" name="aq" value="<?= e($aq) ?>">
              <button class="btn btn-sm btn-outline-success"><i class="fa-solid fa-check"></i> پیامک شد</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <?= abt_pager($totalRows, $perPage, $pageNo) ?>
  <script>
  document.querySelectorAll('.acc-show').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = b.parentNode.querySelector('.acc-pass');
      var shown = c.dataset.shown === '1';
      c.textContent = shown ? '••••••' : (c.dataset.pass || '—');
      c.dataset.shown = shown ? '0' : '1';
    });
  });
  document.querySelectorAll('.acc-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.dataset.msg, done = function () { var h = b.innerHTML; b.innerHTML = '<i class="fa-solid fa-check"></i> کپی شد'; setTimeout(function () { b.innerHTML = h; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, function () { window.prompt('کپی کنید:', t); });
      else window.prompt('کپی کنید:', t);
    });
  });
  </script>

<?php else: ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h6 class="fw-bold mb-0"><i class="fa-solid fa-clock-rotate-left"></i> تیکت‌های سفارش‌ها</h6>
      <div class="d-flex gap-1 flex-wrap">
        <a class="chip <?= $filter === '' ? 'active' : '' ?>" href="admin_aradbranding_send.php?tab=list">همه (<?= to_persian_digits((string) $countAll) ?>)</a>
        <?php foreach ($statuses as $k => $meta): ?>
          <a class="chip <?= $filter === $k ? 'active' : '' ?>" href="?tab=list&amp;per=<?= $perPage ?>&amp;status=<?= e($k) ?>"><?= e($meta['label']) ?> (<?= to_persian_digits((string) ($counts[$k] ?? 0)) ?>)</a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($ready && $canSend): ?>
    <form method="post" class="mb-2" onsubmit="return confirm('تا ۲۵ تیکتِ «آماده‌ی ارسال» و «ناموفق» (قدیمی‌ترین اول) برای مشتری‌ها در آراد برندینگ ثبت شود؟');">
      <?= csrf_field() ?><input type="hidden" name="action" value="send_queued">
      <button class="btn btn-sm btn-success"><i class="fa-solid fa-paper-plane"></i> ارسالِ گروهیِ تیکت‌های آماده/ناموفق (۲۵تا ۲۵تا)</button>
    </form>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th>سفارش</th><th>مشتری</th><th>خدمت / واحد</th><th>موضوع</th><th>وضعیت</th><th>شماره تیکت</th><th>زمان</th><th></th></tr></thead>
        <tbody>
        <?php if (!$tickets): ?><tr><td colspan="8" class="text-center text-muted py-3">تیکتی نیست.</td></tr><?php endif; ?>
        <?php foreach ($tickets as $t): $m = $statuses[$t['status']] ?? ['label' => $t['status'], 'color' => 'secondary']; ?>
          <tr>
            <td><bdi dir="ltr"><?= e(to_persian_digits((string) ($t['order_number'] ?? '#' . $t['order_id']))) ?></bdi></td>
            <td><?= e((string) ($t['customer_name'] ?? '')) ?></td>
            <td class="small"><?= e((string) ($t['service_title'] ?? '—')) ?><?= !empty($t['department']) ? '<div class="text-muted">' . e((string) $t['department']) . '</div>' : '' ?></td>
            <td><?= e(mb_strimwidth((string) $t['subject'], 0, 60, '…')) ?></td>
            <td><span class="badge text-bg-<?= e($m['color']) ?>"><?= e($m['label']) ?></span>
              <?php if ($t['status'] === 'failed' && $t['last_error']): ?><div class="text-danger" style="font-size:11px"><?= e(mb_strimwidth((string) $t['last_error'], 0, 90, '…')) ?></div><?php endif; ?></td>
            <td dir="ltr"><?php if ($__tl = abt_ticket_link($t)): ?><a href="<?= e($__tl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e((string) ($t['external_id'] ?: 'مشاهده')) ?></a><?php else: ?><?= e((string) ($t['external_id'] ?? '')) ?><?php endif; ?></td>
            <td class="text-nowrap"><?= to_jalali(substr((string) ($t['sent_at'] ?: $t['created_at']), 0, 10)) ?> <span class="text-muted"><?= e(to_persian_digits(substr((string) ($t['sent_at'] ?: $t['created_at']), 11, 5))) ?></span></td>
            <td><a class="btn btn-sm btn-outline-primary py-0" href="../order_view.php?id=<?= (int) $t['order_id'] ?>#abt">سفارش</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= abt_pager($totalRows, $perPage, $pageNo) ?>
  </div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
