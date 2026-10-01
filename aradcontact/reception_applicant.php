<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reception_functions.php';
$user = require_login();
$pdo = db();

// ادمینِ پذیرش (بانک متقاضیان / مصاحبه‌های حضوری) همه‌ی پرونده‌ها را می‌بیند؛
// کارشناسِ پذیرش فقط پرونده‌های خودش را.
$isAdmin = user_can('admin_reception_candidates', $user) || user_can('admin_reception_inperson', $user);
$isAgent = user_can('reception_agent_panel', $user);
if (!$isAdmin && !$isAgent) {
    perm_deny('', $user);
}

$moduleReady = reception_module_ready($pdo);
if (!$moduleReady) {
    redirect('dashboard.php');
}

$applicantId = (int) ($_GET['id'] ?? 0);
$applicant = reception_get_applicant($pdo, $applicantId);
if (!$applicant) {
    redirect($isAdmin ? 'admin/admin_reception_applicants_bank.php' : 'reception_dashboard.php');
}

// کارشناسانِ عادی فقط اجازه‌ی دیدنِ متقاضیِ خودشان را دارند؛ ادمین/ادمینِ پذیرش همه را می‌بینند.
if (!$isAdmin && (int) $applicant['assigned_agent_id'] !== (int) $user['id']) {
    redirect('reception_dashboard.php');
}

