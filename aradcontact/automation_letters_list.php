<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/automation_functions.php';
if (isset($pdo)) automation_letter_numbers_v1($pdo); else automation_letter_numbers_v1(db());
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
$canViewAll = automation_user_has_permission($pdo, $user, 'letter_view_all');
$canDelete = automation_user_has_permission($pdo, $user, 'letter_delete');
$view = $_GET['view'] ?? 'inbox';
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$viewLabels = [
    'inbox'            => 'نامه‌های دریافتی',
    'sent'              => 'نامه‌های ارسالی',
    'referred'          => 'نامه‌های ارجاع‌شده به من',
    'pending_approval'  => 'در انتظار تایید من',
    'pending_action'    => 'در انتظار اقدام',
    'unread'            => 'نامه‌های خوانده‌نشده',
    'urgent'            => 'نامه‌های فوری',
    'drafts'            => 'پیش‌نویس‌ها',
    'archive'           => 'بایگانی',
    'all'               => 'جستجوی همه مکاتبات',
];
if (!isset($viewLabels[$view])) { $view = 'inbox'; }

// حذف نامه: فقط برای کاربری که Permission «حذف نامه» دارد.
// حتی اگر فرم به‌صورت دستی ارسال شود، Permission در سمت سرور دوباره کنترل می‌شود.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_letter') {
    automation_require_permission($pdo, $user, 'letter_delete');

    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید.');
        redirect('automation_letters_list.php');
    }

    $deleteId = (int) ($_POST['letter_id'] ?? 0);
    $redirectView = trim((string) ($_POST['view'] ?? 'inbox'));
    if (!isset($viewLabels[$redirectView])) { $redirectView = 'inbox'; }
    $redirectQ = trim((string) ($_POST['q'] ?? ''));
    $redirectPage = max(1, (int) ($_POST['page'] ?? 1));

    if ($deleteId <= 0) {
        flash_set('danger', 'شناسه نامه معتبر نیست.');
    } else {
        // فقط نامه‌ای که کاربر طبق Permission مشاهده می‌کند قابل حذف است.
        $canDeleteLetter = false;
        $checkStmt = $pdo->prepare('SELECT id, sender_user_id, subject FROM letters WHERE id = ? LIMIT 1');
        $checkStmt->execute([$deleteId]);
        $deleteLetter = $checkStmt->fetch();

        if ($deleteLetter) {
            $isSenderOfDelete = (int) $deleteLetter['sender_user_id'] === $myId;
            $isRecipientOfDelete = false;
            $recipientCheck = $pdo->prepare('SELECT 1 FROM letter_recipient_users WHERE letter_id = ? AND user_id = ? LIMIT 1');
            $recipientCheck->execute([$deleteId, $myId]);
            $isRecipientOfDelete = (bool) $recipientCheck->fetchColumn();
            $canDeleteLetter = $isSenderOfDelete || $isRecipientOfDelete || $canViewAll;
        }

        if (!$deleteLetter || !$canDeleteLetter) {
            flash_set('danger', 'این نامه در محدوده دسترسی شما نیست یا وجود ندارد.');
        } else {
            try {
                $pdo->beginTransaction();
                $deleteStmt = $pdo->prepare('DELETE FROM letters WHERE id = ?');
                $deleteStmt->execute([$deleteId]);

                if ($deleteStmt->rowCount() > 0) {
                    try {
                        automation_audit($pdo, $myId, 'letter_delete', 'letter', $deleteId, 'حذف نامه: ' . (string) ($deleteLetter['subject'] ?? ''));
                    } catch (Throwable $auditError) {
                        // حذف نامه نباید صرفاً به‌دلیل خطای ثبت Audit متوقف شود.
                    }
                    $pdo->commit();
                    flash_set('success', 'نامه با موفقیت حذف شد.');
                } else {
                    $pdo->rollBack();
                    flash_set('danger', 'نامه پیدا نشد یا قبلاً حذف شده است.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                flash_set('danger', 'حذف نامه انجام نشد. ممکن است نامه وابستگی غیرقابل حذف داشته باشد.');
            }
        }
    }

    $qs = ['view' => $redirectView];
    if ($redirectQ !== '') { $qs['q'] = $redirectQ; }
    if ($redirectPage > 1) { $qs['page'] = $redirectPage; }
    redirect('automation_letters_list.php?' . http_build_query($qs));
}


$baseSql = "SELECT DISTINCT l.*, u.full_name AS sender_name,
    CASE WHEN l.sender_user_id = ? THEN (
        SELECT GROUP_CONCAT(DISTINCT ru.full_name ORDER BY ru.full_name SEPARATOR ', ')
        FROM letter_recipient_users lru2
        LEFT JOIN users ru ON ru.id = lru2.user_id
        WHERE lru2.letter_id = l.id AND lru2.role = 'to'
    ) ELSE u.full_name END AS display_person_name
    FROM letters l
    LEFT JOIN users u ON u.id = l.sender_user_id";
$joins = [];
$where = ['1=1'];
$params = [$myId];

switch ($view) {
    case 'inbox':
        $joins[] = 'JOIN letter_recipient_users lru ON lru.letter_id = l.id AND lru.user_id = ? AND lru.role = \'to\'';
        $params[] = $myId;
        break;
    case 'sent':
        $where[] = 'l.sender_user_id = ?';
        $params[] = $myId;
        $where[] = "l.status <> 'پیش‌نویس'";
        break;
    case 'referred':
        $joins[] = 'JOIN letter_referrals lr ON lr.letter_id = l.id AND lr.referred_to_user_id = ?';
        $params[] = $myId;
        break;
    case 'pending_approval':
        $joins[] = 'JOIN letter_delivery_paths ldp ON ldp.letter_id = l.id';
        $joins[] = 'JOIN letter_approval_steps las ON las.delivery_path_id = ldp.id AND las.approver_user_id = ? AND las.status = \'در انتظار تایید\'';
        $params[] = $myId;
        break;
    case 'pending_action':
        $joins[] = 'JOIN letter_recipient_users lru ON lru.letter_id = l.id AND lru.user_id = ? AND lru.role = \'to\'';
        $params[] = $myId;
        $where[] = "lru.status NOT IN ('بسته شده','در انتظار تایید')";
        break;
    case 'unread':
        $joins[] = 'JOIN letter_recipient_users lru ON lru.letter_id = l.id AND lru.user_id = ? AND lru.role = \'to\'';
        $params[] = $myId;
        $where[] = "lru.status NOT IN ('خوانده شده','بسته شده','در انتظار تایید')";
        break;
    case 'urgent':
        $where[] = "l.priority IN ('فوری','خیلی فوری')";
        if (!$canViewAll) {
            $joins[] = 'LEFT JOIN letter_recipient_users lru ON lru.letter_id = l.id AND lru.user_id = ?';
            $params[] = $myId;
            $where[] = '(l.sender_user_id = ? OR lru.user_id IS NOT NULL)';
            $params[] = $myId;
        }
        break;
    case 'drafts':
        $where[] = 'l.sender_user_id = ?';
        $params[] = $myId;
        $where[] = "l.status = 'پیش‌نویس'";
        break;
    case 'archive':
        $where[] = "l.status = 'بایگانی شده'";
        if (!$canViewAll) {
            $joins[] = 'LEFT JOIN letter_recipient_users lru ON lru.letter_id = l.id AND lru.user_id = ?';
            $params[] = $myId;
            $where[] = '(l.sender_user_id = ? OR lru.user_id IS NOT NULL)';
            $params[] = $myId;
        }
        break;
    case 'all':
    default:
        if (!$canViewAll) {
            $joins[] = 'LEFT JOIN letter_recipient_users lru ON lru.letter_id = l.id AND lru.user_id = ?';
            $params[] = $myId;
            $where[] = '(l.sender_user_id = ? OR lru.user_id IS NOT NULL)';
            $params[] = $myId;
        }
        break;
}

if ($q !== '') {
    $where[] = '(l.subject LIKE ? OR l.body LIKE ? OR l.letter_number LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . normalize_digits($q) . '%'; // شماره‌ی نامه با رقمِ فارسی هم پیدا شود
}

$sql = $baseSql . ' ' . implode(' ', $joins) . ' WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') t');
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare($sql . ' ORDER BY l.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$listStmt->execute($params);
$letters = $listStmt->fetchAll();
// نامِ گیرنده/فرستنده: واحد ← نامِ واحد (نه اعضا)؛ اعضای دبیرخانه ریاست ← نامِ دبیرخانه
foreach ($letters as &$__l) {
    try {
        if ((int) $__l['sender_user_id'] === $myId) {
            $__d = automation_letter_recipient_display($pdo, (int) $__l['id'], $user);
            if ($__d['to']) $__l['display_person_name'] = implode('، ', array_map(static fn($x) => $x['label'], $__d['to']));
        } else {
            $__l['display_person_name'] = automation_display_name($pdo, (int) $__l['sender_user_id'], (string) ($__l['display_person_name'] ?? $__l['sender_name'] ?? ''), $user, (int) $__l['id']);
            $__l['sender_name'] = automation_display_name($pdo, (int) $__l['sender_user_id'], (string) ($__l['sender_name'] ?? ''), $user, (int) $__l['id']);
        }
    } catch (Throwable $e) {}
}
unset($__l);

// وضعیتِ خوانده‌شده/نشده‌یِ من برایِ هر نامه (برایِ رنگ‌بندیِ سطر)
$myStatusByLetter = [];
if ($letters) {
    $ids = array_column($letters, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT letter_id, status, role FROM letter_recipient_users WHERE user_id = ? AND letter_id IN ($ph)");
    $s->execute(array_merge([$myId], $ids));
    foreach ($s->fetchAll() as $r) {
        $myStatusByLetter[$r['letter_id']] = $r;
    }
}

$priorityBadge = ['عادی' => 'secondary', 'مهم' => 'warning', 'فوری' => 'danger', 'خیلی فوری' => 'dark'];

$pageTitle = $viewLabels[$view];
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
/* ===== Arad Contact | Luxury Gold Theme ===== */
.alx-page{
  --lux-black:#0c0b09;
  --lux-black-2:#17140f;
  --lux-black-3:#242016;
  --lux-gold:#c9a24d;
  --lux-gold-2:#e4c66f;
  --lux-gold-3:#f7e7b0;
  --lux-gold-dark:#765719;
  --lux-cream:#fffaf0;
  --lux-cream-2:#f7f0df;
  --lux-line:#e5d4a8;
  --lux-text:#211b10;
  --lux-muted:#786d58;
  color:var(--lux-text);
  position:relative;
}
.alx-page:before{
  content:"";
  position:absolute;
  inset:-18px -14px auto;
  height:180px;
  z-index:-1;
  pointer-events:none;
  background:
    radial-gradient(circle at 85% 15%,rgba(226,190,91,.18),transparent 30%),
    radial-gradient(circle at 10% 20%,rgba(226,190,91,.10),transparent 28%),
    linear-gradient(135deg,rgba(255,255,255,.75),rgba(247,240,223,.45));
  border-radius:28px;
}
.alx-page .lux-hero{
  position:relative;
  overflow:hidden;
  min-height:145px;
  margin-bottom:18px;
  padding:24px 30px;
  border-radius:24px;
  border:1px solid rgba(201,162,77,.62);
  background:
    radial-gradient(circle at 12% 25%,rgba(241,211,133,.28),transparent 25%),
    radial-gradient(circle at 88% 0%,rgba(201,162,77,.20),transparent 30%),
    linear-gradient(135deg,#fffdf7 0%,#f5ead1 48%,#fffaf0 100%);
  box-shadow:
    0 18px 42px -28px rgba(63,45,10,.55),
    inset 0 1px 0 rgba(255,255,255,.95);
}
.alx-page .lux-hero:before{
  content:"";
  position:absolute;
  width:260px;height:260px;
  left:-105px;top:-120px;
  border:1px solid rgba(159,119,34,.16);
  border-radius:50%;
  box-shadow:0 0 0 22px rgba(159,119,34,.045),0 0 0 44px rgba(159,119,34,.03);
}
.alx-page .lux-hero:after{
  content:"✦";
  position:absolute;
  left:28px;bottom:12px;
  font-size:78px;
  line-height:1;
  color:rgba(176,132,39,.10);
}
.alx-page .lux-hero-content{
  position:relative;
  z-index:2;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:24px;
}
.alx-page .lux-hero-copy h4{
  margin:0 0 7px;
  font-weight:950;
  font-size:1.55rem;
  letter-spacing:-.6px;
  color:#3a2a0e;
}
.alx-page .lux-hero-copy p{
  margin:0;
  color:#76633e;
  font-size:.82rem;
  font-weight:700;
}
.alx-page .lux-seal{
  flex:0 0 94px;
  width:94px;height:94px;
  border-radius:50%;
  display:grid;
  place-items:center;
  background:
    radial-gradient(circle,#f9e9b7 0 42%,#d3ad57 43% 52%,#2a2111 53% 58%,#c49b42 59% 64%,#17130c 65%);
  color:#f8e8ae;
  box-shadow:0 8px 25px -12px #6e5015, inset 0 0 0 1px rgba(255,255,255,.4);
  font-size:2rem;
}
.alx-page .section-title{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
}
.alx-page .section-title h5{
  margin:0;
  font-weight:950;
  letter-spacing:-.25px;
}
.alx-page .section-title h5 i{color:var(--lux-gold-dark);margin-left:.4rem}
.alx-page .btn-primary{
  border:1px solid #a77b20;
  border-radius:12px;
  font-weight:900;
  background:linear-gradient(135deg,#f8e7ae 0%,#d6ad4d 48%,#b88a29 100%);
  color:#211805;
  box-shadow:0 8px 20px -13px #715015,inset 0 1px 0 rgba(255,255,255,.7);
}
.alx-page .btn-primary:hover{
  background:linear-gradient(135deg,#fff0c5,#dcb65c,#a97b21);
  color:#211805;
  transform:translateY(-1px);
}
.alx-page .tab-pills{
  display:flex;
  gap:.5rem;
  flex-wrap:wrap;
  margin-bottom:14px;
}
.alx-page .tab-pills a{
  padding:.48rem .9rem;
  border-radius:999px;
  font-size:.77rem;
  font-weight:850;
  text-decoration:none;
  border:1px solid #dfcfaa;
  color:#665a45;
  background:linear-gradient(180deg,#fffefa,#f8f0df);
  box-shadow:0 3px 10px -8px rgba(67,47,8,.45);
  transition:.18s ease;
}
.alx-page .tab-pills a:hover{
  border-color:#c8a04a;
  color:#5d4515;
  transform:translateY(-1px);
}
.alx-page .tab-pills a.active{
  color:#211704;
  border-color:#9e7626;
  background:linear-gradient(135deg,#f8e6aa,#cda448);
  box-shadow:0 8px 18px -12px #6d4e10,inset 0 1px 0 rgba(255,255,255,.7);
}
.alx-page .search-card{
  position:relative;
  overflow:hidden;
  border:1px solid #8f6b27;
  border-radius:20px;
  padding:14px !important;
  background:
    radial-gradient(circle at 8% 50%,rgba(219,174,69,.13),transparent 24%),
    linear-gradient(135deg,#0d0c0a,#242016);
  box-shadow:0 14px 30px -22px #49350c,inset 0 1px 0 rgba(255,255,255,.06);
}
.alx-page .search-card:after{
  content:"";
  position:absolute;
  width:180px;height:180px;
  left:-90px;top:-95px;
  border:1px solid rgba(220,180,82,.15);
  border-radius:50%;
  box-shadow:0 0 0 20px rgba(220,180,82,.035),0 0 0 40px rgba(220,180,82,.025);
}
.alx-page .search-card input{
  height:44px;
  border:1px solid #c6a95f;
  border-radius:11px;
  background:#fffdf8;
  color:#2a2111;
  font-weight:600;
  box-shadow:inset 0 1px 2px rgba(44,30,4,.06);
}
.alx-page .search-card input:focus{
  border-color:#e3c56d;
  box-shadow:0 0 0 3px rgba(219,178,76,.18);
}
.alx-page .search-card .btn-outline-secondary{
  height:44px;
  border:1px solid #d7b85e;
  border-radius:11px;
  background:linear-gradient(135deg,#f4d987,#b98a2c);
  color:#231906;
  font-weight:900;
  box-shadow:0 6px 16px -12px #7d5b19;
}
.alx-page .table-card{
  overflow:hidden;
  padding:12px !important;
  border:1px solid #d7b85c;
  border-radius:23px;
  background:
    radial-gradient(circle at 95% 5%,rgba(223,190,108,.12),transparent 25%),
    linear-gradient(145deg,#fffefb,#fbf5e7);
  box-shadow:
    0 20px 45px -28px rgba(63,45,10,.6),
    0 3px 10px rgba(61,44,10,.05),
    inset 0 1px 0 rgba(255,255,255,.95);
}
.alx-page table{
  font-size:.83rem;
  border-collapse:separate;
  border-spacing:0;
}
.alx-page table thead th{
  background:linear-gradient(135deg,#0b0a08 0%,#1b1710 48%,#2b2110 100%);
  color:#f4df9d;
  font-weight:900;
  font-size:.73rem;
  border:0;
  padding:.88rem .7rem;
  white-space:nowrap;
  box-shadow:inset 0 -1px 0 rgba(214,175,78,.35);
}
.alx-page table thead th:first-child{border-radius:0 13px 13px 0}
.alx-page table thead th:last-child{border-radius:13px 0 0 13px}
.alx-page table tbody td{
  border-bottom:1px solid #eee4cf;
  vertical-align:middle;
  padding:.78rem .65rem;
  background:rgba(255,255,255,.74);
}
.alx-page table tbody tr:last-child td{border-bottom:0}
.alx-page table tbody tr:hover td{
  background:linear-gradient(90deg,#fffdf8,#fff8e7);
}
.alx-page tr.unread-row td{
  font-weight:750;
  background:linear-gradient(90deg,#fffdf8,#fff8e4);
}
.alx-page .sender-cell{
  min-width:155px;
  font-weight:850;
  color:#342812;
  white-space:nowrap;
}
.alx-page .sender-icon{
  display:inline-grid;
  place-items:center;
  width:28px;height:28px;
  margin-left:.45rem;
  border-radius:50%;
  color:#7b5b1a;
  background:linear-gradient(145deg,#fff0bd,#c69a3e);
  border:1px solid #b88d32;
  box-shadow:0 3px 8px -5px #6b4d12,inset 0 1px 0 rgba(255,255,255,.75);
  font-size:.72rem;
}
.alx-page .my-status-cell{min-width:130px}
.alx-page .my-status-badge{
  display:inline-flex;
  align-items:center;
  gap:.32rem;
  padding:.3rem .62rem;
  border-radius:999px;
  background:linear-gradient(135deg,#fff5d7,#ead5a0);
  color:#5c4517;
  border:1px solid #d7bb75;
  font-size:.73rem;
  font-weight:900;
  white-space:nowrap;
  box-shadow:0 3px 8px -7px #705014;
}
.alx-page .badge.bg-warning{
  color:#3c2a08 !important;
  background:linear-gradient(135deg,#f8e7a9,#d2a640) !important;
  border:1px solid #b88b2d;
}
.alx-page .badge.bg-secondary{
  background:#5e625f !important;
}
.alx-page .btn-outline-primary{
  border-color:#c49a3e;
  color:#805e18;
  border-radius:9px;
  background:#fffaf0;
}
.alx-page .btn-outline-primary:hover{
  background:linear-gradient(135deg,#f3dda0,#c59a3c);
  border-color:#a87a22;
  color:#201706;
}
.alx-page .btn-outline-danger{
  border-radius:9px;
}
.alx-page .pagination .page-link{
  color:#6f5118;
  border-color:#e0cfaa;
  background:#fffaf0;
}
.alx-page .pagination .page-item.active .page-link{
  background:linear-gradient(135deg,#f2d98e,#bd8e2e);
  border-color:#a97920;
  color:#241906;
}
@media (max-width:900px){
  .alx-page table{min-width:980px}
  .alx-page .lux-hero-content{align-items:flex-start}
}
</style>
<div class="alx-page">
<div class="lux-hero">
  <div class="lux-hero-content">
    <div class="lux-hero-copy">
      <h4><i class="fa-solid fa-crown"></i> <?= e($viewLabels[$view]) ?></h4>
      <p>مدیریت حرفه‌ای مکاتبات سازمانی · دسترسی سریع، مرتب و هوشمند به نامه‌ها</p>
    </div>
    <div class="lux-seal" aria-hidden="true"><i class="fa-solid fa-envelope-open-text"></i></div>
    <a href="automation_compose.php" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-pen"></i> نامه جدید
    </a>
  </div>
</div>

<div class="section-title"><h5><i class="fa-solid fa-layer-group"></i> دسته‌بندی مکاتبات</h5></div>
<div class="tab-pills">
  <?php foreach ($viewLabels as $vKey => $vLabel): ?>
    <a href="automation_letters_list.php?view=<?= e($vKey) ?>" class="<?= $view === $vKey ? 'active' : '' ?>"><?= e($vLabel) ?></a>
  <?php endforeach; ?>
</div>

<div class="card p-3 mb-3 search-card">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="col-md-9"><input type="text" name="q" class="form-control form-control-sm" value="<?= e($q) ?>" placeholder="جستجو در موضوع/متن/شماره نامه..."></div>
    <div class="col-md-3"><button class="btn btn-sm btn-outline-secondary w-100"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button></div>
  </form>
</div>

<div class="card p-3 table-card">
  <?php if (!$letters): ?>
    <div class="text-center text-muted py-5"><i class="fa-regular fa-folder-open" style="font-size:2rem;display:block;margin-bottom:.5rem"></i> موردی یافت نشد.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>شماره</th><th>موضوع</th><th><?= $view === 'sent' ? 'گیرنده' : 'فرستنده' ?></th><th>وضعیت من</th><th>اولویت</th><th>وضعیت نامه</th><th>تاریخ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($letters as $l):
          $myStatus = $myStatusByLetter[$l['id']] ?? null;
          $isUnread = $myStatus && !in_array($myStatus['status'], ['خوانده شده', 'بسته شده'], true) && $myStatus['status'] !== 'در انتظار تایید';
      ?>
        <tr class="<?= $isUnread ? 'unread-row' : '' ?>">
          <td dir="ltr" class="text-nowrap"><?= e(to_persian_digits((string) ($l['letter_number'] ?? '—'))) ?></td>
          <td><a href="automation_letter_view.php?id=<?= (int) $l['id'] ?>"><?= e($l['subject']) ?></a></td>
          <td class="sender-cell"><i class="fa-solid fa-user sender-icon"></i><?= e($l['display_person_name'] ?? $l['sender_name'] ?? 'بدون نام') ?></td>
          <td class="my-status-cell"><?= $myStatus ? '<span class="my-status-badge"><i class="fa-solid fa-circle-check"></i>' . e($myStatus['status']) . '</span>' . ($myStatus['role'] === 'cc' ? ' <span class="badge bg-info text-dark">رونوشت</span>' : '') : '<span class="text-muted">—</span>' ?></td>
          <td><span class="badge bg-<?= $priorityBadge[$l['priority']] ?? 'secondary' ?>"><?= e($l['priority']) ?></span></td>
          <td><?= e($l['status']) ?></td>
          <td><?= to_jalali(substr($l['created_at'], 0, 10)) ?></td>
          <td class="text-nowrap">
            <a href="automation_letter_view.php?id=<?= (int) $l['id'] ?>" class="btn btn-sm btn-outline-primary" title="مشاهده"><i class="fa-solid fa-eye"></i></a>
            <?php if ($canDelete): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('آیا از حذف این نامه مطمئن هستید؟ این عملیات قابل بازگشت نیست.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_letter">
                <input type="hidden" name="letter_id" value="<?= (int) $l['id'] ?>">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <input type="hidden" name="page" value="<?= (int) $page ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف نامه"><i class="fa-solid fa-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?view=<?= e($view) ?>&q=<?= e($q) ?>&page=<?= $p ?>"><?= to_persian_digits((string) $p) ?></a></li>
      <?php endfor; ?>
    </ul></nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
