
<style id="luxury-top-status-fix">
/* سه وضعیت بالای نامه: عادی / عادی / ارسال شده */
.alv-page .badge{
  font-size:12px!important;
  line-height:1.25!important;
  min-height:29px;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  padding:5px 12px!important;
  border-radius:9px!important;
  border:1px solid rgba(185,145,78,.22)!important;
  box-shadow:0 2px 7px rgba(90,65,35,.08)!important;
  vertical-align:middle!important;
  white-space:nowrap!important;
}

/* وضعیت «عادی» */
.alv-page .badge.bg-light{
  color:#796b5e!important;
  background:linear-gradient(135deg,#fffdf9,#f4eee6)!important;
  border-color:#dfd1c1!important;
}

/* وضعیت «ارسال شده» */
.alv-page .badge.bg-dark{
  color:#fff!important;
  background:linear-gradient(135deg,#4a4643,#655b54)!important;
  border-color:#8f7861!important;
  box-shadow:0 3px 9px rgba(55,45,38,.16)!important;
}

/* فاصله و چینش خود ردیف وضعیت‌ها */
.alv-page .card .d-flex.align-items-center.gap-2{
  gap:7px!important;
  margin-bottom:8px!important;
  flex-wrap:wrap!important;
}

/* عنوان نامه را از Badgeها کمی جدا می‌کنیم */
.alv-page .card .d-flex.align-items-center.gap-2 + h5,
.alv-page .card .d-flex.align-items-center.gap-2 + h4{
  margin-top:8px!important;
}
</style>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/automation_functions.php';
require_once __DIR__ . '/includes/services_functions.php';
$user = require_login();
$pdo  = db();

if (!automation_ready($pdo)) {
    $pageTitle = 'اتوماسیون';
    require_once __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning">ماژولِ اتوماسیون هنوز روی این سایت نصب نشده.</div>';
    require_once __DIR__ . '/includes/layout_bottom.php';
    exit;
}
automation_require_permission($pdo, $user, 'letter_view');

$myId = (int) $user['id'];
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT l.*, u.full_name AS sender_name, su.title AS sender_unit_title
    FROM letters l
    JOIN users u ON u.id = l.sender_user_id
    LEFT JOIN automation_org_units su ON su.id = l.sender_unit_id
    WHERE l.id = ? LIMIT 1');
$stmt->execute([$id]);
$letter = $stmt->fetch();
if (!$letter) {
    http_response_code(404);
    die('نامه یافت نشد.');
}

$canViewAll = automation_user_has_permission($pdo, $user, 'letter_view_all');
$myLru = null;
$lruStmt = $pdo->prepare('SELECT * FROM letter_recipient_users WHERE letter_id = ? AND user_id = ? LIMIT 1');
$lruStmt->execute([$id, $myId]);
$myLru = $lruStmt->fetch() ?: null;
$isSender = (int) $letter['sender_user_id'] === $myId;
$canSeeLetter = $isSender || $myLru || $canViewAll;
if (!$canSeeLetter) {
    http_response_code(403);
    die('دسترسیِ مشاهده‌یِ این نامه را ندارید.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است.');
        redirect('automation_letter_view.php?id=' . $id);
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'decide') {
        automation_require_permission($pdo, $user, 'letter_' . ($_POST['decision'] === 'approve' ? 'approve' : ($_POST['decision'] === 'reject' ? 'reject' : 'return')));
        $stepId = (int) ($_POST['step_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $note = trim((string) ($_POST['note'] ?? ''));
        try {
            $ok = automation_decide_approval_step($pdo, $stepId, $myId, $decision, $note);
            flash_set($ok ? 'success' : 'danger', $ok ? 'تصمیمِ شما ثبت شد.' : 'این مرحله قابلِ تصمیم‌گیری توسطِ شما نیست.');
        } catch (Throwable $e) {
            flash_set('danger', 'خطا در ثبتِ تصمیم.');
        }
        redirect('automation_letter_view.php?id=' . $id);
    }

    if ($action === 'refer') {
        automation_require_permission($pdo, $user, 'letter_forward');
        $toKind = $_POST['to_kind'] ?? 'user';
        $toId = (int) ($_POST['to_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $deadlineJ = trim((string) ($_POST['deadline_at'] ?? ''));
        $deadlineG = $deadlineJ !== '' ? to_gregorian($deadlineJ) : null;
        $priority = $_POST['priority'] ?? 'عادی';
        // ارجاع به کاربرِ «دبیرخانه ریاست» ← به خودِ واحدِ دبیرخانه
        if ($toKind === 'user' && $toId > 0 && automation_is_secretariat_target($pdo, 'user', $toId) && automation_secretariat_unit_id($pdo)) {
            $toKind = 'unit';
            $toId = automation_secretariat_unit_id($pdo);
        }
        if ($toId > 0) {
            $pdo->prepare('INSERT INTO letter_referrals (letter_id, referred_by_user_id, referred_to_user_id, referred_to_unit_id, note, deadline_at, priority) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id, $myId, $toKind === 'user' ? $toId : null, $toKind === 'unit' ? $toId : null, $note !== '' ? $note : null, $deadlineG, $priority]);
            // ارجاع، دسترسیِ مشاهده هم می‌ده (اگه گیرنده‌یِ اصلی نبوده)
            if ($toKind === 'user') {
                $pdo->prepare("INSERT IGNORE INTO letter_recipient_users (letter_id, user_id, role, status) VALUES (?,?,'to','ارسال شده')")->execute([$id, $toId]);
            } else {
                $memberIds = automation_resolve_unit_recipients($pdo, $toId, 'all_members');
                $ins = $pdo->prepare("INSERT IGNORE INTO letter_recipient_users (letter_id, user_id, role, status) VALUES (?,?,'to','ارسال شده')");
                foreach ($memberIds as $mid) { $ins->execute([$id, $mid]); }
            }
            automation_log_history($pdo, $id, $myId, 'referred', 'نامه ارجاع داده شد.' . ($note !== '' ? ' یادداشت: ' . $note : ''));
            automation_audit($pdo, $myId, 'letter_refer', 'letter', $id);
            flash_set('success', 'نامه ارجاع داده شد.');
        } else {
            flash_set('danger', 'گیرنده‌یِ ارجاع معتبر نیست.');
        }
        redirect('automation_letter_view.php?id=' . $id);
    }

    if ($action === 'referral_done') {
        $refId = (int) ($_POST['referral_id'] ?? 0);
        $pdo->prepare("UPDATE letter_referrals SET status = 'انجام شد' WHERE id = ? AND referred_to_user_id = ?")->execute([$refId, $myId]);
        flash_set('success', 'ارجاع، انجام‌شده علامت خورد.');
        redirect('automation_letter_view.php?id=' . $id);
    }

    if ($action === 'archive') {
        automation_require_permission($pdo, $user, 'letter_archive');
        $pdo->prepare("UPDATE letters SET status = 'بایگانی شده' WHERE id = ?")->execute([$id]);
        automation_log_history($pdo, $id, $myId, 'archived', 'نامه بایگانی شد.');
        flash_set('success', 'نامه بایگانی شد.');
        redirect('automation_letter_view.php?id=' . $id);
    }

    if ($action === 'close') {
        $pdo->prepare("UPDATE letters SET status = 'بسته شده', closed_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE letter_recipient_users SET status = 'بسته شده' WHERE letter_id = ?")->execute([$id]);
        automation_log_history($pdo, $id, $myId, 'closed', 'نامه بسته شد.');
        flash_set('success', 'نامه بسته شد.');
        redirect('automation_letter_view.php?id=' . $id);
    }
}

// -------- مطالعه = خوانده‌شده (اگه من گیرنده‌ام) --------
if ($myLru) {
    automation_mark_read($pdo, $id, $myId);
}

// -------- داده‌های نمایشی --------
$recipientsStmt = $pdo->prepare("SELECT lru.*, u.full_name FROM letter_recipient_users lru JOIN users u ON u.id = lru.user_id WHERE lru.letter_id = ? ORDER BY lru.role ASC, u.full_name ASC");
$recipientsStmt->execute([$id]);
$allRecipients = $recipientsStmt->fetchAll();
$toRecipients = array_filter($allRecipients, fn($r) => $r['role'] === 'to');
$ccRecipients = array_filter($allRecipients, fn($r) => $r['role'] === 'cc');

// -------- برندینگِ نامه از همان تنظیمات پیش‌فاکتور --------
$invoiceSettings = [];
try {
    $invoiceSettings = get_invoice_settings($pdo) ?: [];
} catch (Throwable $e) {
    $invoiceSettings = [];
}
$invoiceLogoPath = trim((string) ($invoiceSettings['logo_path'] ?? ''));
$invoiceCompanyName = trim((string) ($invoiceSettings['company_name'] ?? ''));
if ($invoiceCompanyName === '') {
    $invoiceCompanyName = 'آراد برندینگ';
}
$invoiceCompanyDetails = trim((string) ($invoiceSettings['company_details'] ?? ''));

// استخراج واحد مقصد؛ هم مقصدِ واحدی و هم واحدِ گیرندگان مستقیم را در نظر می‌گیرد.
$recipientUnits = [];
try {
    $ruStmt = $pdo->prepare("SELECT DISTINCT un.title
        FROM letter_recipients lr
        JOIN automation_org_units un ON un.id = lr.unit_id
        WHERE lr.letter_id = ? AND lr.role = 'to' AND lr.recipient_kind = 'unit'
        ORDER BY un.title ASC");
    $ruStmt->execute([$id]);
    foreach ($ruStmt->fetchAll(PDO::FETCH_COLUMN) as $unitTitle) {
        $unitTitle = trim((string)$unitTitle);
        if ($unitTitle !== '') {
            $recipientUnits[] = $unitTitle;
        }
    }

    try {
        $lvStmt = $pdo->prepare("SELECT DISTINCT l.title FROM letter_recipients lr JOIN automation_org_levels l ON l.id = lr.level_id
            WHERE lr.letter_id = ? AND lr.role = 'to' AND lr.recipient_kind = 'level'");
        $lvStmt->execute([$id]);
        foreach ($lvStmt->fetchAll(PDO::FETCH_COLUMN) as $lvTitle) $recipientUnits[] = 'رده: ' . trim((string) $lvTitle);
    } catch (Throwable $e) {}

    $directRuStmt = $pdo->prepare("SELECT DISTINCT un.title
        FROM letter_recipient_users lru
        JOIN automation_user_positions p ON p.user_id = lru.user_id" . (automation_multi_unit_ready($pdo) ? " AND p.is_primary = 1" : "") . "
        JOIN automation_org_units un ON un.id = p.unit_id
        WHERE lru.letter_id = ? AND lru.role = 'to'
        ORDER BY un.title ASC");
    $directRuStmt->execute([$id]);
    foreach ($directRuStmt->fetchAll(PDO::FETCH_COLUMN) as $unitTitle) {
        $unitTitle = trim((string)$unitTitle);
        if ($unitTitle !== '' && !in_array($unitTitle, $recipientUnits, true)) {
            $recipientUnits[] = $unitTitle;
        }
    }
} catch (Throwable $e) {
    $recipientUnits = [];
}
$recipientUnitsLabel = $recipientUnits ? implode('، ', $recipientUnits) : '—';

$pathsStmt = $pdo->prepare('SELECT ldp.*, u.full_name AS recipient_name FROM letter_delivery_paths ldp JOIN users u ON u.id = ldp.recipient_user_id WHERE ldp.letter_id = ? ORDER BY ldp.id');
$pathsStmt->execute([$id]);
$paths = $pathsStmt->fetchAll();
$stepsByPath = [];
if ($paths) {
    $pathIds = array_column($paths, 'id');
    $ph = implode(',', array_fill(0, count($pathIds), '?'));
    $stepsStmt = $pdo->prepare("SELECT las.*, u.full_name FROM letter_approval_steps las JOIN users u ON u.id = las.approver_user_id WHERE las.delivery_path_id IN ($ph) ORDER BY las.step_order ASC");
    $stepsStmt->execute($pathIds);
    foreach ($stepsStmt->fetchAll() as $s) {
        $stepsByPath[$s['delivery_path_id']][] = $s;
    }
}

$historyStmt = $pdo->prepare('SELECT h.*, u.full_name FROM letter_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.letter_id = ? ORDER BY h.created_at ASC');
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();

$referralsStmt = $pdo->prepare('SELECT r.*, ub.full_name AS by_name, ut.full_name AS to_name, un.title AS to_unit_title FROM letter_referrals r
    JOIN users ub ON ub.id = r.referred_by_user_id
    LEFT JOIN users ut ON ut.id = r.referred_to_user_id
    LEFT JOIN automation_org_units un ON un.id = r.referred_to_unit_id
    WHERE r.letter_id = ? ORDER BY r.created_at ASC');
$referralsStmt->execute([$id]);
$referrals = $referralsStmt->fetchAll();

// -------- نامِ فرستنده پنهان (انتخابِ فرستنده / پیش‌فرضِ «مدیران عالی») ← نامِ واحدِ فرستنده، بدونِ نام و سمتِ فرد --------
if ($__hs = automation_letter_hidden_sender($pdo, $id)) {
    $letter['sender_name'] = $__hs['label'];
    $letter['sender_position_title'] = null;
}

// -------- دبیرخانه ریاست: نامِ نیرویی که نامه را برمی‌دارد برای دیگران پنهان می‌ماند (نامِ واحد نمایش داده می‌شود) --------
$__dn = static fn($uid, $name) => automation_display_name($pdo, $uid !== null ? (int) $uid : null, $name !== null ? (string) $name : null, $user);
if (!automation_can_see_hidden_names($pdo, $user) && automation_letter_involves_secretariat($pdo, $id)) {
    $__hid = automation_hidden_member_map($pdo);
    if (isset($__hid[(int) $letter['sender_user_id']])) {
        $letter['sender_name'] = $__hid[(int) $letter['sender_user_id']];
        $letter['sender_position_title'] = null;
    }
    foreach ($allRecipients as &$__r) $__r['full_name'] = $__dn($__r['user_id'], $__r['full_name']);
    unset($__r);
    foreach ($paths as &$__p) $__p['recipient_name'] = $__dn($__p['recipient_user_id'], $__p['recipient_name']);
    unset($__p);
    foreach ($stepsByPath as &$__ss) foreach ($__ss as &$__s) { if (isset($__hid[(int) $__s['approver_user_id']])) { $__s['full_name'] = $__hid[(int) $__s['approver_user_id']]; $__s['snapshot_position_title'] = ''; $__s['snapshot_unit_title'] = ''; } }
    unset($__ss, $__s);
    foreach ($history as &$__h) if ($__h['user_id']) $__h['full_name'] = $__dn($__h['user_id'], $__h['full_name']);
    unset($__h);
    foreach ($referrals as &$__f) { $__f['by_name'] = $__dn($__f['referred_by_user_id'], $__f['by_name']); if ($__f['referred_to_user_id']) $__f['to_name'] = $__dn($__f['referred_to_user_id'], $__f['to_name']); }
    unset($__f);
}
// گیرندگان/رونوشت‌ها بر اساسِ انتخابِ خام: واحد ← نامِ واحد (نه نامِ تک‌تکِ اعضا)
$recipientDisplay = ['to' => [], 'cc' => []];
try { $recipientDisplay = automation_letter_recipient_display($pdo, $id, $user); } catch (Throwable $e) {
    foreach ($allRecipients as $__r) $recipientDisplay[$__r['role'] === 'cc' ? 'cc' : 'to'][] = ['label' => $__r['full_name'], 'status' => $__r['status'], 'kind' => 'user'];
}
$secretariatNotice = false;
if (!empty($_SESSION['automation_secretariat_notice']) && (int) $_SESSION['automation_secretariat_notice'] === $id) {
    $secretariatNotice = true;
    unset($_SESSION['automation_secretariat_notice']);
}

$attachStmt = $pdo->prepare('SELECT * FROM letter_attachments WHERE letter_id = ? ORDER BY created_at ASC');
$attachStmt->execute([$id]);
$attachments = $attachStmt->fetchAll();

$repliesStmt = $pdo->prepare("SELECT l.*, u.full_name AS sender_name FROM letters l JOIN users u ON u.id = l.sender_user_id WHERE l.parent_letter_id = ? ORDER BY l.created_at ASC");
$repliesStmt->execute([$id]);
$replies = $repliesStmt->fetchAll();
foreach ($replies as &$__rp) $__rp['sender_name'] = automation_display_name($pdo, (int) $__rp['sender_user_id'], (string) $__rp['sender_name'], $user, (int) $__rp['id']);
unset($__rp);

// مرحله‌یِ در انتظارِ منِ کاربرِ جاری (اگه هست)
$myPendingStep = null;
foreach ($paths as $p) {
    foreach (($stepsByPath[$p['id']] ?? []) as $s) {
        if ((int) $s['approver_user_id'] === $myId && $s['status'] === 'در انتظار تایید') {
            $myPendingStep = $s;
            break 2;
        }
    }
}

$canApprove = automation_user_has_permission($pdo, $user, 'letter_approve');
$canForward = automation_user_has_permission($pdo, $user, 'letter_forward');
$canReply = automation_user_has_permission($pdo, $user, 'letter_reply');
$canArchive = automation_user_has_permission($pdo, $user, 'letter_archive');
$canViewAttachment = automation_user_has_permission($pdo, $user, 'letter_view_attachment');

$priorityBadge = ['عادی' => 'secondary', 'مهم' => 'warning', 'فوری' => 'danger', 'خیلی فوری' => 'dark'];

$pageTitle = 'جزئیات نامه';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
/* =========================================================
   صفحه جزئیات نامه — بدون تغییر در ظاهر اصلی سایت
   تمام استایل‌های فرم چاپ فقط داخل @media print هستند.
   ========================================================= */
.alv-page{--alv-line:#e7e2d3;}

/* فقط ظاهر دکمه چاپ؛ سایر اجزای صفحه دست‌نخورده می‌مانند. */
.alv-print-btn{
  border:1px solid #c9a24b!important;
  color:#6b531c!important;
  background:#fffaf0!important;
  border-radius:8px!important;
  font-weight:700!important;
}
.alv-print-btn:hover{
  background:#f8edcf!important;
  color:#4b3a12!important;
}


/* =========================================================
   Luxury reading interface — screen only
   چاپ و تنظیمات چاپ عمداً خارج از این بخش و بدون تغییر است.
   ========================================================= */
.alv-page{
  --alv-gold:#c9a15a;
  --alv-gold-soft:#ead8b4;
  --alv-rose:#c98a8d;
  --alv-rose-soft:#f3dfe0;
  --alv-ink:#403a37;
  --alv-muted:#877b73;
  --alv-border:#eadfd2;
}

/* فاصله کلی و تنفس صفحه */
.alv-page{
  padding-top:.15rem;
}
.alv-page > .d-flex.align-items-center.justify-content-between{
  margin-bottom:1.35rem!important;
  padding:0 .15rem;
}

/* دکمه‌ها: لوکس، ظریف و یکدست */
.alv-page .btn{
  border-radius:10px!important;
  font-weight:700!important;
  transition:transform .18s ease,box-shadow .18s ease,background .18s ease;
}
.alv-page .btn:hover{
  transform:translateY(-1px);
}
.alv-page .btn-primary{
  border:0!important;
  background:linear-gradient(135deg,#b88a43 0%,#d4ad69 48%,#c98588 100%)!important;
  box-shadow:0 5px 14px rgba(171,124,66,.17)!important;
}
.alv-page .btn-primary:hover{
  box-shadow:0 7px 18px rgba(171,124,66,.23)!important;
}
.alv-page .btn-outline-secondary{
  color:#665d57!important;
  border-color:#dccbb8!important;
  background:linear-gradient(135deg,#fffefa,#faf5ef)!important;
}
.alv-page .btn-outline-secondary:hover{
  color:#8d5e61!important;
  border-color:#c98a8d!important;
}
.alv-page .btn-outline-danger{
  color:#9b5c61!important;
  border-color:#dfb7b9!important;
  background:#fff9f9!important;
}
.alv-page .btn-outline-warning{
  color:#96702f!important;
  border-color:#e0c88f!important;
  background:#fffaf1!important;
}
.alv-page .btn-outline-success{
  color:#5f7969!important;
  border-color:#b9ccbd!important;
  background:#f8fcf9!important;
}
.alv-page .btn-success{
  border:0!important;
  background:linear-gradient(135deg,#879a80,#667b6e)!important;
}

/* کارت‌ها: قاب بسیار ظریف با خط طلایی/رزگلد */
.alv-page .card{
  position:relative;
  border:1px solid var(--alv-border)!important;
  border-radius:17px!important;
  background:
    radial-gradient(circle at 100% 0%,rgba(201,138,141,.055),transparent 27%),
    linear-gradient(145deg,#fffefd 0%,#fffaf6 100%)!important;
  box-shadow:0 8px 25px rgba(72,53,36,.065),0 1px 3px rgba(72,53,36,.035)!important;
}
.alv-page .card::before{
  content:"";
  position:absolute;
  top:0;
  right:24px;
  left:24px;
  height:2px;
  border-radius:0 0 8px 8px;
  background:linear-gradient(90deg,transparent,#d0a35b,#cf8b8e,#d0a35b,transparent);
  opacity:.72;
  pointer-events:none;
}

/* کارت اصلی نامه */
.alv-page .col-lg-8 > .card:first-child{
  padding:1.35rem 1.5rem!important;
}
.alv-page h5,
.alv-page h6{
  color:var(--alv-ink)!important;
  font-weight:800!important;
  letter-spacing:-.15px;
}

/* عنوان نامه خواناتر و جمع‌وجورتر */
.alv-page .col-lg-8 > .card:first-child h5{
  font-size:1.02rem!important;
  line-height:1.7!important;
}

/* اطلاعات بالای نامه */
.alv-page .col-lg-8 > .card:first-child .text-muted.small{
  font-size:.73rem!important;
  line-height:1.95!important;
  color:#877b73!important;
  margin-top:.35rem!important;
}

/* متن نامه — ریزتر، مرتب‌تر و کاملاً Justify */
.alv-page .col-lg-8 > .card:first-child > div[style*="white-space"]{
  margin-top:1.15rem!important;
  margin-bottom:1.05rem!important;
  padding:1.15rem 1.2rem!important;
  border:1px solid #eee4da!important;
  border-radius:13px!important;
  background:linear-gradient(180deg,rgba(255,255,255,.78),rgba(252,247,242,.58))!important;
  color:#3f3a37!important;
  font-size:.83rem!important;
  line-height:2.05!important;
  text-align:justify!important;
  text-justify:inter-word!important;
  direction:rtl!important;
  white-space:pre-wrap!important;
  overflow-wrap:anywhere!important;
  word-break:normal!important;
}

/* متن نامه در صفحه جزئیات: همان Markdown ساده‌ی بخش چاپ، بدون نمایش هشتگ‌ها */
.alv-page .col-lg-8 > .card:first-child .alv-screen-body{
  margin-top:1.15rem!important;
  margin-bottom:1.05rem!important;
  padding:1.15rem 1.2rem!important;
  border:1px solid #eee4da!important;
  border-radius:13px!important;
  background:linear-gradient(180deg,rgba(255,255,255,.78),rgba(252,247,242,.58))!important;
  color:#3f3a37!important;
  font-size:.83rem!important;
  line-height:2.05!important;
  text-align:justify!important;
  text-justify:inter-word!important;
  direction:rtl!important;
  overflow-wrap:anywhere!important;
  word-break:normal!important;
}
.alv-page .col-lg-8 > .card:first-child .alv-screen-body .alv-screen-markdown-heading{
  display:inline-block!important;
  font-weight:800!important;
  color:#3f3a37!important;
}

/* فاصله اینترهای متن در صفحه جزئیات: جمع‌وجور و کنترل‌شده */
.alv-page .col-lg-8 > .card:first-child .alv-screen-body .alv-screen-gap{
  display:block!important;
  height:.38rem!important;
  line-height:0!important;
}

/* فاصله پاراگراف‌ها در متن اصلی بدون درشت‌کردن صفحه */
.alv-page .col-lg-8 > .card:first-child > div[style*="white-space"] br + br{
  line-height:.75!important;
}

/* توضیحات */
.alv-page .col-lg-8 > .card:first-child .text-muted.small.border-top{
  font-size:.72rem!important;
  line-height:1.9!important;
  padding-top:.8rem!important;
  color:#8b7f77!important;
}

/* Badgeها */
.alv-page .badge{
  border-radius:8px!important;
  padding:.36em .62em!important;
  font-size:.68rem!important;
  font-weight:700!important;
}
.alv-page .badge.bg-secondary{
  background:linear-gradient(135deg,#d8c5b1,#bda996)!important;
}
.alv-page .badge.bg-dark{
  background:linear-gradient(135deg,#57514d,#383432)!important;
}
.alv-page .badge.bg-warning{
  color:#70531c!important;
  background:#f0d9a4!important;
}
.alv-page .badge.bg-danger{
  background:#dba1a4!important;
}

/* بخش‌های پایینی صفحه */
.alv-page .col-lg-8 > .card:not(:first-child),
.alv-page .col-lg-4 > .card{
  margin-bottom:1.05rem!important;
}
.alv-page .col-lg-8 > .card:not(:first-child),
.alv-page .col-lg-4 > .card{
  padding:1rem 1.15rem!important;
}

/* تیترهای فرعی */
.alv-page .card h6,
.alv-page .card h5{
  line-height:1.75!important;
}
.alv-page .card .small,
.alv-page .card small{
  font-size:.72rem!important;
  line-height:1.85!important;
}

/* ورودی‌ها */
.alv-page .form-control,
.alv-page .form-select{
  border:1px solid #e1d3c5!important;
  border-radius:10px!important;
  background:#fffdfb!important;
  color:#443e3a!important;
  font-size:.76rem!important;
}
.alv-page .form-control:focus,
.alv-page .form-select:focus{
  border-color:#d09a73!important;
  box-shadow:0 0 0 .18rem rgba(201,138,141,.10)!important;
}

/* کادر جستجوی ارجاع */
#referResults{
  margin-top:5px!important;
  padding:4px!important;
  border:1px solid #dfcdb8!important;
  border-radius:12px!important;
  background:#fffdfb!important;
  box-shadow:0 12px 28px rgba(55,40,25,.12)!important;
  overflow:hidden!important;
}
#referResults > div{
  border-bottom:1px solid #f0e6dc!important;
  border-radius:8px!important;
}
#referResults > div:hover{
  background:linear-gradient(90deg,#fff8f4,#faeeee)!important;
}

/* خطوط جداکننده و متن کم‌رنگ */
.alv-page .text-muted{color:#857a72!important;}
.alv-page .border-top{border-color:#eee3d9!important;}

/* فاصله بین دکمه‌ها و تب/پنجره‌ها */
.alv-page .d-flex.gap-2,
.alv-page .d-flex.flex-wrap.gap-2{
  gap:.55rem!important;
}
.alv-page .card + .card{
  margin-top:.9rem!important;
}

/* ریسپانسیو */
@media (max-width:991.98px){
  .alv-page .col-lg-8 > .card:first-child{
    padding:1rem!important;
  }
  .alv-page .col-lg-8 > .card:first-child > div[style*="white-space"]{
    padding:1rem!important;
    font-size:.8rem!important;
  }
}

/* =========================================================
   نسخه مخصوص چاپ — در نمایش عادی کاملاً مخفی است.
   ========================================================= */
.alv-print-only{display:none;}

@media print{
  @page{size:A4 portrait;margin:10mm 0 8mm 0;}

  html,body{background:#fff!important;margin:0!important;padding:0!important;height:auto!important;}

  /* فقط نسخه چاپ دیده شود؛ ظاهر خود صفحه سایت دست نخورده بماند. */
  body *{visibility:hidden!important;}
  .alv-page{position:absolute!important;top:0!important;left:0!important;width:100%!important;margin:0!important;padding:0!important;}
  .alv-page > *:not(.alv-print-only){display:none!important;}
  .alv-print-only,.alv-print-only *{visibility:visible!important;}

  .alv-print-only{
    display:block!important;
    width:198mm!important;
    margin:0 auto!important;
    padding:0!important;
    color:#171717!important;
    font-family:"B Nazanin","BNazanin",Tahoma,Arial,sans-serif!important;
    direction:rtl!important;
    box-sizing:border-box!important;
  }
  .alv-print-only *{font-family:inherit;}
  .alv-print-only, .alv-print-only *{font-family:"B Nazanin","BNazanin",Tahoma,Arial,sans-serif!important;font-size:13pt!important;}
  .alv-print-doc-title, .alv-print-doc-title strong{font-family:"IranNastaliq","Noto Nastaliq Urdu",serif!important;font-size:11pt!important;}
  .alv-print-doc-title strong{font-size:13pt!important;}

  /* قاب چاپ به صورت یک لایه‌ی ثابت روی هر صفحه تکرار می‌شود.
     بنابراین ارتفاع قاب مستقل از مقدار متن است و در انتهای جمله بسته نمی‌شود. */
  .alv-print-frame{
    position:fixed!important;
    top:3mm!important;
    left:50%!important;
    transform:translateX(-50%)!important;
    width:198mm!important;
    height:273mm!important;
    box-sizing:border-box!important;
    border:0.4mm solid #444!important;
    background:transparent!important;
    z-index:999!important;
    pointer-events:none!important;
  }

  .alv-print-sheet{
    width:198mm!important;
    min-height:0!important;
    height:auto!important;
    box-sizing:border-box!important;
    position:relative!important;
    z-index:1!important;
    background:#fff!important;
    border:0!important;
    -webkit-box-decoration-break:clone!important;
    box-decoration-break:clone!important;
    margin:0 auto!important;
    /* فاصله‌ی امن متن از قاب؛ با clone در شکست صفحات نیز حفظ می‌شود. */
    padding:7mm 13mm 12mm!important;
    overflow:visible!important;
  }

  .alv-print-sheet > *{
    break-inside:auto;
  }

  .alv-print-header{
    position:relative;
    height:40mm;
    border-bottom:0;
    margin-bottom:4mm;
    break-inside:avoid;
    page-break-inside:avoid;
  }

  .alv-print-brand{position:absolute;right:0;top:5mm;width:52mm;text-align:right;}
  .alv-print-logo{width:29mm;height:18mm;display:flex;align-items:flex-start;justify-content:flex-start;margin:0 0 1mm 0;}
  .alv-print-logo img{max-width:29mm;max-height:18mm;width:auto;height:auto;object-fit:contain;}
  .alv-print-logo-fallback{width:24mm;height:18mm;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12pt;color:#222;border:0.4mm solid #c9a24b;}
  .alv-print-brand-name{font-size:9pt;font-weight:800;color:#333;}
  .alv-print-brand-sub{display:block;margin-top:1mm;font-size:7.2pt;color:#777;}

  .alv-print-doc-title{position:absolute;left:0;right:0;top:33mm;width:auto;text-align:center;font-family:"IranNastaliq","Noto Nastaliq Urdu",serif;font-size:11pt;font-weight:400;color:#111;line-height:1.3;}
  .alv-print-doc-title strong{display:block;font-family:"IranNastaliq","Noto Nastaliq Urdu",serif;font-size:14pt;font-weight:400;margin:0;}

  .alv-print-meta{position:absolute;left:0;top:5mm;width:42mm;border-collapse:collapse;font-size:8.5pt;line-height:1.8;}
  .alv-print-meta tr{display:block;white-space:nowrap;}
  .alv-print-meta td{display:block;padding:0;vertical-align:middle;border:0;white-space:nowrap;}
  .alv-print-meta .label{font-weight:800;color:#222;margin-left:1mm;}
  .alv-print-meta .value{font-weight:500;color:#333;}

  .alv-print-recipient{font-size:11pt;font-weight:800;line-height:2;margin-bottom:1mm;}
  .alv-print-subject{display:flex;gap:3mm;align-items:baseline;font-size:10.8pt;line-height:2;margin:3mm 0 4mm;}
  .alv-print-subject .label{font-weight:900;}.alv-print-subject .value{font-weight:700;}
  .alv-print-greeting{font-size:11pt;font-weight:700;margin-bottom:5mm;}

  /* فاصله خطوط کنترل شده تا Enter باعث فضای غیرطبیعی نشود. */
  .alv-print-body{
    word-break:break-word;
    text-align:justify;
    font-size:13pt;
    font-weight:400;
    line-height:1.65;
    margin:0!important;
    padding:0!important;
  }
  .alv-print-paragraph{
    white-space:pre-wrap;
    margin:0;
  }
  .alv-print-markdown-heading{
    font-weight:900!important;
  }
  /* فاصله پاراگراف‌هایی که با دو Enter ایجاد شده‌اند، فقط کمی کمتر می‌شود. */
  .alv-print-paragraph-gap{
    margin-bottom:1.2mm;
  }
  .alv-print-description{white-space:pre-wrap;word-break:break-word;font-size:13pt;line-height:1.65;color:#555;margin-top:5mm;}

  .alv-print-signature{width:48mm;margin-right:auto;margin-left:0;margin-top:10mm;text-align:center;font-size:13pt;line-height:1.35;font-weight:700;break-inside:avoid;page-break-inside:avoid;}
  .alv-print-signature .signature-space{height:3mm;}.alv-print-signature .name{font-size:13pt;font-weight:900;}.alv-print-signature .position{font-size:13pt;color:#555;}

  .alv-print-bottom{margin-top:3mm;padding-top:3mm;border-top:0.25mm solid #ddd;font-size:13pt;line-height:1.5;color:#555;break-inside:avoid;page-break-inside:avoid;}
  .alv-print-bottom-row{margin-bottom:1mm;}.alv-print-bottom .label{font-weight:900;color:#222;}
  .alv-print-footer{position:absolute;left:16mm;right:16mm;bottom:6mm;border-top:0.25mm solid #e0e0e0;padding-top:2mm;display:flex;justify-content:space-between;gap:8mm;font-size:13pt;color:#999;}
  a{color:inherit!important;text-decoration:none!important;}

}
</style>

<style id="reply-button-exact-size">
/* دکمه پاسخ: دقیقاً هم‌قد و هم‌تراز با سایر دکمه‌های اکشن */
.alv-page .card .btn-reply{
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  box-sizing:border-box!important;
  height:38px!important;
  min-height:38px!important;
  max-height:38px!important;
  width:auto!important;
  min-width:0!important;
  max-width:none!important;
  padding:0 14px!important;
  margin:0!important;
  line-height:1!important;
  border-radius:10px!important;
  font-size:13px!important;
  font-weight:700!important;
  white-space:nowrap!important;
  vertical-align:middle!important;
  flex:0 0 auto!important;
}
.alv-page .card .btn-reply i{
  margin-left:6px!important;
  margin-right:0!important;
  line-height:1!important;
}
.alv-page .card .d-flex.gap-2{
  align-items:center!important;
}
</style>

<style id="reply-button-height-final">
/* دکمه پاسخ — فقط ارتفاع و تراز، دقیقاً هم‌اندازه دکمه‌های همان ردیف */
.alv-page .card .btn-reply{
  box-sizing:border-box!important;
  height:38px!important;
  min-height:38px!important;
  max-height:38px!important;
  padding-top:0!important;
  padding-bottom:0!important;
  line-height:1!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  vertical-align:middle!important;
}

/* جلوگیری از اختلاف ارتفاع به‌خاطر اندازه آیکون */
.alv-page .card .btn-reply i{
  line-height:1!important;
  vertical-align:middle!important;
}
</style>


<div class="alv-page">
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <a href="automation_letters_list.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i> بازگشت به لیستِ نامه‌ها</a>
  <button type="button" class="btn btn-sm alv-print-btn" onclick="window.print()">
    <i class="fa-solid fa-print"></i> چاپ نامه
  </button>
</div>

<!-- این بخش فقط هنگام چاپ نمایش داده می‌شود و در صفحه سایت مخفی است. -->
<div class="alv-print-only">
  <div class="alv-print-frame" aria-hidden="true"></div>
  <div class="alv-print-sheet">
    <div class="alv-print-header">
      <div class="alv-print-brand">
        <div class="alv-print-logo">
          <?php if ($invoiceLogoPath !== ''): ?>
            <img src="<?= e($invoiceLogoPath) ?>" alt="<?= e($invoiceCompanyName) ?>">
          <?php else: ?>
            <div class="alv-print-logo-fallback">آراد</div>
          <?php endif; ?>
        </div>

      </div>

      <table class="alv-print-meta">
        <tr>
          <td><span class="label">شماره:</span><span class="value" dir="ltr"><?= e($letter['letter_number'] ?? '—') ?></span></td>
          <td><span class="label">تاریخ:</span><span class="value"><?= e(to_jalali(substr($letter['created_at'], 0, 10))) ?></span></td>
          <td><span class="label">پیوست:</span><span class="value"><?= !empty($attachments) ? 'دارد' : 'ندارد' ?></span></td>
        </tr>
      </table>

      <div class="alv-print-doc-title">
        <strong>بسمه تعالی</strong>
      </div>
    </div>

    <div class="alv-print-recipient">به: <?= e($recipientUnitsLabel) ?></div>
    <div class="alv-print-recipient">از: <?= e($letter['sender_unit_title'] ?? '—') ?></div>

    <div class="alv-print-subject">
      <span class="label">موضوع:</span>
      <span class="value"><?= e($letter['subject']) ?></span>
    </div>

    <div class="alv-print-greeting">با سلام و احترام</div>

    <?php if ($letter['body']): ?>
      <?php $printBodyParagraphs = preg_split("/\R{2,}/u", (string) $letter['body']); ?>
      <div class="alv-print-body">
        <?php foreach ($printBodyParagraphs as $i => $paragraph): ?>
          <?php
          // تبدیل Markdown ساده‌ی متن نامه برای چاپ:
          // **متن** => بولد و # / ## / ### ابتدای خط => تیتر بولد بدون هشتگ
          $printLines = preg_split("/\R/u", (string) $paragraph);
          $printHtmlLines = [];
          foreach ($printLines as $printLine) {
              $printLine = trim((string) $printLine);
              if ($printLine === '') {
                  $printHtmlLines[] = '<br>';
                  continue;
              }

              // تیترهای Markdown را تشخیص بده و هشتگ‌ها را حذف کن.
              $isHeading = false;
              if (preg_match('/^#{1,6}\s*(.*)$/u', $printLine, $hm)) {
                  $printLine = trim($hm[1]);
                  $isHeading = true;
              }

              // ابتدا کل خط را امن کن، سپس فقط نشانه‌ی **...** را به <strong> تبدیل کن.
              $safeLine = e($printLine);
              $safeLine = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $safeLine);

              if ($isHeading) {
                  $printHtmlLines[] = '<strong class="alv-print-markdown-heading">' . $safeLine . '</strong>';
              } else {
                  $printHtmlLines[] = $safeLine;
              }
          }
          ?>
          <div class="alv-print-paragraph<?= $i < count($printBodyParagraphs) - 1 ? ' alv-print-paragraph-gap' : '' ?>"><?= implode('<br>', $printHtmlLines) ?></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alv-print-body">متن نامه‌ای برای چاپ ثبت نشده است.</div>
    <?php endif; ?>

    <?php if ($letter['description']): ?>
      <div class="alv-print-description">توضیحات: <?= e($letter['description']) ?></div>
    <?php endif; ?>

    <?php
      // در بخش چاپ، اگر نام فرستنده و سمت او یک عبارت یکسان باشند، فقط یک‌بار نمایش داده شود.
      // همچنین اگر سمت به‌صورت تکراریِ پشت‌سرهم ثبت شده باشد، تکرار حذف می‌شود.
      $printSenderName = trim((string)($letter['sender_name'] ?? ''));
      $printSenderPosition = trim((string)($letter['sender_position_title'] ?? ''));
      $normalizePrintText = static function (string $text): string {
          $text = preg_replace('/\s+/u', ' ', trim($text));
          return $text ?? '';
      };
      $normalizedPrintName = $normalizePrintText($printSenderName);
      $normalizedPrintPosition = $normalizePrintText($printSenderPosition);

      if ($normalizedPrintPosition !== '' && $normalizedPrintPosition === $normalizedPrintName) {
          $printSenderPosition = '';
      } elseif ($normalizedPrintPosition !== '') {
          // حذف تکرار کاملِ یک عبارت در خودِ سمت؛ فقط در صورت تکرار دقیق و پشت‌سرهم.
          $positionParts = preg_split('/\s+/u', $normalizedPrintPosition);
          $positionCount = count($positionParts);
          if ($positionCount > 1 && $positionCount % 2 === 0) {
              $half = (int)($positionCount / 2);
              if (array_slice($positionParts, 0, $half) === array_slice($positionParts, $half)) {
                  $printSenderPosition = implode(' ', array_slice($positionParts, 0, $half));
              }
          }
      }
    ?>
    <div class="alv-print-signature">
      <div>با احترام</div>
      <div class="signature-space"></div>
      <?php if ($printSenderName !== ''): ?>
        <div class="name"><?= e($printSenderName) ?></div>
      <?php endif; ?>
      <?php if ($printSenderPosition !== ''): ?>
        <div class="position"><?= e($printSenderPosition) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($recipientDisplay['cc']): ?>
      <div class="alv-print-bottom">
        <div class="alv-print-bottom-row">
          <span class="label">رونوشت:</span>
          <?= e(implode('، ', array_map(fn($r) => $r['label'], $recipientDisplay['cc']))) ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-4">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
        <h5 class="mb-0"><?= e($letter['subject']) ?></h5>
        <div class="d-flex gap-1">
          <span class="badge bg-<?= $priorityBadge[$letter['priority']] ?? 'secondary' ?>"><?= e($letter['priority']) ?></span>
          <span class="badge bg-secondary"><?= e($letter['confidentiality']) ?></span>
          <span class="badge bg-dark"><?= e($letter['status']) ?></span>
        </div>
      </div>
      <div class="text-muted small mb-3">
        شماره: <span dir="ltr"><?= e($letter['letter_number'] ?? '—') ?></span> ·
        فرستنده: <?= e($letter['sender_name']) ?> (<?= e($letter['sender_position_title'] ?? '—') ?>) ·
        تاریخ: <?= to_jalali(substr($letter['created_at'], 0, 10)) ?>
        <?php if ($letter['deadline_at']): ?> · مهلت اقدام: <?= to_jalali($letter['deadline_at']) ?><?php endif; ?>
      </div>
      <?php if ($letter['body']): ?>
        <?php
        // نمایش متن نامه در صفحه جزئیات با پشتیبانی از Markdown ساده:
        // **متن** => بولد و # / ## / ### ابتدای خط => تیتر بولد بدون هشتگ
        // همه اینترهای متوالی را به یک اینتر تبدیل می‌کنیم تا فاصله اضافی بین خطوط ایجاد نشود.
        $screenBody = preg_replace("/\R{2,}/u", "\n", str_replace(["\r\n", "\r"], "\n", (string) $letter['body']));
        $screenLines = preg_split("/\R/u", (string) $screenBody);
        $screenHtmlLines = [];
        foreach ($screenLines as $screenLine) {
            $screenLine = rtrim((string) $screenLine);
            $trimmedScreenLine = trim($screenLine);

            if ($trimmedScreenLine === '') {
                // یک اینتر خالی را به فاصله‌ی کوچک و کنترل‌شده تبدیل می‌کنیم؛
                // چند اینتر متوالی نیز فقط یک فاصله ایجاد می‌کند.
                if (!empty($screenHtmlLines) && end($screenHtmlLines) !== '<span class=\"alv-screen-gap\"></span>') {
                    $screenHtmlLines[] = '<span class=\"alv-screen-gap\"></span>';
                }
                continue;
            }

            // تیترهای Markdown را تشخیص بده و هشتگ‌ها را حذف کن.
            $isScreenHeading = false;
            if (preg_match('/^#{1,6}\s*(.*)$/u', $trimmedScreenLine, $hm)) {
                $trimmedScreenLine = trim($hm[1]);
                $isScreenHeading = true;
            }

            // ابتدا متن را امن کن، سپس فقط نشانه‌ی **...** را به <strong> تبدیل کن.
            $safeScreenLine = e($trimmedScreenLine);
            $safeScreenLine = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $safeScreenLine);

            if ($isScreenHeading) {
                $screenHtmlLines[] = '<strong class="alv-screen-markdown-heading">' . $safeScreenLine . '</strong>';
            } else {
                $screenHtmlLines[] = $safeScreenLine;
            }
        }
        ?>
        <div class="mb-3 alv-screen-body"><?= implode('<br>', $screenHtmlLines) ?></div>
      <?php endif; ?>
      <?php if ($letter['description']): ?><div class="text-muted small border-top pt-2">توضیحات: <?= e($letter['description']) ?></div><?php endif; ?>

      <?php if ($attachments): ?>
        <div class="mt-3">
          <h6 class="small fw-bold">پیوست‌ها</h6>
          <?php foreach ($attachments as $a): ?>
            <div class="d-flex align-items-center gap-2 small mb-1">
              <i class="fa-solid fa-paperclip"></i>
              <?php if ($canViewAttachment): ?>
                <a href="automation_attachment_download.php?id=<?= (int) $a['id'] ?>"><?= e($a['file_name']) ?></a>
              <?php else: ?>
                <span class="text-muted"><?= e($a['file_name']) ?> (بدونِ دسترسیِ مشاهده)</span>
              <?php endif; ?>
              <span class="text-muted">(<?= number_format(((int) $a['file_size']) / 1024, 0) ?> KB)</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="mt-3 border-top pt-3">
        <div class="small text-muted mb-1">گیرندگان اصلی:</div>
        <?php foreach ($recipientDisplay['to'] as $r): ?>
          <span class="badge bg-light text-dark border me-1 mb-1"><?php if ($r['kind'] !== 'user'): ?><i class="fa-solid <?= $r['kind'] === 'unit' ? 'fa-building' : 'fa-layer-group' ?>"></i> <?php endif; ?><?= e($r['label']) ?><?= $r['status'] ? ' — ' . e($r['status']) : '' ?></span>
        <?php endforeach; ?>
        <?php if ($recipientDisplay['cc']): ?>
          <div class="small text-muted mt-2 mb-1">رونوشت:</div>
          <?php foreach ($recipientDisplay['cc'] as $r): ?>
            <span class="badge bg-info text-dark me-1 mb-1"><?php if ($r['kind'] !== 'user'): ?><i class="fa-solid <?= $r['kind'] === 'unit' ? 'fa-building' : 'fa-layer-group' ?>"></i> <?php endif; ?><?= e($r['label']) ?></span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2 mt-3 flex-wrap">
        <?php if ($letter['status'] === 'پیش‌نویس' && (int) $letter['sender_user_id'] === $myId): ?><a href="automation_compose.php?draft=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane"></i> ویرایش و ارسالِ پیش‌نویس</a><?php endif; ?>
        <?php if ($canReply && $letter['status'] !== 'پیش‌نویس'): ?><a href="automation_compose.php?reply_to=<?= $id ?>" class="btn btn-sm btn-primary btn-reply"><i class="fa-solid fa-reply"></i> پاسخ</a><?php endif; ?>
        <?php if ($canArchive && $letter['status'] !== 'بایگانی شده'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
            <button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-box-archive"></i> بایگانی</button></form>
        <?php endif; ?>
        <?php if ($isSender && $letter['status'] !== 'بسته شده'): ?>
          <form method="post" onsubmit="return confirm('این نامه بسته شود؟');"><?= csrf_field() ?><input type="hidden" name="action" value="close">
            <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-lock"></i> بستن نامه</button></form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($myPendingStep && $canApprove): ?>
    <div class="card p-4 border-warning">
      <h6 class="mb-3"><i class="fa-solid fa-gavel text-warning"></i> این نامه در انتظارِ تصمیمِ شماست</h6>
      <form method="post" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="decide">
        <input type="hidden" name="step_id" value="<?= (int) $myPendingStep['id'] ?>">
        <div class="col-12"><textarea name="note" class="form-control form-control-sm" rows="2" placeholder="توضیح (اختیاری)"></textarea></div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i> تایید</button>
          <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i> رد</button>
          <button type="submit" name="decision" value="return" class="btn btn-sm btn-outline-warning"><i class="fa-solid fa-rotate-left"></i> برگشت برای اصلاح</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canForward): ?>
    <div class="card p-4">
      <h6 class="mb-3"><i class="fa-solid fa-share-from-square"></i> ارجاع نامه</h6>
      <form method="post" class="row g-2 position-relative">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="refer">
        <input type="hidden" name="to_kind" id="referToKind" value="user">
        <input type="hidden" name="to_id" id="referToId" value="">
        <div class="col-md-6 position-relative">
          <input type="text" id="referSearch" class="form-control form-control-sm" placeholder="جستجویِ فرد یا واحد برایِ ارجاع..." autocomplete="off">
          <div id="referResults" class="d-none" style="position:absolute;z-index:20;background:#fff;border:1px solid var(--alv-line);border-radius:10px;width:100%;max-height:220px;overflow:auto"></div>
        </div>
        <div class="col-md-3"><input type="text" name="deadline_at" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" placeholder="مهلتِ اقدام"></div>
        <div class="col-md-3">
          <select name="priority" class="form-select form-select-sm">
            <?php foreach (['عادی','مهم','فوری','خیلی فوری'] as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><textarea name="note" class="form-control form-control-sm" rows="2" placeholder="یادداشتِ ارجاع (اختیاری)"></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="fa-solid fa-share-from-square"></i> ارجاع بده</button></div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($replies): ?>
    <div class="card p-4">
      <h6 class="mb-3"><i class="fa-solid fa-comments"></i> پاسخ‌ها</h6>
      <?php foreach ($replies as $rp): ?>
        <div class="border rounded p-2 mb-2">
          <a href="automation_letter_view.php?id=<?= (int) $rp['id'] ?>"><?= e($rp['subject']) ?></a>
          <div class="text-muted small"><?= e($rp['sender_name']) ?> — <?= to_jalali(substr($rp['created_at'], 0, 10)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <h6 class="mb-3"><i class="fa-solid fa-route"></i> مسیرِ تایید</h6>
      <?php if (!$paths): ?>
        <div class="text-muted small">این نامه مسیرِ تاییدِ مشخصی ندارد (ارسالِ مستقیم به همه‌یِ گیرندگان).</div>
      <?php endif; ?>
      <?php foreach ($paths as $p): ?>
        <div class="mb-3">
          <div class="small fw-bold mb-1">گیرنده: <?= e($p['recipient_name']) ?> — <span class="badge bg-light text-dark border"><?= e($p['status']) ?></span></div>
          <?php foreach (($stepsByPath[$p['id']] ?? []) as $s):
              $cls = $s['status'] === 'تایید شد' ? 'done' : ($s['status'] === 'در انتظار تایید' ? 'pending' : 'rejected');
              $icon = $s['status'] === 'تایید شد' ? 'fa-check text-success' : ($s['status'] === 'در انتظار تایید' ? 'fa-hourglass-half text-warning' : 'fa-xmark text-danger');
          ?>
            <div class="approval-step <?= $cls ?>">
              <i class="fa-solid <?= $icon ?>"></i>
              <div class="flex-grow-1">
                <div class="small fw-bold"><?= e($s['full_name']) ?></div>
                <div class="text-muted" style="font-size:.7rem"><?= e($s['snapshot_position_title'] ?? '') ?> <?= e($s['snapshot_unit_title'] ? '— ' . $s['snapshot_unit_title'] : '') ?></div>
                <?php if ($s['note']): ?><div class="text-muted" style="font-size:.7rem">یادداشت: <?= e($s['note']) ?></div><?php endif; ?>
              </div>
              <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= e($s['status']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($referrals): ?>
    <div class="card p-3">
      <h6 class="mb-3"><i class="fa-solid fa-share-from-square"></i> ارجاعات</h6>
      <?php foreach ($referrals as $r): ?>
        <div class="border rounded p-2 mb-2 small">
          <div><?= e($r['by_name']) ?> ← <?= e($r['to_name'] ?? $r['to_unit_title'] ?? '—') ?></div>
          <div class="text-muted"><?= to_jalali(substr($r['created_at'], 0, 10)) ?> · <span class="badge bg-light text-dark border"><?= e($r['status']) ?></span></div>
          <?php if ($r['note']): ?><div class="text-muted">یادداشت: <?= e($r['note']) ?></div><?php endif; ?>
          <?php if ((int) $r['referred_to_user_id'] === $myId && $r['status'] === 'در انتظار اقدام'): ?>
            <form method="post" class="mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="referral_done"><input type="hidden" name="referral_id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-success">انجام شد</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card p-3">
      <h6 class="mb-3"><i class="fa-solid fa-clock-rotate-left"></i> گردشِ نامه (تاریخچه)</h6>
      <ul class="timeline">
        <?php foreach ($history as $h): ?>
          <li>
            <div class="small fw-bold"><?= e($h['description'] ?: $h['event_type']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= e($h['full_name'] ?? 'سیستم') ?> — <?= to_jalali(substr($h['created_at'], 0, 10)) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
</div>


<script>
(function () {
  var input = document.getElementById('referSearch');
  var results = document.getElementById('referResults');
  var kindEl = document.getElementById('referToKind');
  var idEl = document.getElementById('referToId');
  if (!input) return;
  var timer = null;
  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = input.value.trim();
    if (q.length < 2) { results.classList.add('d-none'); return; }
    timer = setTimeout(function () {
      fetch('automation_search_recipients.php?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (data) {
        if (!data.ok || !data.results.length) { results.classList.add('d-none'); return; }
        results.innerHTML = '';
        data.results.forEach(function (item) {
          var div = document.createElement('div');
          div.style.cssText = 'padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #f3f1ea';
          div.innerHTML = '<strong>' + item.label + '</strong> <span class="text-muted small">' + (item.sub || '') + '</span>';
          div.addEventListener('click', function () {
            kindEl.value = item.kind; idEl.value = item.id; input.value = item.label; results.classList.add('d-none');
          });
          results.appendChild(div);
        });
        results.classList.remove('d-none');
      }).catch(function () {});
    }, 250);
  });
})();
</script>
<?php if ($secretariatNotice): ?>
<div class="modal fade" id="secretariatNoticeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;border:1px solid #e2cb91">
      <div class="modal-header" style="border-bottom:1px solid #f1e6c8">
        <h6 class="modal-title fw-bold"><i class="fa-solid fa-envelope-circle-check text-success"></i> نامه‌ی شما به دبیرخانه‌ی ریاست ارسال شد</h6>
        <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button>
      </div>
      <div class="modal-body" style="line-height:2">
        نامه‌ی شما <b>حتماً خوانده می‌شود</b>؛ اما لزوماً قرار نیست از طریقِ همین نامه پاسخی دریافت کنید،
        بنابراین نیازی نیست پیگیریِ موضوع را از طریقِ نامه انجام دهید.
      </div>
      <div class="modal-footer" style="border-top:0">
        <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">متوجه شدم</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('secretariatNoticeModal');
  if (!el) return;
  if (window.bootstrap && bootstrap.Modal) { new bootstrap.Modal(el).show(); }
  else { alert('نامه‌ی شما به دبیرخانه‌ی ریاست ارسال شد.\nنامه‌ی شما حتماً خوانده می‌شود؛ اما لزوماً قرار نیست از طریقِ همین نامه پاسخی دریافت کنید.'); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