$errors = [];
$success = null;
$bookedMeetingInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete_note') {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            if ($noteId <= 0) {
                $errors[] = 'یادداشتِ انتخاب‌شده معتبر نیست.';
            } else {
                try {
                    if (reception_delete_applicant_note($pdo, $noteId, $applicantId, (int) $user['id'])) {
                        reception_touch_activity($pdo, $applicantId);
                        reception_log_action($pdo, $applicantId, (int) $user['id'], 'note_deleted', 'حذفِ یادداشتِ #' . $noteId);
                        $success = 'یادداشت با موفقیت حذف شد.';
                    } else {
                        $errors[] = 'یادداشت یافت نشد یا اجازهٔ حذفِ آن را ندارید.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'خطا در حذفِ یادداشت.';
                }
            }
        } elseif ($action === 'log_call') {
            $phone = trim((string) ($_POST['phone'] ?? $applicant['mobile']));
            $duration = (int) ($_POST['duration_seconds'] ?? 0);
            $result = trim((string) ($_POST['result'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $validResults = ['success', 'no_answer', 'cancelled', 'followup', 'wrong_number', 'other'];
            if (!in_array($result, $validResults, true)) {
                $errors[] = 'نتیجه‌ی تماس معتبر نیست.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO reception_calls (applicant_id, agent_user_id, phone, started_at, duration_seconds, result, notes, created_at) VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW())");
                    $stmt->execute([$applicantId, (int) $user['id'], $phone, $duration ?: null, $result, $notes]);

                    $statusMap = [
                        'success' => 'success', 'no_answer' => 'no_answer', 'cancelled' => 'cancelled',
                        'followup' => 'followup', 'wrong_number' => 'wrong_number', 'other' => $applicant['status'],
                    ];
                    $newStatus = $statusMap[$result] ?? $applicant['status'];
                    if ($newStatus !== $applicant['status']) {
                        $pdo->prepare('UPDATE reception_applicants SET status = ? WHERE id = ?')->execute([$newStatus, $applicantId]);
                        reception_record_status_change($pdo, $applicantId, $applicant['status'], $newStatus, (int) $user['id']);
                    }
                    $pdo->prepare('UPDATE reception_applicants SET call_count = call_count + 1, last_activity_at = NOW() WHERE id = ?')->execute([$applicantId]);
                    reception_log_action($pdo, $applicantId, (int) $user['id'], 'call_logged', 'نتیجه: ' . $result);
                    $pdo->commit();
                    rp_on_call($pdo, $applicantId, $result, (int) $user['id']);
                    $success = 'تماس با موفقیت ثبت شد.';
                    $applicant = reception_get_applicant($pdo, $applicantId);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $errors[] = 'خطا در ثبتِ تماس.';
                }
            }
        } elseif ($action === 'log_followup') {
            $type = trim((string) ($_POST['activity_type'] ?? 'note'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($notes === '') {
                $errors[] = 'متنِ پیگیری نمی‌تواند خالی باشد.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO reception_followups (applicant_id, agent_user_id, activity_type, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$applicantId, (int) $user['id'], $type, $notes]);
                    reception_touch_activity($pdo, $applicantId);
                    reception_log_action($pdo, $applicantId, (int) $user['id'], 'followup_logged', $type);
                    $success = 'پیگیری با موفقیت ثبت شد.';
                    $pastNotes = reception_applicant_notes($pdo, $applicantId);
                } catch (Throwable $e) {
                    $errors[] = 'خطا در ثبتِ پیگیری.';
                }
            }
        } elseif ($action === 'assign_supervisor') {
            $supervisorId = (int) ($_POST['supervisor_id'] ?? 0);
            $chk = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'leader' LIMIT 1");
            $chk->execute([$supervisorId]);
            $supervisor = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$supervisor) {
                $errors[] = 'سرپرستِ انتخاب‌شده معتبر نیست.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $oldStatus = $applicant['status'];
                    $stmt = $pdo->prepare("UPDATE reception_applicants SET supervisor_user_id = ?, referred_at = NOW(), referred_by = ?, status = 'referred_to_supervisor', last_activity_at = NOW() WHERE id = ?");
                    $stmt->execute([$supervisorId, (int) $user['id'], $applicantId]);
                    reception_record_status_change($pdo, $applicantId, $oldStatus, 'referred_to_supervisor', (int) $user['id']);

                    // افزودنِ کارشناس به تیمِ سرپرست (در صورتِ وجودِ حسابِ کاربریِ متقاضی و جدولِ teams)
                    if (!empty($applicant['user_id'])) {
                        try {
                            $teamStmt = $pdo->prepare('SELECT id FROM teams WHERE leader_user_id = ? LIMIT 1');
                            $teamStmt->execute([$supervisorId]);
                            $teamId = $teamStmt->fetchColumn();
                            if ($teamId !== false) {
                                $pdo->prepare('UPDATE users SET team_id = ? WHERE id = ?')->execute([(int) $teamId, (int) $applicant['user_id']]);
                            }
                        } catch (Throwable $e) {
                            // اگر جدولِ teams در دسترس نبود، از این مرحله بی‌سروصدا عبور می‌کنیم.
                        }
                    }

                    reception_notify(
                        $pdo,
                        $supervisorId,
                        $applicantId,
                        'ارجاعِ کارشناسِ جدید',
                        trim($applicant['first_name'] . ' ' . $applicant['last_name']) . ' (' . $applicant['mobile'] . ') توسطِ ' . $user['full_name'] . ' در تاریخِ ' . to_jalali(date('Y-m-d H:i:s')) . ' به شما ارجاع داده شد.',
                        '../reception_applicant.php?id=' . $applicantId
                    );

                    // پیامِ خوش‌آمدگویی خودکار از سرپرست به کارشناس (best-effort).
                    if (!empty($applicant['user_id']) && !empty($supervisor['meeting_url'])) {
                        $welcomeText = reception_supervisor_welcome_message(
                            $applicant['first_name'],
                            $supervisor['full_name'],
                            $supervisor['meeting_url'],
                            $supervisor['mobile']
                        );
                        reception_send_chat_message($pdo, $supervisorId, (int) $applicant['user_id'], $welcomeText);
                    }

                    // در جهتِ عکس هم یک پیامِ خودکار از طرفِ خودِ کارشناس برای سرپرست فرستاده می‌شود
                    // تا سرپرست هم در همان گفتگو از اضافه‌شدنِ این عضوِ جدید به تیمش مطلع شود.
                    if (!empty($applicant['user_id'])) {
                        $joinText = reception_specialist_join_message(
                            trim($applicant['first_name'] . ' ' . $applicant['last_name']),
                            $applicant['mobile']
                        );
                        reception_send_chat_message($pdo, (int) $applicant['user_id'], $supervisorId, $joinText);
                    }

                    reception_log_action($pdo, $applicantId, (int) $user['id'], 'assign_supervisor', 'ارجاع به سرپرست #' . $supervisorId);
                    $pdo->commit();
                    rp_on_joined($pdo, $applicantId, (int) $user['id'], 'ارجاع به سرپرست: ' . $supervisor['full_name']);
                    $success = 'متقاضی با موفقیت به سرپرست ارجاع داده شد.';
                    $applicant = reception_get_applicant($pdo, $applicantId);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $errors[] = 'خطا در ارجاع به سرپرست.';
                }
            }
        } elseif ($action === 'book_meeting_slot') {
            $slotId = (int) ($_POST['slot_id'] ?? 0);
            if ($slotId <= 0) {
                $errors[] = 'یک تایم را انتخاب کنید.';
            } else {
                $bookRes = reception_book_meeting_slot($pdo, $slotId, $applicantId, (int) $user['id']);
                if (!$bookRes['ok']) {
                    $errors[] = $bookRes['message'];
                } else {
                    $success = $bookRes['message'];
                    $slot = $bookRes['slot'];
                    reception_touch_activity($pdo, $applicantId);
                    reception_log_action($pdo, $applicantId, (int) $user['id'], 'book_meeting_slot', 'رزروِ تایمِ #' . $slotId);

                    // اطلاعاتِ لینک/زمانِ جلسه برایِ نمایش به نیرویِ پذیرش (تا بتواند برایِ مخاطبش کپی/ارسال کند)
                    // و همچنین پیامِ خودکار از سویِ نیرویِ پذیرش برای کارشناسِ توسعه، حاویِ تاریخ/ساعتِ دقیقِ جلسه.
                    if ($slot) {
                        $svStmt = $pdo->prepare('SELECT full_name, meeting_url FROM users WHERE id = ? LIMIT 1');
                        $svStmt->execute([(int) $slot['supervisor_user_id']]);
                        $svRow = $svStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $meetingText = reception_agent_meeting_message(
                            $applicant['first_name'],
                            (string) ($svRow['full_name'] ?? ''),
                            to_jalali($slot['slot_date']),
                            $slot['start_time'],
                            (string) ($svRow['meeting_url'] ?? '')
                        );
                        if (!empty($applicant['user_id'])) {
                            reception_send_chat_message($pdo, (int) $user['id'], (int) $applicant['user_id'], $meetingText);
                        }

                        // اطلاع‌رسانیِ خودِ سرپرست: یک پیامِ چت هم برایِ خودِ سرپرست ارسال می‌شود
                        // تا بجِ پیامِ خوانده‌نشده برایش فعال شود و بداند باید به بخشِ «جلسات» سر بزند.
                        $noticeText = reception_supervisor_booking_notice_message(
                            trim($applicant['first_name'] . ' ' . $applicant['last_name']),
                            to_jalali($slot['slot_date']),
                            $slot['start_time'],
                            $user['full_name']
                        );
                        reception_send_chat_message($pdo, (int) $user['id'], (int) $slot['supervisor_user_id'], $noticeText);

                        $bookedMeetingInfo = [
                            'supervisor_name' => (string) ($svRow['full_name'] ?? ''),
                            'meeting_url'     => (string) ($svRow['meeting_url'] ?? ''),
                            'date_jalali'     => to_jalali($slot['slot_date']),
                            'time'            => $slot['start_time'],
                            'copy_text'       => $meetingText,
                        ];
                    }
                    $applicant = reception_get_applicant($pdo, $applicantId);
                }
            }
        } elseif ($action === 'cancel_meeting_booking') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $cancelRes = reception_cancel_meeting_booking($pdo, $bookingId, $applicantId);
            if (!$cancelRes['ok']) {
                $errors[] = $cancelRes['message'];
            } else {
                $success = $cancelRes['message'];
                reception_log_action($pdo, $applicantId, (int) $user['id'], 'cancel_meeting_booking', 'لغوِ رزروِ #' . $bookingId);
            }
        } elseif ($action === 'log_inperson_interview') {
            $interviewDateRaw = trim((string) ($_POST['interview_date'] ?? ''));
            $interviewTime    = trim((string) ($_POST['interview_time'] ?? ''));
            $location         = trim((string) ($_POST['location'] ?? ''));
            $notes            = trim((string) ($_POST['notes'] ?? ''));

            $interviewDateRaw = function_exists('to_english_digits_safe')
                ? to_english_digits_safe($interviewDateRaw)
                : $interviewDateRaw;

            $interviewDateGreg = '';
            if ($interviewDateRaw !== '') {
                if (function_exists('to_gregorian')) {
                    $interviewDateGreg = (string) (to_gregorian($interviewDateRaw) ?: '');
                } elseif (function_exists('jalali_to_gregorian')) {
                    $interviewDateGreg = (string) (jalali_to_gregorian($interviewDateRaw) ?: '');
                }
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $interviewDateGreg)) {
                $errors[] = 'تاریخِ مصاحبه حضوری معتبر نیست.';
            } elseif (!reception_inperson_table_ready($pdo)) {
                $errors[] = 'جدولِ جلسات مصاحبه حضوری هنوز ایجاد نشده است.';
            } else {
                $res = reception_log_inperson_interview($pdo, $applicantId, (int) $user['id'], [
                    'interview_date'     => $interviewDateGreg,
                    'interview_time'     => $interviewTime,
                    'location'           => $location,
                    'notes'              => $notes,
                    'status'             => 'scheduled',
                    'supervisor_user_id' => (int) ($_POST['supervisor_user_id'] ?? ($applicant['supervisor_user_id'] ?? 0)),
                ]);
                if (!$res['ok']) {
                    $errors[] = $res['message'];
                } else {
                    reception_touch_activity($pdo, $applicantId);
                    $success = $res['message'];
                    $applicant = reception_get_applicant($pdo, $applicantId);
                }
            }
        } elseif ($action === 'inperson_status') {
            $interviewId = (int) ($_POST['interview_id'] ?? 0);
            $newIvStatus = (string) ($_POST['iv_status'] ?? '');
            $ok = reception_update_inperson_status(
                $pdo,
                $interviewId,
                $newIvStatus,
                (int) $user['id'],
                trim((string) ($_POST['result_note'] ?? '')),
                $isAdmin ? null : (int) $user['id']
            );
            if ($ok) {
                reception_touch_activity($pdo, $applicantId);
                $success = 'نتیجه‌ی مصاحبه‌ی حضوری ثبت شد: ' . reception_inperson_status_label($newIvStatus);
            } else {
                $errors[] = 'تغییرِ وضعیتِ مصاحبه انجام نشد (یا اجازه‌ی آن را ندارید).';
            }
        } elseif ($action === 'delete_inperson_interview') {
            $interviewId = (int) ($_POST['interview_id'] ?? 0);
            if ($interviewId <= 0) {
                $errors[] = 'جلسه‌ی انتخاب‌شده معتبر نیست.';
            } elseif (!reception_inperson_table_ready($pdo)) {
                $errors[] = 'جدولِ جلسات مصاحبه حضوری هنوز ایجاد نشده است.';
            } elseif (reception_delete_inperson_interview($pdo, $interviewId, $applicantId, (int) $user['id'])) {
                reception_touch_activity($pdo, $applicantId);
                reception_log_action($pdo, $applicantId, (int) $user['id'], 'inperson_interview_deleted', 'حذفِ جلسه‌ی #' . $interviewId);
                $success = 'جلسه مصاحبه حضوری حذف شد.';
            } else {
                $errors[] = 'حذفِ جلسه ناموفق بود یا اجازه‌ی آن را ندارید.';
            }
        }
    }
}

