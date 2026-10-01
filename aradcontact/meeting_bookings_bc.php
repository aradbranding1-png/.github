<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();

if (!in_array($user['role'], ['B', 'C', 'leader', 'admin'], true)) {
    http_response_code(403);
    die('این صفحه فقط برای کارشناسان واحد B و C یا سرپرست‌هاست.');
}

$NOT_HELD_REASONS = ['مشتری در دسترس نبود', 'مشتری منصرف شد', 'زمان جلسه تغییر کرد', 'سایر'];

// -----------------------------------------------------------------
// ثبت نتیجه‌ی جلسه
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_result'])) {
    if (csrf_verify()) {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $resultText = trim((string) ($_POST['result_text'] ?? ''));
        $nextActions = trim((string) ($_POST['next_actions'] ?? ''));
        $notHeldReason = trim((string) ($_POST['not_held_reason'] ?? ''));
        $nextFollowupJalali = trim((string) ($_POST['next_followup_date'] ?? ''));
        $nextFollowupG = $nextFollowupJalali !== '' ? to_gregorian(normalize_digits($nextFollowupJalali)) : null;

        $stmt = $pdo->prepare('SELECT * FROM meeting_bookings WHERE id = ? LIMIT 1');
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if (!$booking || (!($user['role'] === 'admin') && (int) $booking['staff_id'] !== (int) $user['id'])) {
            flash_set('danger', 'دسترسی به این جلسه ندارید.');
        } elseif (!in_array($newStatus, ['held', 'not_held', 'needs_followup'], true)) {
            flash_set('danger', 'وضعیت انتخابی نامعتبر است.');
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE meeting_bookings SET status = ?, result_text = ?, next_actions = ?, not_held_reason = ?, next_followup_date = ? WHERE id = ?')
                    ->execute([
                        $newStatus, $resultText !== '' ? $resultText : null, $nextActions !== '' ? $nextActions : null,
                        $newStatus === 'not_held' ? ($notHeldReason !== '' ? $notHeldReason : null) : null,
                        $nextFollowupG, $bookingId,
                    ]);
                log_booking_history($pdo, $bookingId, 'status_changed', 'وضعیت جلسه به «' . booking_status_label($newStatus) . '» تغییر کرد.', (int) $user['id']);

                if ($newStatus === 'held') {
                    $custStmt = $pdo->prepare('SELECT owner_user_id, full_name FROM customers WHERE id = ? LIMIT 1');
                    $custStmt->execute([$booking['customer_id']]);
                    $customer = $custStmt->fetch();

                    if ($customer) {
                        // وضعیتِ مشتری همیشه باید «جلسه برگزار شد» بشه — چه نیاز به انتقال مالکیت باشه چه نه
                        // (قبلاً این‌جا شرطش به انتقال مالکیت گره خورده بود؛ یعنی اگه کارشناس از قبل خودش
                        // مالک مشتری بود، با تایید «برگزارشده» اصلاً status عوض نمی‌شد و مشتری هیچ‌وقت توی
                        // آمار جلسات دیده نمی‌شد). شرط status <> ... هم مثل meeting_verifications.php از
                        // نوشتنِ بی‌مورد و جابه‌جاییِ meeting_held_at روی یه رکوردِ از‌قبل‌«برگزار شده» جلوگیری می‌کنه.
                        record_meeting_flag_if_needed($pdo, (int) $booking['customer_id'], 'جلسه برگزار شد');
                        $pdo->prepare("UPDATE customers SET status = 'جلسه برگزار شد', meeting_held_at = NOW() WHERE id = ? AND status <> 'جلسه برگزار شد'")
                            ->execute([$booking['customer_id']]);

                        // انتقال مسئولیت مشتری از نیروی A به کارشناس برگزارکننده — فقط اینجا، فقط یه‌بار، و فقط اگه لازم باشه
                        if ((int) $customer['owner_user_id'] !== (int) $booking['staff_id']) {
                            $oldOwnerId = (int) $customer['owner_user_id'];
                            $pdo->prepare('UPDATE customers SET owner_user_id = ? WHERE id = ?')->execute([$booking['staff_id'], $booking['customer_id']]);
                            $pdo->prepare('UPDATE meeting_bookings SET responsibility_transferred = 1 WHERE id = ?')->execute([$bookingId]);
                            // در «تاریخچه ارجاع» هم ثبت شود تا ارجاع‌دهنده/گیرنده آن را ببینند
                            try { referral_log($pdo, (int) $booking['customer_id'], $oldOwnerId, (int) $booking['staff_id'], (int) $user['id'], 'meeting', 'مسئولیتِ مشتری بعد از برگزاریِ جلسه منتقل شد', 'mb' . (int) $bookingId); } catch (Throwable $e) {}

                            $ownerNames = $pdo->prepare('SELECT id, full_name FROM users WHERE id IN (?,?)');
                            $ownerNames->execute([$oldOwnerId, $booking['staff_id']]);
                            $names = [];
                            foreach ($ownerNames->fetchAll() as $r) {
                                $names[$r['id']] = $r['full_name'];
                            }
                            $transferMsg = 'مسئولیت مشتری بعد از برگزاری جلسه از «' . ($names[$oldOwnerId] ?? '—') . '» به «' . ($names[$booking['staff_id']] ?? '—') . '» منتقل شد.';
                            log_booking_history($pdo, $bookingId, 'responsibility_transferred', $transferMsg, (int) $user['id']);
                            $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)')
                                ->execute([$booking['customer_id'], $user['id'], 'followup', $transferMsg]);
                        }
                    }
                } elseif ($newStatus === 'needs_followup' || $newStatus === 'not_held') {
                    // تا وقتی جلسه برگزار نشده، مسئولیت مشتری همچنان با نیروی A می‌مونه — چیزی عوض نمی‌کنیم
                    log_booking_history($pdo, $bookingId, 'note', 'مسئولیت مشتری همچنان با نیروی A باقی موند (جلسه برگزار نشده).', (int) $user['id']);
                }

                $pdo->commit();
                flash_set('success', 'نتیجه‌ی جلسه ثبت شد.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('danger', 'خطایی پیش آمد، دوباره تلاش کنید.');
            }
        }
    }
    redirect('meeting_bookings_bc.php');
}

