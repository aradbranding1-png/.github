<?php
/** عملیاتِ مالکیتِ مشتری (POST): ارجاع A→Box B، B→Box C، احیای C→Box A، ورود به Box A، ثبت/جایگزینی/حذفِ دستیِ جایگاه (مدیر) */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/customer_credit.php';
require_once __DIR__ . '/includes/performance_functions.php';
$cid = (int) ($_POST['customer_id'] ?? 0);
$back = 'customer_view.php?id=' . $cid . '#ps-ownership';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$cid || !csrf_verify() || !perf_ready($pdo)) { flash_set('danger', 'درخواست نامعتبر است.'); redirect($back); }
$uid = (int) $user['id'];
$own = ps_owners($pdo, $cid);
$isA = $own['A'] && (int) $own['A']['user_id'] === $uid;
$isB = !empty($own['B']) && (int) $own['B'][0]['user_id'] === $uid;
$manage = perf_can('owners', $user) || perf_can('box_manage', $user);
$a = (string) ($_POST['action'] ?? '');
try {
    if ($a === 'refer_b') {
        if (!$isA && !$manage) throw new RuntimeException('فقط A همین مشتری می‌تواند به Box B ارجاع دهد.');
        $r = ps_box_add($pdo, $cid, 'B', $uid, $isA ? 'refer_a' : 'manual', trim((string) ($_POST['note'] ?? '')));
    } elseif ($a === 'refer_c') {
        if (!$isB && !$manage) throw new RuntimeException('فقط B همین مشتری می‌تواند به Box C ارجاع دهد.');
        $r = ps_box_add($pdo, $cid, 'C', $uid, $isB ? 'refer_b' : 'manual', trim((string) ($_POST['note'] ?? '')));
    } elseif ($a === 'c_revive') {
        // کارشناسِ C: مشتریِ خودش ← Box A (فقط یک بار برای هر مشتری؛ شرط‌ها در ps_c_revive_check)
        $r = ps_c_revive_to_box_a($pdo, $cid, $user);
    } elseif ($a === 'to_box_a' && $manage) {
        $r = ps_box_add($pdo, $cid, 'A', $uid, 'manual');
    } elseif ($a === 'owner_set' && perf_can('owners', $user)) {
        $slot = (string) ($_POST['slot'] ?? '');
        if ($slot === 'B1') $slot = 'B';
        $tu = (int) ($_POST['user_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!in_array($slot, ['A', 'B', 'C'], true) || $tu <= 0) throw new RuntimeException('جایگاه و کارشناس را انتخاب کنید.');
        if (mb_strlen($note) < 3) throw new RuntimeException('دلیلِ تعیین/اصلاحِ جایگاه را بنویسید.');
        $r = ps_owner_set($pdo, $cid, $slot, $tu, 'manual', $uid, $note);
        if ($r['ok'] && !empty($r['changed'])) {
            ps_activity($pdo, $cid, $uid, 'تعیینِ دستیِ مالکیت: ' . $r['message'] . ' — دلیل: ' . $note);
            perf_audit($pdo, $uid, 'owner_note', 'ps_owners', $own['person_key'], null, ['slot' => $slot, 'user_id' => $tu], $note);
        }
    } elseif ($a === 'owner_del' && perf_can('owners', $user)) {
        $r = ps_owner_remove($pdo, (int) ($_POST['owner_id'] ?? 0), $uid, (string) ($_POST['reason'] ?? ''));
    } else {
        throw new RuntimeException('اجازه‌ی این کار را ندارید.');
    }
    flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
} catch (Throwable $e) {
    flash_set('danger', $e->getMessage());
}
redirect($back);