$phones = reception_applicant_phones($pdo, $applicantId);
if (!$phones) {
    $phones = [['phone' => $applicant['mobile'], 'phone_normalized' => $applicant['mobile_normalized'], 'is_primary' => 1]];
}
$timeline = reception_applicant_timeline($pdo, $applicantId);
$pastNotes = reception_applicant_notes($pdo, $applicantId);
$socialNetworks = reception_load_social_networks($pdo, true);
$statuses = reception_load_statuses($pdo, true);
$supervisors = $pdo->query("SELECT id, full_name, mobile FROM users WHERE role = 'leader' AND is_active = 1 ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$slotsReady = reception_meeting_slots_ready($pdo);
$availableSlotsBySupervisor = [];
if ($slotsReady) {
    foreach ($supervisors as $sv) {
        $svSlots = reception_load_supervisor_slots($pdo, (int) $sv['id'], true, true);
        $svSlots = array_values(array_filter($svSlots, static function ($s) { return $s['remaining'] > 0; }));
        if ($svSlots) {
            $availableSlotsBySupervisor[(int) $sv['id']] = ['name' => $sv['full_name'], 'slots' => $svSlots];
        }
    }
}
$myBookings = [];
if ($slotsReady) {
    try {
        $stmt = $pdo->prepare("SELECT bk.*, s.slot_date, s.start_time, su.full_name AS supervisor_name
            FROM reception_meeting_bookings bk
            JOIN reception_meeting_slots s ON s.id = bk.slot_id
            LEFT JOIN users su ON su.id = s.supervisor_user_id
            WHERE bk.applicant_id = ? AND bk.status = 'booked'
            ORDER BY s.slot_date ASC, s.start_time ASC");
        $stmt->execute([$applicantId]);
        $myBookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $myBookings = [];
    }
}

$inpersonReady = reception_inperson_table_ready($pdo);
$myInpersonInterviews = $inpersonReady ? reception_applicant_inperson_interviews($pdo, $applicantId) : [];

$pageTitle = 'پروندهٔ متقاضی';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.rap-page{--rap-line:#e7e2d3;--rap-ink:#1c1917;--rap-muted:#78716c;--rap-gold:#c9a24b;--rap-gold-2:#f1dfa8;}
.rap-page .rap-hero{
  background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rap-gold) 130%);
  border-radius:16px; padding:20px 22px; color:#f6efdd; position:relative; overflow:hidden;
  box-shadow:0 10px 30px -18px rgba(28,25,23,.55);
}
.rap-page .rap-hero::after{content:'';position:absolute;inset:0;background:radial-gradient(600px 160px at 85% -20%, rgba(241,223,168,.25), transparent 60%);pointer-events:none}
.rap-page .rap-hero h5{color:#f6efdd;font-weight:800}
.rap-page .rap-hero p{color:#e7ddc4}
.rap-page .card{border:1px solid var(--rap-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rap-page .btn-primary{background:linear-gradient(135deg,var(--rap-gold-2),var(--rap-gold));border:none;color:#241708;font-weight:700}
.rap-page .btn-primary:hover{filter:brightness(.97);color:#241708}
.rap-page .btn-outline-secondary{border-color:var(--rap-line);color:var(--rap-ink)}
.rap-page .badge-status{padding:.4em .8em;border-radius:20px;font-weight:600;font-size:.8rem}
.rap-page .rap-action-btn{border-radius:12px;padding:.65rem 1rem;font-weight:700;border:none;color:#241708;background:linear-gradient(135deg,var(--rap-gold-2),var(--rap-gold));box-shadow:0 6px 14px -8px rgba(201,162,75,.6)}
.rap-page .rap-action-btn.secondary{background:#fff;color:var(--rap-ink);border:1px solid var(--rap-line);box-shadow:none}
.rap-page .rap-timeline{position:relative;padding-right:24px;border-right:2px solid var(--rap-line)}
.rap-page .rap-timeline-item{position:relative;padding-bottom:18px}
.rap-page .rap-timeline-item::before{content:'';position:absolute;right:-30px;top:4px;width:12px;height:12px;border-radius:50%;background:linear-gradient(135deg,var(--rap-gold-2),var(--rap-gold));border:2px solid #fff;box-shadow:0 0 0 2px var(--rap-gold)}
.rap-page .rap-social-btn{display:inline-flex;align-items:center;gap:.4rem;border:1px solid var(--rap-line);border-radius:20px;padding:.4rem .9rem;color:var(--rap-ink);text-decoration:none;background:#fff}
.rap-page .rap-social-btn:hover{border-color:var(--rap-gold);background:#faf7ef}
.rap-page .rap-meeting-copy-box{border:1px dashed var(--rap-gold);border-radius:10px;background:#fdf9ef}
.rap-page .rap-notes-card{background:linear-gradient(180deg,#fffdf8 0%,#fff 100%)}
.rap-page .rap-notes-count{background:linear-gradient(135deg,var(--rap-gold-2),var(--rap-gold));color:#241708}
.rap-note-item{padding:12px 0;border-bottom:1px solid var(--rap-line)}
.rap-note-item:first-child{padding-top:0}
.rap-note-item:last-child{border-bottom:0;padding-bottom:0}
.rap-note-item .text-muted{font-size:.72rem}
.rap-inperson-item{background:#fff8e6;border:1px solid #f1dfa8}
</style>

<div class="rap-page">
<div class="d-flex gap-2 flex-wrap mb-3">
  <a href="<?= ($isAdmin && user_can('admin_reception_candidates', $user)) ? 'admin/admin_reception_applicants_bank.php' : 'reception_dashboard.php' ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i> بازگشت</a>
  <a href="reception_inperson.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-building-user"></i> مصاحبه‌های حضوری</a>
</div>

<div class="rap-hero mb-4">
  <div class="d-flex align-items-center justify-content-between position-relative flex-wrap gap-2">
    <div>
      <h5 class="mb-1"><?= e(trim($applicant['first_name'] . ' ' . $applicant['last_name'])) ?></h5>
      <p class="small mb-0" dir="ltr"><?= e($applicant['mobile']) ?></p>
    </div>
    <span class="badge badge-status bg-<?= e(reception_status_color($pdo, $applicant['status'])) ?>"><?= e(reception_status_label($pdo, $applicant['status'])) ?></span>
  </div>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert alert-success py-2"><?= e($success) ?></div><?php endif; ?>

<?php
// ─── کارتِ «مسیرِ پیگیری»: مرحله‌ی فعلی + اقدامِ بعدی + دکمه‌های یک‌کلیکی ───
$rpRow = null;
$rpBox = null;
if (!empty($applicant['assigned_agent_id']) && rp_ready($pdo)) {
    $rpRow = rp_ensure($pdo, $applicantId, (int) $user['id'], true);
    $rpBox = rp_box_of($pdo, $applicantId);
}
$rpGuard = false;
if ($rpRow && $rpBox !== 'closed' && (int) $applicant['assigned_agent_id'] === (int) $user['id']) {
    $rpDue = (rp_ts($rpRow['next_action_at']) ?? PHP_INT_MAX) <= time();
    $rpGuard = $rpDue && ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_GET['focus']));
}
?>
<?php if ($rpRow && $rpBox): $rpm = rp_boxes()[$rpBox]; $rpActs = rp_action_meta(); ?>
<div class="card p-3 mb-3" id="rpCard" style="border:2px solid #f1dfa8;border-radius:16px;background:linear-gradient(135deg,#fffdf6,#fff)">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
      <div class="small text-muted">مسیرِ پیگیری — مرحله‌ی فعلی</div>
      <div class="fw-bold fs-6"><i class="fa-solid <?= e($rpm['icon']) ?> text-warning"></i> <?= e($rpm['label']) ?>
        <?php if ($rpRow['stage'] === 'invited'): $rs = rp_reminder_states()[$rpRow['reminder_status']] ?? rp_reminder_states()['none']; ?>
          <span class="badge text-bg-<?= e($rs['color']) ?>"><?= e($rs['label']) ?></span>
        <?php elseif ($rpRow['stage'] === 'closed'): $oc = rp_outcomes()[$rpRow['outcome']] ?? rp_outcomes()['other']; ?>
          <span class="badge text-bg-<?= e($oc['color']) ?>"><?= e($oc['label']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($rpRow['meeting_at']) && in_array($rpRow['stage'], ['invited', 'attended', 'no_show'], true)): ?>
        <div class="small mt-1"><i class="fa-solid <?= $rpRow['meeting_kind'] === 'inperson' ? 'fa-building' : 'fa-video' ?>"></i>
          جلسه‌ی <?= $rpRow['meeting_kind'] === 'inperson' ? 'حضوری' : 'آنلاین' ?>: <b><?= rp_jdt($rpRow['meeting_at']) ?></b>
          <?php if ($rpRow['stage'] === 'invited'): ?><span class="text-muted">(<?= e(rp_relative($rpRow['meeting_at'])) ?>)</span><?php endif; ?></div>
      <?php endif; ?>
      <?php if ($rpRow['stage'] !== 'closed' && $rpRow['next_action_type']): $late = (rp_ts($rpRow['next_action_at']) ?? PHP_INT_MAX) <= time(); ?>
        <div class="small mt-1 <?= $late ? 'text-danger fw-bold' : '' ?>"><i class="fa-solid fa-forward"></i> اقدامِ بعدی:
          <?= e(rp_action_types()[$rpBox === 'awaiting' ? 'attendance' : $rpRow['next_action_type']] ?? '') ?> —<?= e(rp_relative($rpRow['next_action_at'])) ?></div>
      <?php endif; ?>
    </div>
    <a href="reception_pipeline.php?box=<?= e($rpBox) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-route"></i> مسیرِ پیگیری</a>
  </div>
  <div class="d-flex flex-wrap gap-2 mt-2">
    <?php foreach (rp_box_actions($rpBox) as $act): $am = $rpActs[$act]; ?>
      <?php if ($act === 'invite'): ?>
        <a class="btn btn-sm btn-<?= e($am['style']) ?>" href="#meeting-section" onclick="document.getElementById('meeting-section').scrollIntoView({behavior:'smooth'});return false;"><i class="fa-solid <?= e($am['icon']) ?>"></i> <?= e($am['label']) ?></a>
      <?php else: ?>
        <button type="button" class="btn btn-sm btn-<?= in_array($act, ['close', 'followup', 'reopen'], true) ? 'outline-' . $am['style'] : $am['style'] ?>" data-act="<?= e($act) ?>" data-kind="<?= e($am['kind']) ?>"
                data-id="<?= (int) $applicantId ?>" data-name="<?= e(trim($applicant['first_name'] . ' ' . $applicant['last_name'])) ?>"><i class="fa-solid <?= e($am['icon']) ?>"></i> <?= e($am['label']) ?></button>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php if (($_GET['focus'] ?? '') === 'meeting'): ?>
  <div class="alert alert-warning py-2"><i class="fa-solid fa-calendar-plus"></i> برای این متقاضی یک جلسه‌ی جدید (آنلاین یا حضوری) از بخشِ جلسات انتخاب کنید؛ سوابقِ قبلی حفظ می‌شود.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card p-3 mb-3">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-id-card text-warning"></i> اطلاعاتِ متقاضی</h6>
      <table class="table table-sm mb-0">
        <tr><th class="text-muted small">تاریخِ ورود</th><td class="small"><?= to_jalali($applicant['created_at']) ?></td></tr>
        <tr><th class="text-muted small">منبعِ ورود</th><td class="small"><?= e($applicant['source']) ?></td></tr>
        <tr><th class="text-muted small">کارشناسِ پذیرش</th><td class="small"><?= e($applicant['agent_name'] ?? '—') ?></td></tr>
        <tr><th class="text-muted small">سرپرست</th><td class="small"><?= e($applicant['supervisor_name'] ?? '—') ?></td></tr>
        <tr><th class="text-muted small">تاریخِ ارجاع</th><td class="small"><?= $applicant['referred_at'] ? to_jalali($applicant['referred_at']) : '—' ?></td></tr>
        <tr><th class="text-muted small">تعدادِ تماس</th><td class="small"><?= to_persian_digits((string) $applicant['call_count']) ?></td></tr>
        <tr><th class="text-muted small">آخرین فعالیت</th><td class="small"><?= $applicant['last_activity_at'] ? to_jalali($applicant['last_activity_at']) : '—' ?></td></tr>
      </table>

      <div class="mt-2 d-flex flex-wrap gap-2">
        <?php foreach ($phones as $ph): ?>
          <a href="tel:<?= e($ph['phone']) ?>" class="rap-social-btn" dir="ltr">
            <i class="fa-solid fa-phone"></i> <?= e($ph['phone']) ?><?= $ph['is_primary'] ? ' (اصلی)' : '' ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-share-nodes text-warning"></i> شبکه اجتماعی</h6>
      <div class="d-flex flex-wrap gap-2">
        <?php if (!$socialNetworks): ?><p class="text-muted small mb-0">شبکه‌ی اجتماعیِ فعالی تعریف نشده است.</p><?php endif; ?>
        <?php foreach ($socialNetworks as $nw): ?>
          <a href="<?= e(reception_social_link($nw, $applicant['mobile_normalized'] ?: $applicant['mobile'])) ?>" target="_blank" rel="noopener" class="rap-social-btn">
            <i class="<?= e($nw['icon']) ?>"></i> <?= e($nw['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card p-3">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-user-tie text-warning"></i> تعیینِ سرپرست</h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_supervisor">
        <select name="supervisor_id" class="form-select form-select-sm mb-2" required>
          <option value="">— انتخابِ سرپرست —</option>
          <?php foreach ($supervisors as $sv): ?>
            <option value="<?= (int) $sv['id'] ?>" <?= (int) $applicant['supervisor_user_id'] === (int) $sv['id'] ? 'selected' : '' ?>><?= e($sv['full_name']) ?> — <?= e($sv['mobile']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="rap-action-btn w-100"><i class="fa-solid fa-paper-plane"></i> ارجاع به سرپرست</button>
      </form>
    </div>

    <div id="meeting-section" style="scroll-margin-top:80px"></div>
    <?php if ($slotsReady): ?>
    <div class="card p-3 mt-3 <?= ($_GET['focus'] ?? '') === 'meeting' ? 'border-warning border-2' : '' ?>">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-video text-warning"></i> میتینگ آنلاین با سرپرست <span class="badge rounded-pill text-bg-info ms-1" style="font-size:.65rem">آنلاین</span></h6>

      <?php if ($bookedMeetingInfo): ?>
        <div class="rap-meeting-copy-box p-2 mb-3">
          <div class="small mb-1"><b>سرپرست:</b> <?= e($bookedMeetingInfo['supervisor_name']) ?></div>
          <div class="small mb-1"><b>تاریخ و ساعت:</b> <?= e($bookedMeetingInfo['date_jalali']) ?> — <span dir="ltr"><?= e($bookedMeetingInfo['time']) ?></span></div>
          <?php if ($bookedMeetingInfo['meeting_url'] !== ''): ?>
            <div class="small mb-2 text-break"><b>لینکِ جلسه:</b> <span dir="ltr"><?= e($bookedMeetingInfo['meeting_url']) ?></span></div>
          <?php endif; ?>
          <textarea id="rapMeetingCopyText" class="d-none"><?= e($bookedMeetingInfo['copy_text']) ?></textarea>
          <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="rapCopyMeetingText(this)">
            <i class="fa-solid fa-copy"></i> کپیِ متنِ زمان و لینکِ جلسه
          </button>
        </div>
      <?php endif; ?>

      <?php if ($myBookings): ?>
        <div class="mb-3">
          <?php foreach ($myBookings as $mb): ?>
            <div class="rap-social-btn mb-2 d-flex align-items-center justify-content-between" dir="rtl">
              <span>
                <i class="fa-solid fa-calendar-day"></i>
                با <?= e($mb['supervisor_name'] ?? '—') ?> — <?= to_jalali($mb['slot_date']) ?> ساعت <span dir="ltr"><?= e($mb['start_time']) ?></span>
              </span>
              <form method="post" class="d-inline mb-0" onsubmit="return confirm('این رزرو لغو شود؟ ظرفیتِ این تایم دوباره خالی می‌شود.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_meeting_booking">
                <input type="hidden" name="booking_id" value="<?= (int) $mb['id'] ?>">
                <button type="submit" class="btn btn-link btn-sm p-0 text-danger text-decoration-none" title="لغوِ رزرو"><i class="fa-solid fa-xmark"></i></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$availableSlotsBySupervisor): ?>
        <p class="text-muted small mb-0">در حالِ حاضر تایمِ خالی از سویِ سرپرست‌ها تعریف نشده است.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="book_meeting_slot">
          <select name="slot_id" class="form-select form-select-sm mb-2" required>
            <option value="">— انتخابِ سرپرست و تایم —</option>
            <?php foreach ($availableSlotsBySupervisor as $svId => $grp): ?>
              <optgroup label="<?= e($grp['name']) ?>">
                <?php foreach ($grp['slots'] as $slot): ?>
                  <option value="<?= (int) $slot['id'] ?>">
                    <?= to_jalali($slot['slot_date']) ?> — ساعتِ <?= e($slot['start_time']) ?> (ظرفیتِ باقی‌مانده: <?= to_persian_digits((string) $slot['remaining']) ?>)
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="rap-action-btn w-100"><i class="fa-solid fa-calendar-plus"></i> رزروِ این تایم</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($inpersonReady): ?>
    <div class="card p-3 mt-3">
      <h6 class="fw-bold mb-3">
        <i class="fa-solid fa-building-user text-warning"></i> مصاحبه حضوری <span class="badge rounded-pill text-bg-warning ms-1" style="font-size:.65rem">حضوری</span>
      </h6>
      <div class="small text-muted mb-2">اگر متقاضی می‌خواهد به‌جای میتینگ آنلاین حضوری بیاید، این‌جا ثبت کنید تا در آمار «حضوری» شمرده شود و از بخشِ «مصاحبه‌های حضوری» قابل پیگیری باشد.</div>

      <?php if ($myInpersonInterviews): $ivStatuses = reception_inperson_statuses(); ?>
        <div class="mb-3">
          <?php foreach ($myInpersonInterviews as $iv):
              $ivSt = $ivStatuses[$iv['status']] ?? ['label' => $iv['status'], 'color' => 'secondary', 'icon' => 'fa-circle'];
              $canEditIv = $isAdmin || (int) $iv['agent_user_id'] === (int) $user['id'];
          ?>
            <div class="rap-inperson-item rounded-3 p-2 mb-2">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="small">
                  <div class="fw-bold"><i class="fa-solid fa-calendar-day"></i> <?= to_jalali($iv['interview_date']) ?>
                    <?php if (!empty($iv['interview_time'])): ?> — ساعت <span dir="ltr"><?= e(substr((string) $iv['interview_time'], 0, 5)) ?></span><?php endif; ?>
                  </div>
                  <?php if (!empty($iv['location'])): ?><div class="text-muted"><i class="fa-solid fa-location-dot"></i> <?= e($iv['location']) ?></div><?php endif; ?>
                  <?php if (!empty($iv['supervisor_name'])): ?><div class="text-muted"><i class="fa-solid fa-user-tie"></i> <?= e($iv['supervisor_name']) ?></div><?php endif; ?>
                  <div class="text-muted">ثبت: <?= e($iv['agent_name'] ?? '—') ?></div>
                </div>
                <span class="badge text-bg-<?= e($ivSt['color']) ?>"><i class="fa-solid <?= e($ivSt['icon']) ?>"></i> <?= e($ivSt['label']) ?></span>
              </div>
              <?php if (!empty($iv['notes'])): ?><div class="small text-muted mt-1"><?= nl2br(e($iv['notes'])) ?></div><?php endif; ?>
              <?php if (!empty($iv['result_note'])): ?><div class="small mt-1"><b>نتیجه:</b> <?= e($iv['result_note']) ?></div><?php endif; ?>
              <?php if ($canEditIv): ?>
                <form method="post" class="d-flex flex-wrap gap-1 mt-2 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="inperson_status">
                  <input type="hidden" name="interview_id" value="<?= (int) $iv['id'] ?>">
                  <input type="text" name="result_note" class="form-control form-control-sm" style="flex:1 1 120px" placeholder="توضیحِ نتیجه (اختیاری)">
                  <?php foreach ($ivStatuses as $code => $meta): if ($code === $iv['status']) continue; ?>
                    <button type="submit" name="iv_status" value="<?= e($code) ?>" class="btn btn-sm btn-outline-<?= e($meta['color']) ?>" title="<?= e($meta['label']) ?>"><i class="fa-solid <?= e($meta['icon']) ?>"></i></button>
                  <?php endforeach; ?>
                </form>
              <?php endif; ?>
              <?php if ((int) $iv['agent_user_id'] === (int) $user['id'] && $iv['status'] === 'scheduled'): ?>
                <form method="post" class="text-start mt-1" onsubmit="return confirm('این جلسه کامل حذف شود؟ (برای ثبت در آمار، به‌جای حذف «لغو» را بزنید)');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_inperson_interview">
                  <input type="hidden" name="interview_id" value="<?= (int) $iv['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-danger text-decoration-none small"><i class="fa-solid fa-trash-can"></i> حذف (ثبتِ اشتباه)</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <button type="button" class="rap-action-btn w-100 mb-2" data-bs-toggle="collapse" data-bs-target="#rapInpersonForm" aria-expanded="false">
        <i class="fa-solid fa-plus"></i> ثبت جلسه مصاحبه حضوری
      </button>

      <div class="collapse" id="rapInpersonForm">
        <form method="post" class="border rounded p-2" style="border-color:var(--rap-line)!important">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="log_inperson_interview">
          <div class="mb-2">
            <label class="form-label small text-muted mb-1">تاریخ مصاحبه (شمسی)</label>
            <input type="text" name="interview_date" id="rapInpersonDate"
                   class="form-control form-control-sm"
                   autocomplete="off" placeholder="۱۴۰۵/۰۷/۰۲" required>
          </div>
          <div class="mb-2">
            <label class="form-label small text-muted mb-1">ساعت (اختیاری)</label>
            <input type="time" name="interview_time" class="form-control form-control-sm">
          </div>
          <div class="mb-2">
            <label class="form-label small text-muted mb-1">مصاحبه‌کننده / سرپرست (اختیاری)</label>
            <select name="supervisor_user_id" class="form-select form-select-sm">
              <option value="0">—</option>
              <?php foreach ($supervisors as $sv): ?>
                <option value="<?= (int) $sv['id'] ?>" <?= (int) $applicant['supervisor_user_id'] === (int) $sv['id'] ? 'selected' : '' ?>><?= e($sv['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small text-muted mb-1">محل برگزاری (اختیاری)</label>
            <input type="text" name="location" class="form-control form-control-sm" placeholder="مثلاً: دفتر مرکزی">
          </div>
          <div class="mb-2">
            <label class="form-label small text-muted mb-1">یادداشت (اختیاری)</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
          </div>
          <button type="submit" class="rap-action-btn w-100">
            <i class="fa-solid fa-check"></i> ثبت جلسه حضوری
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card p-3 mb-3">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-phone text-warning"></i> ثبتِ تماسِ تلفنی</h6>
      <a href="tel:<?= e($phones[0]['phone'] ?? $applicant['mobile']) ?>" id="rapDialBtn" class="rap-action-btn w-100 d-block text-center text-decoration-none mb-3">
        <i class="fa-solid fa-phone-volume"></i> تماسِ مستقیم با این شماره
      </a>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="log_call">
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">شماره</label>
          <select name="phone" id="rapPhoneSelect" class="form-select form-select-sm" onchange="document.getElementById('rapDialBtn').href = 'tel:' + this.value;">
            <?php foreach ($phones as $ph): ?>
              <option value="<?= e($ph['phone']) ?>"><?= e($ph['phone']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">مدتِ تماس (ثانیه — اختیاری)</label>
          <input type="number" min="0" name="duration_seconds" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">نتیجه</label>
          <select name="result" class="form-select form-select-sm" required>
            <option value="success">موفق</option>
            <option value="no_answer">عدم پاسخ</option>
            <option value="cancelled">انصرافی</option>
            <option value="followup">نیازمند پیگیری</option>
            <option value="wrong_number">شماره اشتباه</option>
            <option value="other">سایر</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">یادداشت</label>
          <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
        </div>
        <button type="submit" class="rap-action-btn w-100"><i class="fa-solid fa-phone"></i> ثبتِ تماس</button>
      </form>
    </div>

    <div class="card p-3 mb-3">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left text-warning"></i> ثبتِ پیگیری</h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="log_followup">
        <div class="mb-2">
          <select name="activity_type" class="form-select form-select-sm">
            <option value="note">یادداشت</option>
            <option value="message">پیام</option>
            <option value="meeting">جلسه</option>
            <option value="other">سایر</option>
          </select>
        </div>
        <div class="mb-2">
          <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="شرحِ پیگیری..." required></textarea>
        </div>
        <button type="submit" class="rap-action-btn secondary w-100"><i class="fa-solid fa-plus"></i> افزودنِ پیگیری</button>
      </form>
    </div>

  </div>

  <div class="col-lg-4">
    <div class="card p-3 mb-3 rap-notes-card">
      <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h6 class="fw-bold mb-0"><i class="fa-solid fa-note-sticky text-warning"></i> یادداشت‌های گذشته</h6>
        <span class="badge rounded-pill rap-notes-count"><?= to_persian_digits((string) count($pastNotes)) ?></span>
      </div>
      <?php if (!$pastNotes): ?>
        <p class="text-muted small mb-0">هنوز یادداشتی برای این متقاضی ثبت نشده است.</p>
      <?php else: ?>
        <div class="rap-notes-list">
          <?php foreach ($pastNotes as $note): ?>
            <div class="rap-note-item">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="small text-muted">
                  <?= to_jalali($note['created_at']) ?>
                  <?php if (!empty($note['agent_name'])): ?> — <?= e($note['agent_name']) ?><?php endif; ?>
                </div>
                <?php if ((int) $note['agent_user_id'] === (int) $user['id']): ?>
                  <form method="post" class="m-0" onsubmit="return confirm('این یادداشت حذف شود؟');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_note">
                    <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="حذف یادداشت"><i class="fa-solid fa-trash-can"></i></button>
                  </form>
                <?php endif; ?>
              </div>
              <?php
                $noteTypeLabels = [
                    'note' => 'یادداشت',
                    'message' => 'پیام',
                    'meeting' => 'جلسه',
                    'other' => 'سایر',
                ];
                $noteTypeLabel = $noteTypeLabels[$note['activity_type'] ?? ''] ?? 'سایر';
              ?>
              <div class="small mb-1"><span class="rap-note-type-label"><?= e($noteTypeLabel) ?></span></div>
              <div class="small mt-1"><?= nl2br(e($note['notes'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card p-3">
      <h6 class="fw-bold mb-3"><i class="fa-solid fa-timeline text-warning"></i> Timeline فعالیت‌ها</h6>
      <?php if (!$timeline): ?>
        <p class="text-muted small mb-0">هنوز فعالیتی برای این متقاضی ثبت نشده است.</p>
      <?php else: ?>
        <div class="rap-timeline">
          <?php foreach (array_reverse($timeline) as $item): ?>
            <div class="rap-timeline-item">
              <div class="small text-muted"><?= to_jalali($item['at']) ?></div>
              <div class="fw-semibold small"><?= e($item['summary']) ?></div>
              <?php if ($item['notes'] !== ''): ?><div class="small text-muted"><?= e($item['notes']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>

<script>
function rapCopyMeetingText(btn) {
  var ta = document.getElementById('rapMeetingCopyText');
  if (!ta) { return; }
  var text = ta.value;
  var done = function () {
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> کپی شد';
    setTimeout(function () { btn.innerHTML = old; }, 1800);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function () { rapFallbackCopy(ta, done); });
  } else {
    rapFallbackCopy(ta, done);
  }
}
function rapFallbackCopy(ta, done) {
  ta.classList.remove('d-none');
  ta.select();
  try { document.execCommand('copy'); done(); } catch (e) {}
  ta.classList.add('d-none');
}
</script>

<?php if ($inpersonReady): ?>
<script>
(function () {
    function loadScript(src, cb) {
        var s = document.createElement('script');
        s.src = src; s.async = false;
        s.onload = cb;
        s.onerror = function () { if (cb) cb(); };
        document.head.appendChild(s);
    }
    function initPicker() {
        if (!window.jQuery || !jQuery.fn.persianDatepicker) return;
        jQuery('#rapInpersonDate').persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            persianDigit: true,
            observer: true,
            calendar: { persian: { locale: 'fa', leapYearMode: 'algorithmic' } }
        });
    }
    function bootstrap() {
        if (!window.jQuery) {
            loadScript('https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js', function () {
                loadScript('https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js', function () {
                    loadScript('https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js', initPicker);
                });
            });
            return;
        }
        if (!jQuery.fn.persianDatepicker) {
            loadScript('https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js', function () {
                loadScript('https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js', initPicker);
            });
            return;
        }
        initPicker();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else { bootstrap(); }
})();
</script>
<?php endif; ?>

<?php if ($rpRow): ?>
<?php require __DIR__ . '/includes/reception_pipeline_modal.php'; ?>
<?php if ($rpGuard): ?>
<!-- قانونِ «هیچ متقاضی بدونِ اقدامِ بعدی رها نشود» -->
<div class="modal fade" id="rpGuard" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px">
      <div class="modal-body p-4">
        <h6 class="fw-bold mb-1"><i class="fa-solid fa-circle-question text-warning"></i> وضعیتِ این متقاضی چیست؟</h6>
        <p class="small text-muted mb-3">قبل از خروج، اقدامِ بعدیِ <?= e(trim($applicant['first_name'] . ' ' . $applicant['last_name'])) ?> را مشخص کنید تا در مسیر رها نشود.</p>
        <div class="d-grid gap-2">
          <button type="button" class="btn btn-outline-secondary" data-guard-go="followup"><i class="fa-solid fa-clock"></i> پیگیری در تاریخِ دیگر</button>
          <button type="button" class="btn btn-outline-primary" data-guard-go="meeting"><i class="fa-solid fa-calendar-plus"></i> تعیینِ جلسه (آنلاین/حضوری)</button>
          <button type="button" class="btn btn-outline-warning" data-guard-go="no_answer"><i class="fa-solid fa-phone-slash"></i> پاسخ نداد — بعداً دوباره</button>
          <button type="button" class="btn btn-outline-danger" data-guard-go="not_interested"><i class="fa-solid fa-hand"></i> عدمِ تمایل</button>
          <button type="button" class="btn btn-outline-dark" data-guard-go="close"><i class="fa-solid fa-flag-checkered"></i> تعیین تکلیفِ نهایی</button>
          <a href="#" class="btn btn-link btn-sm text-muted" id="rpGuardLeave">فعلاً بدونِ ثبت خارج می‌شوم</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<script>window.RP_CONFIG = { endpoint: 'reception_pipeline_action.php', reload: true };</script>
<script src="assets/js/reception-pipeline.js?v=1"></script>
<?php if ($rpGuard): ?>
<script>
(function () {
  var pending = null, aid = <?= (int) $applicantId ?>, name = <?= json_encode(trim($applicant['first_name'] . ' ' . $applicant['last_name']), JSON_UNESCAPED_UNICODE) ?>;
  function gm() { return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(document.getElementById('rpGuard')) : null; }
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[href]');
    if (!a || a.closest('#rpGuard') || a.target === '_blank') return;
    var h = a.getAttribute('href') || '';
    if (h === '' || h.charAt(0) === '#' || /^(tel:|mailto:|javascript:|https?:\/\/wa\.me|https?:\/\/t\.me|https?:\/\/eitaa)/i.test(h)) return;
    if (a.closest('.acts') || a.hasAttribute('data-act')) return;
    var m = gm(); if (!m) return;
    ev.preventDefault(); pending = a.href; m.show();
  }, true);
  document.getElementById('rpGuardLeave').addEventListener('click', function (e) { e.preventDefault(); if (pending) location.href = pending; });
  document.querySelectorAll('[data-guard-go]').forEach(function (b) {
    b.addEventListener('click', function () {
      var go = b.getAttribute('data-guard-go'), m = gm(); if (m) m.hide();
      if (go === 'meeting') { document.getElementById('meeting-section').scrollIntoView({ behavior: 'smooth' }); return; }
      var map = { followup: ['followup', 'date'], no_answer: ['no_answer', 'direct'], not_interested: ['not_interested', 'direct'], close: ['close', 'outcome'] };
      var f = document.createElement('button');
      f.type = 'button'; f.style.display = 'none';
      f.setAttribute('data-act', map[go][0]); f.setAttribute('data-kind', map[go][1]); f.setAttribute('data-id', aid); f.setAttribute('data-name', name);
      document.body.appendChild(f);
      setTimeout(function () { f.click(); }, 250);
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>