// -----------------------------------------------------------------
// لغو جلسه توسط کارشناس برگزارکننده
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    if (csrf_verify()) {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM meeting_bookings WHERE id = ? LIMIT 1');
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if ($booking && ($user['role'] === 'admin' || (int) $booking['staff_id'] === (int) $user['id']) && $booking['status'] === 'booked') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE meeting_bookings SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ?")
                ->execute([$reason !== '' ? $reason : null, $user['id'], $bookingId]);
            if ($booking['slot_id']) {
                $pdo->prepare("UPDATE calendar_slots SET status = 'free' WHERE id = ?")->execute([$booking['slot_id']]);
            }
            log_booking_history($pdo, $bookingId, 'cancelled', 'جلسه لغو شد. علت: ' . ($reason !== '' ? $reason : '(ثبت نشده)'), (int) $user['id']);
            $pdo->commit();
            flash_set('success', 'جلسه لغو شد و بازه‌ی زمانی آزاد شد.');
        } else {
            flash_set('danger', 'امکان لغو این جلسه وجود ندارد.');
        }
    }
    redirect('meeting_bookings_bc.php');
}

if ($user['role'] === 'admin') {
    $bookings = $pdo->query("SELECT mb.*, c.full_name AS customer_name, c.mobile AS customer_mobile,
                                     u1.full_name AS staff_name, u2.full_name AS booked_by_name
                              FROM meeting_bookings mb
                              JOIN customers c ON c.id = mb.customer_id
                              JOIN users u1 ON u1.id = mb.staff_id
                              JOIN users u2 ON u2.id = mb.booked_by
                              ORDER BY mb.meeting_date DESC, mb.start_time DESC")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT mb.*, c.full_name AS customer_name, c.mobile AS customer_mobile,
                                   u1.full_name AS staff_name, u2.full_name AS booked_by_name
                            FROM meeting_bookings mb
                            JOIN customers c ON c.id = mb.customer_id
                            JOIN users u1 ON u1.id = mb.staff_id
                            JOIN users u2 ON u2.id = mb.booked_by
                            WHERE mb.staff_id = ?
                            ORDER BY mb.meeting_date DESC, mb.start_time DESC");
    $stmt->execute([$user['id']]);
    $bookings = $stmt->fetchAll();
}

$pageTitle = 'جلسات تقویم من';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.mb-page{--mb-line:#e7e2d3;--mb-ink:#1c1917;--mb-muted:#78716c;--mb-gold:#c9a24b;--mb-gold-2:#f1dfa8;}

.mb-page .mb-topbtn{border-radius:12px;font-weight:700}

.mb-page .card{border:1px solid var(--mb-line);border-radius:20px;box-shadow:0 4px 20px -16px rgba(28,25,23,.3);}
.mb-page .card > h5{
  display:flex;align-items:center;gap:.55rem;font-weight:800;color:var(--mb-ink);
}
.mb-page .card > h5 i{
  width:32px;height:32px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--mb-gold) 130%);color:#fff;font-size:.85rem;
}

.mb-page .mb-item{
  border:1px solid var(--mb-line) !important;border-radius:16px !important;background:#fdfcf9;transition:.15s ease;
}
.mb-page .mb-item:hover{background:#faf8f2;box-shadow:0 4px 14px -10px rgba(28,25,23,.2)}
.mb-page .mb-item b{color:var(--mb-ink)}
.mb-page .badge{border-radius:999px;font-weight:700;padding:.4rem .8rem;font-size:.74rem}
.mb-page .mb-item a.small{color:var(--mb-gold);text-decoration:none;font-weight:600}
.mb-page .mb-item a.small:hover{color:#8a6a1e}
.mb-page .alert{border-radius:14px}

.mb-page .mb-item form{background:#faf9f5;border:1px dashed var(--mb-line);border-radius:14px;padding:.9rem 1rem;}
.mb-page .mb-item .border-top{border-top:none !important}
.mb-page .mb-item .form-label{font-size:.76rem;font-weight:700;color:#57534e}
.mb-page .mb-item .form-control,
.mb-page .mb-item .form-select{
  border:1px solid var(--mb-line);border-radius:10px;background:#fff;
}
.mb-page .mb-item .form-control:focus,
.mb-page .mb-item .form-select:focus{border-color:var(--mb-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15)}
.mb-page .mb-item .btn-primary{
  border:none;border-radius:10px;font-weight:700;background:linear-gradient(135deg,var(--mb-gold-2),var(--mb-gold));color:#241d0a;
  box-shadow:0 6px 14px -6px rgba(201,162,75,.6);
}
.mb-page .mb-item .btn-outline-danger{border-radius:10px;font-weight:700}
.mb-page .mb-item .btn-outline-primary{border-radius:10px;font-weight:700}

@media (max-width:767.98px){
  .mb-page .card{border-radius:16px}
  .mb-page .mb-item{padding:1rem !important}
}
</style>

<div class="calls-report-page mb-page">
<a href="calendar_manage.php" class="btn btn-sm btn-outline-secondary mb-3 mb-topbtn"><i class="fa-solid fa-calendar-days"></i> مدیریت تقویم کاری</a>

<div class="card p-3">
  <h5 class="mb-3"><i class="fa-solid fa-calendar-check"></i> جلسات <?= $user['role'] === 'admin' ? '(همه)' : 'تقویم من' ?></h5>
  <?php if (!$bookings): ?>
    <p class="text-muted small mb-0">هنوز جلسه‌ای در تقویم شما رزرو نشده.</p>
  <?php else: ?>
    <?php foreach ($bookings as $b): ?>
      <div class="border rounded p-3 mb-3 mb-item">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
          <div>
            <b><?= e($b['customer_name']) ?></b> <span class="text-muted small" dir="ltr"><?= e($b['customer_mobile']) ?></span>
            <div class="small text-muted">رزروکننده: <?= e($b['booked_by_name']) ?> — <?= to_jalali($b['meeting_date']) ?> ساعت <?= to_persian_digits(substr($b['start_time'], 0, 5)) ?></div>
          </div>
          <div class="align-self-start text-end">
            <span class="badge <?= booking_status_badge_class($b['status']) ?>"><?= booking_status_label($b['status']) ?></span>
            <a href="meeting_booking_history_view.php?id=<?= (int) $b['id'] ?>" class="d-block small mt-1"><i class="fa-solid fa-clock-rotate-left"></i> تاریخچه</a>
          </div>
        </div>
        <?php if ($b['notes']): ?>
          <div class="alert alert-info small py-2 mb-2"><b>توضیحات تکمیلی نیروی A:</b> <?= nl2br(e($b['notes'])) ?></div>
        <?php endif; ?>
        <?php if ($b['result_text']): ?>
          <div class="small mb-2"><b>نتیجه‌ی مذاکره:</b> <?= nl2br(e($b['result_text'])) ?></div>
        <?php endif; ?>

        <?php if ($b['status'] === 'booked'): ?>
          <form method="post" class="mt-3 border-top pt-3" id="resultForm<?= (int) $b['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
            <div class="mb-2">
              <label class="form-label small">وضعیت نهایی جلسه</label>
              <select name="new_status" class="form-select form-select-sm result-status-select" data-target="<?= (int) $b['id'] ?>">
                <option value="held">برگزارشده</option>
                <option value="not_held">برگزارنشده</option>
                <option value="needs_followup">نیازمند پیگیری</option>
              </select>
            </div>
            <div class="mb-2 held-fields-<?= (int) $b['id'] ?>">
              <label class="form-label small">نتیجه‌ی مذاکره</label>
              <textarea name="result_text" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-2 not-held-fields-<?= (int) $b['id'] ?>" style="display:none;">
              <label class="form-label small">علت برگزارنشدن</label>
              <select name="not_held_reason" class="form-select form-select-sm">
                <?php foreach ($NOT_HELD_REASONS as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small">اقدامات بعدی</label>
              <textarea name="next_actions" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-2 followup-date-fields-<?= (int) $b['id'] ?>" style="display:none;">
              <label class="form-label small">زمان پیگیری بعدی</label>
              <input type="text" name="next_followup_date" class="form-control form-control-sm jalali-date" dir="ltr" autocomplete="off" placeholder="۱۴۰۵/۰۷/۰۱">
            </div>
            <button type="submit" name="set_result" value="1" class="btn btn-sm btn-primary">ثبت نتیجه</button>
          </form>
          <form method="post" class="mt-2" onsubmit="return confirm('این جلسه لغو بشه؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
            <input type="text" name="cancel_reason" class="form-control form-control-sm d-inline-block mb-2" style="max-width:300px;" placeholder="علت لغو (اختیاری)">
            <button type="submit" name="cancel_booking" value="1" class="btn btn-sm btn-outline-danger d-block">لغو جلسه</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</div>

<script>
document.querySelectorAll('.result-status-select').forEach(function (sel) {
  var id = sel.getAttribute('data-target');
  function sync() {
    var val = sel.value;
    document.querySelector('.held-fields-' + id).style.display = val === 'held' ? '' : 'none';
    document.querySelector('.not-held-fields-' + id).style.display = val === 'not_held' ? '' : 'none';
    document.querySelector('.followup-date-fields-' + id).style.display = val === 'needs_followup' ? '' : 'none';
  }
  sel.addEventListener('change', sync);
  sync();
});
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
