<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/merchant_services.php';
$user = require_login();
$pdo  = db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT c.*, u.full_name AS owner_name, u.role AS owner_role, fa.full_name AS first_advisor_name
                        FROM customers c
                        LEFT JOIN users u ON u.id = c.owner_user_id
                        LEFT JOIN users fa ON fa.id = c.first_advisor_user_id
                        WHERE c.id = ? LIMIT 1');
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer && !empty($_GET['mobile'])) {
    $mobile = preg_replace('/\D+/', '', (string) $_GET['mobile']);
    if ($mobile !== '') {
        $mobileStmt = $pdo->prepare('SELECT c.*, u.full_name AS owner_name, u.role AS owner_role, fa.full_name AS first_advisor_name
                                     FROM customers c
                                     LEFT JOIN users u ON u.id = c.owner_user_id
                                     LEFT JOIN users fa ON fa.id = c.first_advisor_user_id
                                     WHERE c.mobile = ? OR c.mobile_2 = ? OR c.mobile_3 = ? OR c.mobile_4 = ? OR c.mobile_5 = ?
                                     ORDER BY c.id DESC LIMIT 1');
        $mobileStmt->execute([$mobile, $mobile, $mobile, $mobile, $mobile]);
        $customer = $mobileStmt->fetch() ?: null;
        if ($customer) {
            $id = (int) $customer['id'];
        }
    }
}

if (!$customer) {
    http_response_code(404);
    die('مشتری یافت نشد.');
}
$canManage = ($customer['owner_user_id'] == $user['id']) || can_manage_service_requests($user) || leader_supervises_owner($pdo, $user, (int) $customer['owner_user_id']);

$myRelation = null;
if (!$canManage && customer_relations_ready($pdo)) {
    $myRelStmt = $pdo->prepare('SELECT * FROM customer_employee_relations WHERE customer_id = ? AND employee_id = ? LIMIT 1');
    $myRelStmt->execute([$id, (int) $user['id']]);
    $myRelation = $myRelStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$canAddFollowup = $canManage || $myRelation !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_conflict_to']) && can_manage_service_requests($user)) {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست شما منقضی شده است، دوباره تلاش کنید.');
        redirect('customer_view.php?id=' . $id);
    }
    $chosenId = (int) $_POST['assign_conflict_to'];
    $otherIds = array_filter(array_map('intval', (array) ($_POST['conflict_ids'] ?? [])), fn($v) => $v !== $chosenId);

    $chosen = merge_load_customer($pdo, $chosenId);
    if (!$chosen) {
        flash_set('danger', 'مشتری انتخاب‌شده پیدا نشد.');
        redirect('customer_view.php?id=' . $id);
    }

    $mergedCount = 0;
    try {
        $pdo->beginTransaction();
        foreach ($otherIds as $oid) {
            $dup = merge_load_customer($pdo, $oid);
            if (!$dup) {
                continue;
            }
            merge_customer_records($pdo, $chosen, $dup);
            $mergedCount++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('danger', 'خطا در انتصاب: ' . $e->getMessage());
        redirect('customer_view.php?id=' . $id);
    }

    flash_set('success', to_persian_digits((string) $mergedCount) . ' رکورد تکراری در پرونده‌ی «' . $chosen['full_name'] . '» ادغام شد و از لیست پیگیری بقیه کارشناس‌ها حذف شد.');
    redirect('customer_view.php?id=' . $chosenId);
}

$canViewReferralHistory = false;
if (!$canManage) {
    try {
        $refAccess = $pdo->prepare('SELECT 1 FROM customer_referrals
            WHERE customer_id = ? AND (from_user_id = ? OR referred_by = ?)
            LIMIT 1');
        $refAccess->execute([$id, (int) $user['id'], (int) $user['id']]);
        $canViewReferralHistory = (bool) $refAccess->fetchColumn();
    } catch (Throwable $e) {
        $canViewReferralHistory = false;
    }
}
$canReadAllCustomers = user_can('customer_view_all', $user);
if (!$canManage && !$canViewReferralHistory && !$myRelation && !$canReadAllCustomers) {
    http_response_code(403);
    die('شما اجازه مشاهده این مشتری را ندارید.');
}

$statusOptions = status_options_for_role($customer['owner_role']);
$meetingConductorOptions = $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE is_active = 1 AND role IN ('B','C') ORDER BY full_name")->fetchAll();
$errors = [];

$purchasedServicesStmt = $pdo->prepare('SELECT service_name, quantity, department FROM customer_services WHERE customer_id = ? ORDER BY department, service_name');
$purchasedServicesStmt->execute([$id]);
$purchasedServices = $purchasedServicesStmt->fetchAll();
$purchasedServicesByDept = [];
foreach ($purchasedServices as $ps) {
    $dept = $ps['department'] !== null && $ps['department'] !== '' ? $ps['department'] : 'سایر';
    $purchasedServicesByDept[$dept][] = $ps;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merchant_refresh'])) {
    merchant_services_handle_refresh_post($pdo, (int) $user['id']);
    redirect('customer_view.php?id=' . $id . '#merchant-services-full');
}

$fromRaw = $_POST['from'] ?? $_GET['from'] ?? '';
$fromAllowed = ['customer_list.php', 'dashboard.php', 'customer_referrals.php'];
$returnTo = 'customer_list.php';
if ($fromRaw !== '') {
    $fromPath = explode('?', $fromRaw, 2)[0];
    if (in_array($fromPath, $fromAllowed, true)) {
        $returnTo = $fromRaw;
    }
}

$duplicateCandidates = find_duplicate_name_customers($pdo, $id, $customer['full_name'], $user['role'] === 'admin', $customer['owner_user_id']);

$svcReqStmt = $pdo->prepare("SELECT * FROM service_requests WHERE customer_id = ? ORDER BY created_at DESC");
$svcReqStmt->execute([$id]);
$linkedServiceRequests = $svcReqStmt->fetchAll();
$svcReqEventsByReq = [];
if ($linkedServiceRequests) {
    $ids = array_column($linkedServiceRequests, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $evtStmt = $pdo->prepare("SELECT e.*, u.full_name AS actor_name FROM service_request_events e
                               LEFT JOIN users u ON u.id = e.actor_user_id
                               WHERE e.service_request_id IN ($ph) ORDER BY e.created_at ASC");
    $evtStmt->execute($ids);
    foreach ($evtStmt->fetchAll() as $evt) {
        $svcReqEventsByReq[(int) $evt['service_request_id']][] = $evt;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('فقط مدیر سیستم اجازه حذف مشتری را دارد.');
    }
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } else {
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        flash_set('success', 'مشتری «' . $customer['full_name'] . '» حذف شد.');
        redirect($returnTo);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_followup'])) {
    if (!$canAddFollowup) {
        http_response_code(403);
        die('این پرونده برای شما فقط به‌صورت مشاهده‌ای قابل دسترسی است.');
    }
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    }
    $followupDateJ = trim($_POST['followup_date'] ?? '');
    $nextDateJ     = trim($_POST['next_followup_date'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $newStatus     = trim($_POST['status'] ?? '');

    $followupDateG = to_gregorian($followupDateJ);
    $noFollowup    = status_needs_no_followup($newStatus);
    $nextDateG     = null;

    if (!$followupDateG) {
        $errors[] = 'تاریخ پیگیری معتبر نیست.';
    }
    if (!$noFollowup) {
        $nextDateG = to_gregorian($nextDateJ);
        if (!$nextDateG) {
            $errors[] = 'تاریخ پیگیری بعدی معتبر نیست.';
        }
    }
    if (!in_array($newStatus, $statusOptions, true)) {
        $errors[] = 'وضعیت انتخابی معتبر نیست.';
    }

    $refStatus = $canManage ? (string) $customer['status'] : (string) ($myRelation['status'] ?? 'جدید');
    $refNextDate = $canManage ? $customer['next_followup_date'] : ($myRelation['next_followup_date'] ?? null);

    $meetingStatusTransition = ($newStatus === 'جلسه برگزار شد' && $refStatus !== 'جلسه برگزار شد');
    $meetingConductorId = null;
    $needsMeetingConfirmation = ($meetingStatusTransition && $user['role'] === 'A');
    $directMeetingCompletion = ($meetingStatusTransition && in_array($user['role'], ['B', 'C'], true));
    if ($directMeetingCompletion) {
        $meetingConductorId = (int) $user['id'];
    }
    if ($needsMeetingConfirmation && !$errors) {
        $meetingConductorId = (int) ($_POST['meeting_conductor_id'] ?? 0);
        $condStmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND is_active = 1 AND role IN ('B','C') LIMIT 1");
        $condStmt->execute([$meetingConductorId]);
        $conductor = $condStmt->fetch();
        if (!$conductor) {
            $errors[] = 'برای ثبت «جلسه برگزار شد» باید مشخص کنید کدام کارشناسِ واحد B یا C جلسه را برگزار کرده است.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $nextFollowupNumber = (int) $customer['followup_count'] + 1;
            $statusToApply = $needsMeetingConfirmation ? $refStatus : $newStatus;
            $nextDateToApply = $needsMeetingConfirmation ? $refNextDate : $nextDateG;
            $statusAfterForLog = $needsMeetingConfirmation ? $refStatus : $newStatus;

            // ⭐ محاسبه‌ی ستون‌های denormalized (برای گزارش‌های سریع)
            $__ct = (string) ($customer['contact_type'] ?? 'customer');
            if (!in_array($__ct, ['customer', 'family', 'colleague'], true)) {
                $__ct = 'customer';
            }
            $__isPhoneCall = 0; // پیگیری دستی، همیشه ۰

            $ins = $pdo->prepare('INSERT INTO followups
                (customer_id, relation_id, followup_number, followup_date, description, status_after, next_followup_date, created_by, contact_type, is_phone_call)
                VALUES (?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $id, $myRelation['id'] ?? null, $nextFollowupNumber, $followupDateG,
                $description !== '' ? $description : null,
                $statusAfterForLog, $nextDateToApply, $user['id'],
                $__ct, $__isPhoneCall,
            ]);
            $newFollowupId = (int) $pdo->lastInsertId();
            try { require_once __DIR__ . '/includes/performance_functions.php'; ps_note_interaction($pdo, $id, (int) $user['id'], 'manual'); } catch (Throwable $e) {}

            if ($canManage) {
                $upd = $pdo->prepare('UPDATE customers SET status = ?, next_followup_date = ?, followup_count = ? WHERE id = ?');
                $upd->execute([$statusToApply, $nextDateToApply, $nextFollowupNumber, $id]);
            } else {
                $pdo->prepare('UPDATE customers SET followup_count = followup_count + 1 WHERE id = ?')->execute([$id]);
                $pdo->prepare('UPDATE customer_employee_relations SET followup_count = followup_count + 1 WHERE id = ?')->execute([(int) $myRelation['id']]);
                update_relation_status($pdo, (int) $myRelation['id'], $statusToApply, $nextDateToApply);
            }
            if (!$needsMeetingConfirmation) {
                record_meeting_flag_if_needed($pdo, $id, $newStatus);
            }

            $log = $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)');
            $oldStatus = $refStatus;
            if ($needsMeetingConfirmation) {
                $statusText = 'درخواست تایید برگزاری جلسه برای «' . $conductor['full_name'] . '» ثبت شد (وضعیت تا تایید ایشان روی «' . $oldStatus . '» می‌ماند).';
            } else {
                $statusText = $oldStatus !== $newStatus
                    ? 'وضعیت: «' . $oldStatus . '» ← «' . $newStatus . '».'
                    : 'وضعیت بدون تغییر: «' . $newStatus . '».';
            }
            $log->execute([$id, $user['id'], 'followup', 'پیگیری شماره ' . $nextFollowupNumber . ' ثبت شد. ' . $statusText . ($description !== '' ? ' توضیحات: ' . $description : '')]);

            if ($needsMeetingConfirmation || $directMeetingCompletion) {
                $verificationStatus = $needsMeetingConfirmation ? 'pending' : 'confirmed';
                $mv = $pdo->prepare('INSERT INTO meeting_verifications
                    (customer_id, followup_id, submitted_by, conductor_id, previous_status, status)
                    VALUES (?,?,?,?,?,?)');
                $mv->execute([$id, $newFollowupId, $user['id'], $meetingConductorId, $oldStatus, $verificationStatus]);
            }

            $pdo->commit();
            flash_set('success', $needsMeetingConfirmation
                ? "پیگیری شماره {$nextFollowupNumber} ثبت شد — درخواست تایید جلسه برای {$conductor['full_name']} فرستاده شد."
                : "پیگیری شماره {$nextFollowupNumber} با موفقیت ثبت شد.");
            redirect($returnTo);
        } catch (Exception $e) {
            $pdo->rollBack();
            $rawMsg = $e->getMessage();
            if ($noFollowup && (stripos($rawMsg, "next_followup_date' cannot be null") !== false || stripos($rawMsg, '1048') !== false)) {
                $errors[] = 'خطا در ثبت پیگیری: دیتابیس سایت هنوز اجازه خالی گذاشتن «تاریخ پیگیری بعدی» را نمی‌دهد. لطفاً از پنل مدیریت وارد «بروزرسانی سیستم» شوید و فایل migration_nullable_followup_date.sql را (طبق راهنمای بخش دیتابیس) روی سایت اجرا کنید، سپس دوباره تلاش کنید.';
            } else {
                $errors[] = 'خطا در ثبت پیگیری. لطفا دوباره تلاش کنید.';
            }
        }
    }
}

$canSeeOthersFollowups = is_super_admin($user) || user_can('customer_others_followups_view', $user);
$canSeeConflictWarning = is_super_admin($user) || user_can('customer_conflict_warning_view', $user);

$inheritCutoff = null;
if (!$canSeeOthersFollowups) {
    try { require_once __DIR__ . '/includes/performance_functions.php'; if (perf_ready($pdo)) $inheritCutoff = ps_inherit_cutoff($pdo, $id, (int) $user['id']); } catch (Throwable $e) {}
}
// مشتریِ ارجاع‌گرفته: گیرنده باید ببیند قبل از ارجاع چه مراحلی با مشتری طی شده ← همه‌ی پیگیری‌ها و رویدادهای تا لحظه‌ی ارجاع
$receivedReferral = null;
try {
    $rrFrom = function_exists('referral_log_ready') && referral_log_ready($pdo) ? referral_union_sql() : 'customer_referrals'; // + انتقال بعد از جلسه / Box
    $rrSt = $pdo->prepare('SELECT r.created_at, fu.full_name AS from_name, bu.full_name AS by_name
        FROM ' . $rrFrom . ' r LEFT JOIN users fu ON fu.id = r.from_user_id LEFT JOIN users bu ON bu.id = r.referred_by
        WHERE r.customer_id = ? AND r.to_user_id = ? ORDER BY r.created_at DESC, r.id DESC LIMIT 1');
    $rrSt->execute([$id, (int) $user['id']]);
    $receivedReferral = $rrSt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $receivedReferral = null;
}
if ($receivedReferral && !$canSeeOthersFollowups) {
    $__refCut = date('Y-m-d H:i:s', (strtotime((string) $receivedReferral['created_at']) ?: time()) + 2);
    if ($inheritCutoff === null || $__refCut > $inheritCutoff) $inheritCutoff = $__refCut;
}
$stmt = $pdo->prepare('SELECT f.*, u.full_name AS created_by_name
                        FROM followups f JOIN users u ON u.id = f.created_by
                        WHERE f.customer_id = ?' . ($canSeeOthersFollowups ? '' : ' AND (f.created_by = ' . (int) $user['id'] . ($inheritCutoff ? ' OR f.created_at < ?' : '') . ')') . '
                        ORDER BY f.followup_number DESC');
$stmt->execute($inheritCutoff && !$canSeeOthersFollowups ? [$id, $inheritCutoff] : [$id]);
$followups = $stmt->fetchAll();

$callLink = call_link($customer['mobile']);
$callLink2 = !empty($customer['mobile_2']) ? call_link($customer['mobile_2']) : null;
$messengersBySlot = customer_messengers_by_slot($pdo, $id);
$socialLabels = social_network_options();

$phoneConflicts = find_phone_conflicts($pdo, $id, $customer['mobile'], $customer['mobile_2'] ?? null);

$timeline = [];
$tlClock = static function ($raw): ?string {
    $raw = trim((string) $raw);
    if ($raw !== '' && preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $raw, $m)) {
        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }
    return null;
};
if ($canManage || $canViewReferralHistory || $receivedReferral) {
    try {
        $tl = $pdo->prepare('SELECT l.id, l.customer_id, l.user_id, l.activity_type, l.description, l.created_at,
                                    u.full_name AS user_name
                             FROM customer_activity_logs l
                             LEFT JOIN users u ON u.id = l.user_id
                             WHERE l.customer_id = ?
                             ORDER BY l.created_at DESC, l.id DESC
                             LIMIT 200');
        $tl->execute([$id]);
        $timeline = $tl->fetchAll();
    } catch (Throwable $e) {
        $timeline = [];
    }

    try {
        $existingFollowupNumbers = [];
        foreach ($timeline as $event) {
            if (($event['activity_type'] ?? '') === 'followup' && preg_match('/پیگیری شماره\s+(\d+)/u', (string) $event['description'], $m)) {
                $existingFollowupNumbers[(int) $m[1]] = true;
            }
        }
        $fuTl = $pdo->prepare('SELECT f.*, u.full_name AS user_name
                               FROM followups f
                               LEFT JOIN users u ON u.id = f.created_by
                               WHERE f.customer_id = ?
                               ORDER BY f.followup_date DESC, f.id DESC
                               LIMIT 200');
        $fuTl->execute([$id]);
        foreach ($fuTl->fetchAll() as $f) {
            $num = (int) $f['followup_number'];
            if (isset($existingFollowupNumbers[$num])) {
                continue;
            }
            $desc = 'پیگیری شماره ' . $num . ' ثبت شده است.';
            if (!empty($f['description'])) {
                $desc .= ' ' . trim((string) $f['description']);
            }
            if (!empty($f['status_after'])) {
                $desc .= ' وضعیت: ' . $f['status_after'] . '.';
            }
            $fuClock = $tlClock($f['event_time'] ?? null);
            if (!empty($f['followup_date'])) {
                $fuStamp = substr((string) $f['followup_date'], 0, 10) . ' ' . ($fuClock ?? '00:00:00');
                $fuDateOnly = ($fuClock === null);
            } else {
                $fuStamp = (string) $f['created_at'];
                $fuDateOnly = false;
            }
            $timeline[] = [
                'id' => 'followup-' . (int) $f['id'],
                'customer_id' => $id,
                'user_id' => $f['created_by'],
                'activity_type' => 'followup',
                'description' => $desc,
                'created_at' => $fuStamp,
                'date_only' => $fuDateOnly,
                'user_name' => $f['user_name'] ?? '',
            ];
        }
    } catch (Throwable $e) {
    }

    try {
        $refTl = $pdo->prepare('SELECT r.id, r.from_user_id, r.to_user_id, r.referred_by, r.created_at,
                                       from_u.full_name AS from_user_name,
                                       to_u.full_name AS to_user_name,
                                       by_u.full_name AS referred_by_name
                                FROM customer_referrals r
                                JOIN users from_u ON from_u.id = r.from_user_id
                                JOIN users to_u ON to_u.id = r.to_user_id
                                JOIN users by_u ON by_u.id = r.referred_by
                                WHERE r.customer_id = ?
                                ORDER BY r.created_at DESC, r.id DESC
                                LIMIT 200');
        $refTl->execute([$id]);
        foreach ($refTl->fetchAll() as $r) {
            $alreadyLogged = false;
            foreach ($timeline as $event) {
                if (($event['activity_type'] ?? '') !== 'referral') continue;
                $eventTime = strtotime((string) ($event['created_at'] ?? ''));
                $refTime = strtotime((string) $r['created_at']);
                if ($eventTime && $refTime && abs($eventTime - $refTime) <= 5) {
                    $alreadyLogged = true;
                    break;
                }
            }
            if ($alreadyLogged) continue;
            $timeline[] = [
                'id' => 'referral-' . (int) $r['id'],
                'customer_id' => $id,
                'user_id' => $r['referred_by'],
                'activity_type' => 'referral',
                'description' => 'مشتری از «' . $r['from_user_name'] . '» به «' . $r['to_user_name'] . '» ارجاع شد.',
                'created_at' => $r['created_at'],
                'user_name' => $r['referred_by_name'],
                'from_user_id' => $r['from_user_id'],
                'from_user_name' => $r['from_user_name'],
            ];
        }
    } catch (Throwable $e) {
    }

    $hasCreate = false;
    foreach ($timeline as $event) {
        if (($event['activity_type'] ?? '') === 'create') {
            $hasCreate = true;
            break;
        }
    }
    if (!$hasCreate) {
        $firstCall = null;
        foreach ($followups as $fRow) {
            $fd = substr((string) ($fRow['followup_date'] ?? ''), 0, 10);
            if ($fd === '' || strncmp($fd, '0000', 4) === 0) {
                continue;
            }
            $fc = $tlClock($fRow['event_time'] ?? null);
            if ($firstCall === null
                || $fd < $firstCall[0]
                || ($fd === $firstCall[0] && $fc !== null && ($firstCall[1] === null || $fc < $firstCall[1]))) {
                $firstCall = [$fd, $fc];
            }
        }

        $originStamp = null;
        $originDateOnly = false;
        $initialDate = substr((string) ($customer['initial_contact_date'] ?? ''), 0, 10);
        if ($initialDate !== '' && strncmp($initialDate, '0000', 4) !== 0) {
            $originStamp = $initialDate . ' ';
            if ($firstCall !== null && $firstCall[0] === $initialDate && $firstCall[1] !== null) {
                $originStamp .= $firstCall[1];
            } else {
                $originStamp .= '00:00:00';
                $originDateOnly = true;
            }
        } else {
            $candidates = [];
            if (!empty($customer['created_at'])) {
                $candidates[] = [(string) $customer['created_at'], false];
            }
            if ($firstCall !== null) {
                $candidates[] = [$firstCall[0] . ' ' . ($firstCall[1] ?? '00:00:00'), $firstCall[1] === null];
            }
            foreach ($candidates as $cand) {
                if ($originStamp === null || strtotime($cand[0]) < strtotime($originStamp)) {
                    $originStamp = $cand[0];
                    $originDateOnly = $cand[1];
                }
            }
        }

        if ($originStamp !== null) {
            $originTs = strtotime($originStamp) ?: 0;
            foreach ($timeline as $event) {
                $evTs = strtotime((string) ($event['created_at'] ?? '')) ?: 0;
                if ($evTs > 0 && $evTs < $originTs) {
                    $originTs = $evTs;
                    $originStamp = (string) $event['created_at'];
                    $originDateOnly = !empty($event['date_only']);
                }
            }
            $timeline[] = [
                'id' => 'create-' . $id,
                'customer_id' => $id,
                'user_id' => null,
                'activity_type' => 'create',
                'description' => 'مشتری در سیستم ثبت شد.',
                'created_at' => $originStamp,
                'date_only' => $originDateOnly,
                'user_name' => '',
                'auto' => true,
            ];
        }
    }

    foreach ($timeline as $k => $event) {
        if (($event['activity_type'] ?? '') === 'create' && trim((string) ($event['description'] ?? '')) === 'مشتری در سیستم ثبت شد.') {
            $timeline[$k]['auto'] = true;
        }
    }

    if (!$canSeeOthersFollowups) {
        $myId = (int) $user['id'];
        $cut = $inheritCutoff ? strtotime($inheritCutoff) : null;
        $timeline = array_values(array_filter($timeline, static function ($ev) use ($myId, $cut) {
            $uid = (int) ($ev['user_id'] ?? 0);
            if ($uid === 0 || $uid === $myId) return true;
            return $cut !== null && (strtotime((string) ($ev['created_at'] ?? '')) ?: PHP_INT_MAX) < $cut;
        }));
    }

    usort($timeline, static function ($a, $b) {
        $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        $aCreate = (($a['activity_type'] ?? '') === 'create');
        $bCreate = (($b['activity_type'] ?? '') === 'create');
        if ($aCreate !== $bCreate) {
            return $aCreate ? 1 : -1;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    if (count($timeline) > 200) {
        $lastEvent = $timeline[count($timeline) - 1];
        if (($lastEvent['activity_type'] ?? '') === 'create') {
            $timeline = array_merge(array_slice($timeline, 0, 199), [$lastEvent]);
        } else {
            $timeline = array_slice($timeline, 0, 200);
        }
    }
}

$pageTitle = 'جزئیات مشتری - ' . $customer['full_name'];
$pendingMeetingVerif = null;
$mvStmt = $pdo->prepare("SELECT mv.*, u.full_name AS conductor_name FROM meeting_verifications mv
                         JOIN users u ON u.id = mv.conductor_id
                         WHERE mv.customer_id = ? AND mv.status = 'pending' ORDER BY mv.created_at DESC LIMIT 1");
try {
    $mvStmt->execute([$id]);
    $pendingMeetingVerif = $mvStmt->fetch() ?: null;
} catch (Throwable $e) {
    $pendingMeetingVerif = null;
}
require_once __DIR__ . '/includes/layout_top.php';

$__cvInitial = mb_substr(trim((string) $customer['full_name']), 0, 1);
if ($__cvInitial === '') { $__cvInitial = '؟'; }
?>
<style>
.cv-page{--cv-line:#e7e2d3;--cv-ink:#1c1917;--cv-muted:#78716c;--cv-gold:#c9a24b;--cv-gold-2:#f1dfa8;}
.cv-page .card{border:1px solid var(--cv-line);border-radius:20px;box-shadow:0 1px 3px rgba(28,25,23,.05);}
.cv-profile-head{display:flex;align-items:center;gap:1rem;margin-bottom:1.1rem;padding-bottom:1.1rem;border-bottom:1px solid var(--cv-line)}
.cv-profile-avatar{width:58px;height:58px;border-radius:16px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--cv-gold) 130%);box-shadow:0 8px 18px -8px rgba(201,162,75,.55);}
.cv-profile-name{font-size:1.15rem;font-weight:800;color:var(--cv-ink);margin:0;line-height:1.3}
.cv-profile-sub{font-size:.78rem;color:var(--cv-muted);margin-top:.3rem;display:flex;align-items:center;gap:.9rem;flex-wrap:wrap}
.cv-profile-sub i{color:var(--cv-gold);margin-left:.25rem}
.cv-meeting-tick{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#c4b5fd,#7c3aed);color:#fff;font-size:.72rem;vertical-align:middle;box-shadow:0 3px 8px -3px rgba(124,58,237,.6);}
.cv-icon-actions{display:flex;justify-content:flex-start;gap:.5rem;flex-wrap:wrap}
.cv-page .cv-icon-btn{width:38px;height:38px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#faf9f5;border:1px solid var(--cv-line);color:#57534e;font-size:.9rem;transition:.15s ease;box-shadow:0 1px 2px rgba(28,25,23,.04);}
.cv-page .cv-icon-btn:hover{background:linear-gradient(135deg,var(--cv-gold-2),var(--cv-gold));color:#241d0a;border-color:transparent;transform:translateY(-2px);box-shadow:0 8px 16px -8px rgba(201,162,75,.6);}
.cv-page .cv-icon-btn-warning:hover{background:linear-gradient(135deg,#fcd34d,#b45309);color:#fff}
.cv-page .cv-icon-btn-danger{color:#b91c1c}
.cv-page .cv-icon-btn-danger:hover{background:linear-gradient(135deg,#fca5a5,#b91c1c);color:#fff}
.cv-page .cv-icon-btn-360 .fa-stack{width:1.3em;height:1.3em;line-height:1.3em;font-size:1rem}
.cv-page .cv-icon-btn-360 .fa-stack-2x{font-size:1.3em}
.cv-page .cv-icon-btn-360 .fa-stack-1x{font-size:.32em}
.cv-page .btn-call, .cv-page .btn-chat{border-radius:14px;font-weight:700;padding:.65rem 1rem;border:none;box-shadow:0 6px 16px -8px rgba(0,0,0,.25);}
.cv-page .btn-call.btn-success{background:linear-gradient(135deg,#0f766e,#134e4a)}
.cv-page .btn-call.btn-outline-success{background:#fff;color:#0f766e;border:1px solid #0f766e33;box-shadow:none}
.cv-page .btn-chat.btn-info{background:linear-gradient(135deg,#0369a1,#0c4a6e)}
.cv-page .btn-chat.btn-outline-info{background:#fff;color:#0369a1;border:1px solid #0369a133;box-shadow:none}
.cv-page .table-compact{border-collapse:separate;border-spacing:0}
.cv-page .table-compact tr th, .cv-page .table-compact tr td{padding:.5rem .3rem;border-bottom:1px solid #f3f1ea;font-size:.85rem}
.cv-page .table-compact tr:last-child th, .cv-page .table-compact tr:last-child td{border-bottom:none}
.cv-page .table-compact th{font-weight:600;color:var(--cv-muted);white-space:nowrap;width:38%}
.cv-page .table-compact td{color:var(--cv-ink);font-weight:600}
.cv-page .badge-status{border-radius:999px;padding:.35rem .8rem;font-weight:700;font-size:.76rem}
.cv-page .list-unstyled li{border-color:#f3f1ea !important}
.cv-page .customer-detail-tabs-card{background:#fff;border:1px solid var(--cv-line);border-radius:20px;overflow:hidden;box-shadow:0 4px 18px -12px rgba(28,25,23,.25);}
.cv-page .customer-detail-tabs{position:relative;display:flex;gap:.3rem;background:linear-gradient(120deg,#0b0f1a 0%,#241d0a 55%,#3d3220 130%);padding:.5rem .6rem;border:none !important;border-bottom:none !important;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:thin;}
.cv-page .customer-detail-tabs::before{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 92% -10%, rgba(201,162,75,.25), transparent 55%),radial-gradient(circle at 5% 130%, rgba(201,162,75,.12), transparent 45%);}
.cv-page .customer-detail-tabs::after{content:'';position:absolute;left:0;right:0;bottom:0;height:2px;background:linear-gradient(90deg,transparent, var(--cv-gold) 35%, var(--cv-gold-2) 50%, var(--cv-gold) 65%, transparent);}
.cv-page .customer-detail-tabs .nav-link{position:relative;z-index:1;border:1px solid transparent !important;background:rgba(255,255,255,.04);color:rgba(245,245,244,.72);border-radius:10px !important;padding:.5rem .85rem;font-size:.78rem;font-weight:600;letter-spacing:.005em;display:flex;align-items:center;gap:.4rem;white-space:nowrap;flex-shrink:0;transition:.15s ease;outline:none !important;box-shadow:none !important;margin:0 !important;}
.cv-page .customer-detail-tabs .nav-link:focus,.cv-page .customer-detail-tabs .nav-link:focus-visible{outline:none !important;box-shadow:0 0 0 3px rgba(201,162,75,.45) !important;border-color:transparent !important;}
.cv-page .customer-detail-tabs .nav-link::after,.cv-page .customer-detail-tabs .nav-link::before,.cv-page .customer-detail-tabs .nav-link.active::after,.cv-page .customer-detail-tabs .nav-link.active::before,#customerDetailTabs.nav-tabs .nav-link,#customerDetailTabs.nav-tabs .nav-link.active{border-bottom:none !important;}
#customerDetailTabs.nav-tabs .nav-link::after,#customerDetailTabs.nav-tabs .nav-link::before{display:none !important;content:none !important;background:transparent !important;}
.cv-page .customer-detail-tabs .nav-link i{box-sizing:border-box !important;margin:0 !important;padding:0 !important;line-height:1 !important;width:22px !important;height:22px !important;border-radius:7px;background:rgba(255,255,255,.08);display:inline-flex !important;align-items:center;justify-content:center;font-size:.68rem;transition:.15s ease;}
.cv-page .customer-detail-tabs .nav-link:hover{color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.1) !important}
.cv-page .customer-detail-tabs .nav-link.active{background:linear-gradient(135deg,var(--cv-gold-2),var(--cv-gold)) !important;color:#241d0a !important;border-color:transparent !important;box-shadow:0 8px 18px -8px rgba(201,162,75,.7) !important;}
.cv-page .customer-detail-tabs .nav-link.active i{background:rgba(36,29,10,.14);color:#241d0a}
.cv-page .customer-tab-count{background:rgba(255,255,255,.18);color:#fff;border-radius:999px;padding:.04rem .42rem;font-size:.66rem;font-weight:700;}
.cv-page .customer-detail-tabs .nav-link.active .customer-tab-count{background:rgba(36,29,10,.18);color:#241d0a}
.cv-page .customer-detail-tab-content{padding:1.4rem}
.cv-page .customer-detail-tab-content .tab-pane{max-height:34rem;overflow-y:auto;padding-inline-end:.4rem}
@media (max-width:767.98px){.cv-page .customer-detail-tab-content .tab-pane{max-height:none;overflow-y:visible;padding-inline-end:0}}
.cv-page .customer-tab-panel-title{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem}
.cv-page .cv-desc-scroll{max-height:160px;overflow-y:auto;padding-inline-end:.4rem}
.cv-page .customer-tab-empty, .cv-page .customer-timeline-empty{text-align:center;padding:3rem 1rem;color:#a8a29e;}
.cv-page .customer-tab-empty i, .cv-page .customer-timeline-empty i{font-size:2rem;color:#d6d3d1;margin-bottom:.6rem;display:block}
.cv-page .followup-history-table, .cv-page table.table{font-size:.85rem}
.cv-page .table-responsive table thead th{background:#faf9f5;color:#78716c;font-weight:700;font-size:.74rem;border-bottom:1px solid var(--cv-line);}
.cv-page .table-responsive table tbody tr:hover{background:#faf8f2}
.cv-page .customer-timeline{display:flex;flex-direction:column}
.cv-page .customer-timeline-item{display:flex;align-items:stretch;gap:.9rem}
.cv-page .customer-timeline-marker-col{display:flex;flex-direction:column;align-items:center;flex-shrink:0}
.cv-page .customer-timeline-marker{box-sizing:border-box !important;margin:0 !important;padding:0 !important;border:none !important;width:36px !important;min-width:36px !important;max-width:36px !important;height:36px !important;min-height:36px !important;max-height:36px !important;flex-shrink:0 !important;flex-grow:0 !important;line-height:1 !important;border-radius:50% !important;aspect-ratio:1/1;display:flex !important;align-items:center;justify-content:center;overflow:hidden;font-size:.9rem;color:#fff;background:linear-gradient(135deg,#57534e,#292524);box-shadow:0 0 0 4px #fff, 0 2px 6px rgba(0,0,0,.15);}
.cv-page .customer-timeline-marker i{color:#fff !important;font-size:.85rem;line-height:1;width:auto !important;height:auto !important;background:none !important}
.cv-page .customer-timeline-connector{flex:1;width:2px;min-height:14px;margin:.3rem 0;border-radius:2px;background:linear-gradient(180deg,var(--cv-gold),#e7e2d3 90%)}
.cv-page .customer-timeline-item:last-child .customer-timeline-connector{background:transparent}
.cv-page .customer-timeline-item.is-create .customer-timeline-marker{background:linear-gradient(135deg,#0f766e,#134e4a)}
.cv-page .customer-timeline-item.is-followup .customer-timeline-marker{background:linear-gradient(135deg,#0891b2,#155e75)}
.cv-page .customer-timeline-item.is-referral .customer-timeline-marker{background:linear-gradient(135deg,#7c3aed,#4c1d95)}
.cv-page .customer-timeline-item.is-edit .customer-timeline-marker{background:linear-gradient(135deg,#b45309,#78350f)}
.cv-page .customer-timeline-item.is-note .customer-timeline-marker{background:linear-gradient(135deg,#be185d,#831843)}
.cv-page .customer-timeline-body{flex:1;min-width:0;background:#fdfcf9;border:1px solid var(--cv-line);border-radius:14px;padding:.85rem 1rem;margin-bottom:1.1rem;}
.cv-page .customer-timeline-item:last-child .customer-timeline-body{margin-bottom:0}
.cv-page .customer-timeline-head{display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.3rem}
.cv-page .customer-timeline-label{font-size:.74rem;font-weight:800;color:var(--cv-gold);letter-spacing:.02em;white-space:nowrap}
.cv-page .customer-timeline-date{font-size:.74rem;color:#a8a29e;white-space:nowrap}
.cv-page .customer-timeline-title{font-size:.87rem;color:var(--cv-ink);font-weight:600}
.cv-page .customer-timeline-user{font-size:.76rem;color:#a8a29e;margin-top:.3rem}
.cv-page .msv-block{margin-top:1.25rem}
.cv-quotes-card{border-top:3px solid transparent;border-image:linear-gradient(90deg,var(--cv-gold),#f1dfa8,var(--cv-gold)) 1}
.cv-quotes-icon{width:38px;height:38px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b0f1a,var(--cv-gold));color:#fff;font-size:.95rem;box-shadow:0 6px 14px -7px rgba(201,162,75,.6);}
.cv-page .cv-btn-gold{border:none;border-radius:12px;padding:.5rem 1.1rem;font-weight:700;font-size:.85rem;color:#241d0a;background:linear-gradient(135deg,var(--cv-gold-2),var(--cv-gold));box-shadow:0 8px 16px -8px rgba(201,162,75,.7);display:inline-flex;align-items:center;gap:.4rem;transition:.15s ease;}
.cv-page .cv-btn-gold:hover{transform:translateY(-2px);box-shadow:0 10px 20px -8px rgba(201,162,75,.8)}
.cv-page .cv-btn-gold-lg{padding:.75rem 1.5rem;font-size:.92rem;border-radius:14px}
.cv-page .customer-tab-panel form .form-label{font-size:.8rem;font-weight:700;color:#57534e;margin-bottom:.4rem;display:flex;align-items:center;gap:.35rem;}
.cv-page .customer-tab-panel form .form-label::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--cv-gold);display:inline-block;}
.cv-page .customer-tab-panel form .form-control,.cv-page .customer-tab-panel form .form-select{border:1px solid var(--cv-line);border-radius:12px;padding:.6rem .85rem;font-size:.86rem;background:#fdfcf9;transition:.15s ease;box-shadow:none;}
.cv-page .customer-tab-panel form .form-control:focus,.cv-page .customer-tab-panel form .form-select:focus{border-color:var(--cv-gold);background:#fff;box-shadow:0 0 0 4px rgba(201,162,75,.15);}
.cv-page .customer-tab-panel form textarea.form-control{border-radius:14px}
.cv-page #meeting_conductor_wrap{background:#faf9f5;border:1px dashed var(--cv-line);border-radius:14px;padding:.9rem 1rem;}
.cv-page .alert{border-radius:16px;border:1px solid transparent}
.cv-page .phone-conflict-inline-item{display:inline-flex;flex-direction:column;background:#fff;border:1px solid #fecaca;border-radius:12px;padding:.4rem .7rem;text-decoration:none;color:inherit;font-size:.78rem;}
</style>

<div class="cv-page">

<?php if ($pendingMeetingVerif): ?>
  <div class="alert alert-info compact-text">
    <i class="fa-solid fa-hourglass-half"></i>
    <strong>در انتظار تایید جلسه</strong> — درخواست ثبت «جلسه برگزار شد» برای این مشتری فرستاده شده و منتظر تایید
    <strong><?= e($pendingMeetingVerif['conductor_name']) ?></strong> است. تا تایید ایشان، وضعیت مشتری همچنان
    «<?= e($pendingMeetingVerif['previous_status']) ?>» باقی می‌ماند.
  </div>
<?php endif; ?>

<?php if ($phoneConflicts && $canSeeConflictWarning): ?>
  <div class="alert alert-danger phone-conflict-alert compact-text">
    <div class="d-flex align-items-start gap-2">
      <i class="fa-solid fa-triangle-exclamation mt-1"></i>
      <div class="flex-grow-1">
        <strong>هشدار تداخل مشتری</strong>
        <div class="mt-1">یکی از شماره‌های این مشتری در پرونده مشتریِ <?= to_persian_digits((string)count($phoneConflicts)) ?> رکورد دیگر هم ثبت شده و این مشتری با کارشناس‌های دیگری نیز در ارتباط است.</div>
        <div class="phone-conflict-inline-list mt-2">
          <?php foreach ($phoneConflicts as $other): ?>
            <?php if (can_view_customer_basic($user, (int) $other['owner_user_id'])): ?>
              <a href="customer_view.php?id=<?= (int)$other['id'] ?>" class="phone-conflict-inline-item">
                <span class="fw-bold"><?= e($other['full_name']) ?></span>
                <span class="text-muted">مسئول: <?= e($other['owner_name']) ?> (<?= e(role_label($other['owner_role'])) ?>)</span>
              </a>
            <?php else: ?>
              <div class="phone-conflict-inline-item phone-conflict-inline-item-disabled" title="شما اجازه مشاهده پرونده این مشتری را ندارید">
                <span class="fw-bold"><?= e($other['full_name']) ?></span>
                <span class="text-muted">مسئول: <?= e($other['owner_name']) ?> (<?= e(role_label($other['owner_role'])) ?>)</span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div class="small mt-2 text-danger-emphasis">
          <span>لطفاً قبل از پیگیری بعدی مشخص شود مسئول اصلی مشتری کدام کارشناس است.</span>
        </div>
        <?php if (can_manage_service_requests($user)): ?>
          <form method="post" class="d-flex flex-wrap align-items-end gap-2 mt-2" onsubmit="return confirm('این عمل، بقیه‌ی رکوردهای تکراری رو در پرونده‌ی نفر انتخاب‌شده ادغام می‌کنه و از لیست پیگیری بقیه کارشناس‌ها کامل حذف می‌شن. مطمئنید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="conflict_ids[]" value="<?= (int) $id ?>">
            <?php foreach ($phoneConflicts as $other): ?>
              <input type="hidden" name="conflict_ids[]" value="<?= (int) $other['id'] ?>">
            <?php endforeach; ?>
            <div>
              <label class="form-label small mb-1">این مشتری واقعاً مال کدوم کارشناسه؟</label>
              <select name="assign_conflict_to" class="form-select form-select-sm">
                <option value="<?= (int) $id ?>"><?= e($customer['full_name']) ?> — <?= e($customer['owner_name']) ?></option>
                <?php foreach ($phoneConflicts as $other): ?>
                  <option value="<?= (int) $other['id'] ?>"><?= e($other['full_name']) ?> — <?= e($other['owner_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-user-check"></i> انتصاب نهایی (ادغام بقیه در این یکی)</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($duplicateCandidates): ?>
  <div class="alert alert-warning d-flex justify-content-between align-items-start flex-wrap gap-2 compact-text">
    <div>
      <i class="fa-solid fa-triangle-exclamation"></i>
      <?= to_persian_digits((string) count($duplicateCandidates)) ?> مشتری دیگر با همین نام («<?= e($customer['full_name']) ?>») پیدا شد؛
      ممکن است ثبت تکراری همین شخص با شماره دیگر باشد.
      <div class="mt-2 d-flex flex-wrap gap-2">
        <?php foreach ($duplicateCandidates as $dup): ?>
          <span class="badge bg-white text-dark border">
            <?= e($dup['full_name']) ?> - <span dir="ltr"><?= e($dup['mobile']) ?></span>
            <?php if ($user['role'] === 'admin'): ?> (<?= e($dup['owner_name']) ?>)<?php endif; ?>
            <?php if ($canManage): ?><a href="customer_merge.php?id=<?= $id ?>&amp;with=<?= (int) $dup['id'] ?>" class="text-decoration-none">— ادغام</a><?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 cv-detail-row">
  <div class="col-lg-4">
    <div class="card p-4">
      <div class="cv-profile-head">
        <span class="cv-profile-avatar"><?= e($__cvInitial) ?></span>
        <div class="flex-grow-1">
          <h5 class="cv-profile-name"><?= e($customer['full_name']) ?></h5>
          <?php if (!empty($customer['city']) || !empty($customer['job'])): ?>
            <div class="cv-profile-sub">
              <?php if (!empty($customer['city'])): ?><span><i class="fa-solid fa-location-dot"></i> <?= e($customer['city']) ?></span><?php endif; ?>
              <?php if (!empty($customer['job'])): ?><span><i class="fa-solid fa-briefcase"></i> <?= e($customer['job']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="cv-icon-actions mb-3">
        <a href="customer_profile.php?id=<?= $id ?>" class="cv-icon-btn cv-icon-btn-360" title="پروفایل ۳۶۰ مشتری">
          <span class="fa-stack">
            <i class="fa-solid fa-rotate fa-stack-2x"></i>
            <i class="fa-solid fa-user fa-stack-1x"></i>
          </span>
        </a>
        <a href="customer_referrals.php" class="cv-icon-btn" title="تاریخچه ارجاع‌های من"><i class="fa-solid fa-clock-rotate-left"></i></a>
        <?php if ($canManage): ?>
          <a href="customer_merge.php?id=<?= $id ?>" class="cv-icon-btn cv-icon-btn-warning" title="ادغام با مشتری دیگر"><i class="fa-solid fa-code-merge"></i></a>
          <a href="customer_refer.php?id=<?= $id ?>&amp;from=<?= e($returnTo) ?>" class="cv-icon-btn" title="ارجاع به کارشناس دیگر"><i class="fa-solid fa-share-from-square"></i></a>
          <a href="customer_edit.php?id=<?= $id ?>&amp;from=<?= urlencode($returnTo) ?>" class="cv-icon-btn" title="ویرایش اطلاعات"><i class="fa-solid fa-pen"></i></a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('مطمئنید می‌خواهید این مشتری و کل تاریخچه پیگیری‌هایش برای همیشه حذف شود؟ این عمل قابل بازگشت نیست.');">
            <?= csrf_field() ?>
            <input type="hidden" name="delete_customer" value="1">
            <input type="hidden" name="from" value="<?= e($returnTo) ?>">
            <button type="submit" class="cv-icon-btn cv-icon-btn-danger" title="حذف مشتری"><i class="fa-solid fa-trash"></i></button>
          </form>
        <?php endif; ?>
      </div>

      <div class="d-grid gap-2 mb-3">
        <a href="<?= e($callLink) ?>" class="btn btn-success btn-call"><i class="fa-solid fa-phone"></i> تماس تلفنی</a>
        <?php foreach ($messengersBySlot['mobile'] as $m): $link = messenger_link($m, $customer['mobile']); if (!$link) continue; ?>
          <a href="<?= e($link) ?>" target="_blank" class="btn btn-info btn-chat text-white">
            <i class="<?= e(social_network_icon_class($m)) ?>"></i>
            چت در <?= e($socialLabels[$m] ?? '') ?>
          </a>
        <?php endforeach; ?>
        <?php if ($callLink2): ?>
          <a href="<?= e($callLink2) ?>" class="btn btn-outline-success btn-call"><i class="fa-solid fa-phone"></i> تماس با شماره دوم</a>
        <?php endif; ?>
        <?php foreach ($messengersBySlot['mobile_2'] as $m): $link = !empty($customer['mobile_2']) ? messenger_link($m, $customer['mobile_2']) : null; if (!$link) continue; ?>
          <a href="<?= e($link) ?>" target="_blank" class="btn btn-outline-info btn-chat">
            <i class="<?= e(social_network_icon_class($m)) ?>"></i>
            چت در <?= e($socialLabels[$m] ?? '') ?> (شماره دوم)
          </a>
        <?php endforeach; ?>
      </div>

      <table class="table table-sm table-compact mb-0">
        <tr><th class="text-muted">موبایل</th><td dir="ltr"><?= e($customer['mobile']) ?></td></tr>
        <?php if (!empty($customer['mobile_2'])): ?>
          <tr><th class="text-muted">موبایل دوم</th><td dir="ltr"><?= e($customer['mobile_2']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($customer['landline_phone'])): ?>
          <tr><th class="text-muted">تلفن ثابت</th><td dir="ltr"><?= e($customer['landline_phone']) ?></td></tr>
        <?php endif; ?>
        <tr><th class="text-muted">پیام‌رسان‌ها</th><td>
          <?php
          $allMessengerLabels = [];
          foreach ($messengersBySlot['mobile'] as $m) { $allMessengerLabels[] = e($socialLabels[$m] ?? $m); }
          foreach ($messengersBySlot['mobile_2'] as $m) { $allMessengerLabels[] = e($socialLabels[$m] ?? $m) . ' (شماره دوم)'; }
          echo $allMessengerLabels ? implode('، ', $allMessengerLabels) : '-';
          ?>
        </td></tr>
        <tr><th class="text-muted">وضعیت فعلی</th><td>
          <span class="badge badge-status <?= status_badge_class($customer['status']) ?>"><?= e($customer['status']) ?></span>
          <?php if (!empty($customer['had_meeting']) && $customer['status'] !== 'جلسه برگزار شد'): ?>
            <span class="cv-meeting-tick" title="این مشتری حداقل یک‌بار جلسه برگزار کرده، حتی اگر وضعیت فعلی‌اش چیز دیگری باشد">
              <i class="fa-solid fa-circle-check"></i>
            </span>
          <?php endif; ?>
        </td></tr>
        <tr><th class="text-muted">تاریخ ارتباط اولیه</th><td><?= to_jalali($customer['initial_contact_date']) ?></td></tr>
        <tr><th class="text-muted">سررسید پیگیری</th><td><?= to_jalali($customer['next_followup_date']) ?></td></tr>
        <tr><th class="text-muted">شهر</th><td><?= e($customer['city'] ?: '-') ?></td></tr>
        <tr><th class="text-muted">شغل</th><td><?= e($customer['job'] ?: '-') ?></td></tr>
        <?php if (can_manage_service_requests($user) || $canViewReferralHistory): ?>
        <tr><th class="text-muted">کارشناس مسئول</th><td><?= e($customer['owner_name']) ?> (<?= e(role_letter($customer['owner_role'])) ?>)</td></tr>
        <?php endif; ?>
        <?php if (!empty($customer['first_advisor_name'])): ?>
        <tr><th class="text-muted">کارشناس اول</th><td>
          <?= e($customer['first_advisor_name']) ?>
          <?php if ((int) $customer['first_advisor_user_id'] !== (int) $customer['owner_user_id']): ?>
            <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1">الان دستِ کارشناسِ دیگری است</span>
          <?php endif; ?>
        </td></tr>
        <?php endif; ?>
        <tr><th class="text-muted">تعداد پیگیری‌ها</th><td><?= to_persian_digits((string) (int) $customer['followup_count']) ?></td></tr>
        <?php
        $__talk = ['n' => 0, 'sec' => 0];
        try {
            $__ts = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(call_duration_seconds), 0) AS sec FROM followups
                                   WHERE customer_id = ? AND created_by = ? AND call_duration_seconds > 0');
            $__ts->execute([(int) $customer['id'], (int) $customer['owner_user_id']]);
            $__talk = $__ts->fetch(PDO::FETCH_ASSOC) ?: $__talk;
        } catch (Throwable $e) {
        }
        $__sec = (int) $__talk['sec'];
        $__parts = [];
        if ($__sec >= 3600) $__parts[] = intdiv($__sec, 3600) . ' ساعت';
        if ($__sec % 3600 >= 60) $__parts[] = intdiv($__sec % 3600, 60) . ' دقیقه';
        if ($__sec < 3600 && $__sec % 60 > 0) $__parts[] = ($__sec % 60) . ' ثانیه';
        ?>
        <tr><th class="text-muted">مدت کل مکالمه</th><td>
          <?php if ($__sec > 0): ?>
            <b><?= to_persian_digits(implode(' و ', $__parts)) ?></b>
            <span class="text-muted small">(<?= to_persian_digits((string) (int) $__talk['n']) ?> تماسِ برقرارشده با <?= e($customer['owner_name'] ?? 'کارشناس') ?>)</span>
          <?php else: ?>
            <span class="text-muted">مکالمه‌ای ثبت نشده</span>
          <?php endif; ?>
        </td></tr>
      </table>
      <?php if ($customer['description']): ?>
        <hr>
        <div class="text-muted small mb-1 compact-text">توضیحات:</div>
        <div class="compact-text cv-desc-scroll"><?= nl2br(e($customer['description'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="customer-detail-tabs-card">
      <ul class="nav nav-tabs customer-detail-tabs" id="customerDetailTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="followup-new-tab" data-bs-toggle="tab" data-bs-target="#followup-new-pane" type="button" role="tab" aria-controls="followup-new-pane" aria-selected="true">
            <i class="fa-solid fa-phone-volume"></i>
            <span>ثبت پیگیری جدید</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="followup-history-tab" data-bs-toggle="tab" data-bs-target="#followup-history-pane" type="button" role="tab" aria-controls="followup-history-pane" aria-selected="false">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>تاریخچه پیگیری‌ها</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="activity-timeline-tab" data-bs-toggle="tab" data-bs-target="#activity-timeline-pane" type="button" role="tab" aria-controls="activity-timeline-pane" aria-selected="false">
            <i class="fa-solid fa-timeline"></i>
            <span>روند زمانی</span>
            <?php if ($timeline): ?>
              <span class="customer-tab-count"><?= to_persian_digits((string) count($timeline)) ?></span>
            <?php endif; ?>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="service-requests-tab" data-bs-toggle="tab" data-bs-target="#service-requests-pane" type="button" role="tab" aria-controls="service-requests-pane" aria-selected="false">
            <i class="fa-solid fa-headset"></i>
            <span>درخواست خدمات</span>
            <?php if ($linkedServiceRequests): ?>
              <span class="customer-tab-count"><?= to_persian_digits((string) count($linkedServiceRequests)) ?></span>
            <?php endif; ?>
          </button>
        </li>
      </ul>

      <div class="tab-content customer-detail-tab-content" id="customerDetailTabsContent">
        <div class="tab-pane fade show active" id="followup-new-pane" role="tabpanel" aria-labelledby="followup-new-tab" tabindex="0">
          <?php if ($canAddFollowup): ?>
            <div class="customer-tab-panel compact-text">
              <div class="customer-tab-panel-title">
                <div>
                  <h6 class="mb-1"><i class="fa-solid fa-phone-volume text-primary"></i> ثبت پیگیری جدید</h6>
                  <div class="small text-muted">
                    <?php if ($canManage): ?>
                      ارتباط جدید با مشتری را ثبت کنید و در صورت نیاز وضعیت و پیگیری بعدی را مشخص کنید.
                    <?php else: ?>
                      این پیگیری مستقل از کارشناسِ اصلیِ این پرونده ثبت می‌شود — وضعیت/سررسیدِ خودتان جداگانه ذخیره می‌شود.
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <?php if (followup_date_migration_pending($pdo)): ?>
                <?php render_followup_migration_warning($user['role'] === 'admin'); ?>
              <?php endif; ?>

              <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger py-2"><?= e($err) ?></div>
              <?php endforeach; ?>

              <form method="post" id="followupForm" data-customer-id="<?= (int) $customer['id'] ?>" data-customer-updated-at="<?= e((string) ($customer['updated_at'] ?? '')) ?>" data-role="<?= e($user['role']) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="add_followup" value="1">
                <?php $__fuBack = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_followup'])); ?>
                <input type="hidden" name="from" value="<?= e($returnTo) ?>">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">تاریخ این پیگیری</label>
                    <input type="text" name="followup_date" class="form-control jalali-date" dir="ltr" autocomplete="off" value="<?= e($__fuBack && trim((string) ($_POST['followup_date'] ?? '')) !== '' ? (string) $_POST['followup_date'] : today_jalali()) ?>" required>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">وضعیت جدید</label>
                    <select name="status" id="status_select" class="form-select" required>
                      <?php $__refStatusForSelect = ($__fuBack && trim((string) ($_POST['status'] ?? '')) !== '') ? (string) $_POST['status'] : ($canManage ? $customer['status'] : (string) ($myRelation['status'] ?? 'جدید')); ?>
                      <?php foreach ($statusOptions as $st): ?>
                        <option value="<?= e($st) ?>" <?= $__refStatusForSelect === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4" id="next_followup_wrap">
                    <label class="form-label">سررسید پیگیری</label>
                    <input type="text" name="next_followup_date" id="next_followup_input" class="form-control jalali-date" dir="ltr" autocomplete="off" required placeholder="۱۴۰۵/۰۷/۱۰" value="<?= e($__fuBack ? (string) ($_POST['next_followup_date'] ?? '') : '') ?>">
                  </div>
                  <?php if ($user['role'] === 'A'): ?>
                  <div class="col-md-6" id="meeting_conductor_wrap" style="display:none;">
                    <label class="form-label">این جلسه رو کدوم کارشناس (واحد B یا C) برگزار کرده؟</label>
                    <input type="text" id="meeting_conductor_search" class="form-control" list="meeting_conductor_datalist"
                           placeholder="جستجوی نام کارشناس..." autocomplete="off">
                    <datalist id="meeting_conductor_datalist">
                      <?php foreach ($meetingConductorOptions as $mc): ?>
                        <option data-id="<?= (int) $mc['id'] ?>" value="<?= e(person_pick_label((string) $mc['full_name'], $mc['mobile'] ?? null, 'واحد ' . $mc['role'])) ?>"></option>
                      <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="meeting_conductor_id" id="meeting_conductor_select" value="">
                    <div class="form-text">تا این کارشناس تایید نکنه، وضعیت مشتری «جلسه برگزار شد» نمی‌شه — می‌ره توی صف تاییدِ اون.</div>
                  </div>
                  <?php endif; ?>
                  <div class="col-12">
                    <label class="form-label">توضیحات پیگیری (در صورت نیاز)</label>
                    <textarea name="description" id="followup_description" class="form-control" rows="3"><?= e($__fuBack ? (string) ($_POST['description'] ?? '') : '') ?></textarea>
                  </div>
                </div>
                <div id="offlineFollowupNotice" class="alert alert-warning py-2 mt-3" style="display:none">
                  اتصال اینترنت برقرار نیست — این پیگیری در گوشی ذخیره می‌شود و به‌محض وصل‌شدن اینترنت خودکار ارسال می‌شود.
                </div>
                <button type="submit" class="cv-btn-gold cv-btn-gold-lg mt-3"><i class="fa-solid fa-check"></i> ثبت پیگیری شماره <?= to_persian_digits((string) ((int) $customer['followup_count'] + 1)) ?></button>
              </form>
              <script>
              (function () {
                var form = document.getElementById('followupForm');
                if (!form) return;
                form.addEventListener('submit', function (e) {
                  if (navigator.onLine) return;
                  var statusVal = document.getElementById('status_select').value;
                  var role = form.getAttribute('data-role');
                  if (role === 'A' && statusVal === 'جلسه برگزار شد') {
                    e.preventDefault();
                    alert('ثبتِ «جلسه برگزار شد» نیاز به تایید کارشناس دیگر دارد و در حالت آفلاین امکان‌پذیر نیست. لطفاً بعد از وصل‌شدن اینترنت دوباره تلاش کنید.');
                    return;
                  }
                  if (!window.AradOffline) {
                    return;
                  }
                  e.preventDefault();
                  window.AradOffline.queueFollowupUpdate({
                    customerId: form.getAttribute('data-customer-id'),
                    status: statusVal,
                    nextFollowupDate: document.getElementById('next_followup_input').value.trim(),
                    note: document.getElementById('followup_description').value.trim(),
                    baseUpdatedAt: form.getAttribute('data-customer-updated-at'),
                  }).then(function () {
                    document.getElementById('offlineFollowupNotice').style.display = 'block';
                    form.querySelector('button[type=submit]').disabled = true;
                  });
                });
              })();
              </script>
            </div>
          <?php else: ?>
            <div class="customer-tab-panel">
              <div class="alert alert-info d-flex align-items-center gap-2 mb-0 compact-text">
                <i class="fa-solid fa-eye"></i>
                <span>این مشتری قبلاً توسط شما ارجاع داده شده است. پرونده و تاریخچه برای شما قابل مشاهده است، اما چون مسئول فعلی مشتری شخص دیگری است، امکان ثبت تغییر جدید ندارید.</span>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="followup-history-pane" role="tabpanel" aria-labelledby="followup-history-tab" tabindex="0">
          <div class="customer-tab-panel compact-text">
            <div class="customer-tab-panel-title">
              <div>
                <h6 class="mb-1"><i class="fa-solid fa-clock-rotate-left text-primary"></i> تاریخچه پیگیری‌ها</h6>
                <div class="small text-muted">سوابق ارتباط‌های ثبت‌شده با این مشتری.</div>
              </div>
              <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2"><?= to_persian_digits((string) count($followups)) ?> پیگیری</span>
            </div>

            <?php if ($receivedReferral): ?>
              <div class="alert alert-info small py-2 mb-2">
                <i class="fa-solid fa-share-from-square"></i>
                این مشتری در <?= to_persian_digits(to_jalali(substr((string) $receivedReferral['created_at'], 0, 10))) ?> از «<b><?= e((string) ($receivedReferral['from_name'] ?? '')) ?></b>» به شما ارجاع شده
                <?php if (!empty($receivedReferral['by_name']) && $receivedReferral['by_name'] !== $receivedReferral['from_name']): ?>(توسطِ <?= e((string) $receivedReferral['by_name']) ?>)<?php endif; ?>؛
                پیگیری‌ها و توضیحاتِ قبل از ارجاع هم این‌جا آمده (نامِ ثبت‌کننده زیرِ هر ردیف) تا بدانید چه مراحلی با مشتری طی شده.
              </div>
            <?php endif; ?>
            <?php if (!$followups): ?>
              <div class="customer-tab-empty">
                <i class="fa-regular fa-clock"></i>
                <div class="fw-bold mt-2">هنوز پیگیری‌ای ثبت نشده است.</div>
              </div>
            <?php else: ?>
              <style>
                .followup-history-table { width: 100%; table-layout: fixed; }
                .followup-history-table th,
                .followup-history-table td {
                  vertical-align: top;
                  white-space: normal;
                  word-break: break-word;
                  overflow-wrap: anywhere;
                }
                .followup-history-table th:nth-child(1), .followup-history-table td:nth-child(1) { width: 16%; }
                .followup-history-table th:nth-child(2), .followup-history-table td:nth-child(2) { width: 18%; }
                .followup-history-table th:nth-child(3), .followup-history-table td:nth-child(3) { width: 20%; }
                .followup-history-table th:nth-child(4), .followup-history-table td:nth-child(4) { width: 18%; }
                .followup-history-table th:nth-child(5), .followup-history-table td:nth-child(5) { width: 28%; }
              </style>
              <div class="table-responsive followup-history-responsive">
                <table class="table table-sm table-compact align-middle followup-history-table">
                  <thead><tr><th>#</th><th>تاریخ</th><th>وضعیت</th><th>مدت مکالمه</th><th>توضیحات</th></tr></thead>
                  <tbody>
                  <?php foreach ($followups as $f): ?>
                    <tr>
                      <td data-label="#">
                        پیگیری <?= to_persian_digits((string) (int) $f['followup_number']) ?>
                        <?php if (($f['source'] ?? 'manual') === 'call_import'): ?>
                          <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">ثبت کالیزر</span>
                        <?php elseif (($f['source'] ?? 'manual') === 'novatel_import'): ?>
                          <span class="badge border" style="background:#dbeafe;color:#1d4ed8;border-color:#93c5fd !important">ثبت نواتل</span>
                        <?php endif; ?>
                        <?php if (($canSeeOthersFollowups || (int) $f['created_by'] !== (int) $user['id']) && !empty($f['created_by_name'])): ?>
                          <div class="small text-muted mt-1" style="font-size:11px"><i class="fa-regular fa-user"></i> <?= e((string) $f['created_by_name']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td data-label="تاریخ"><?= to_jalali($f['followup_date']) ?></td>
                      <td data-label="وضعیت"><span class="badge badge-status <?= status_badge_class($f['status_after']) ?>"><?= e($f['status_after']) ?></span></td>
                      <td data-label="مدت مکالمه"><?= isset($f['call_duration_seconds']) && $f['call_duration_seconds'] !== null ? format_duration_seconds((int) $f['call_duration_seconds']) : '-' ?></td>
                      <td data-label="توضیحات" class="followup-description-cell"><?= $f['description'] ? nl2br(e($f['description'])) : '-' ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="tab-pane fade" id="activity-timeline-pane" role="tabpanel" aria-labelledby="activity-timeline-tab" tabindex="0">
          <div class="customer-tab-panel customer-activity-timeline-card compact-text">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
              <div>
                <h6 class="mb-1"><i class="fa-solid fa-timeline text-primary"></i> روند زمانی</h6>
                <div class="small text-muted">تمام رویدادهای مهم پرونده به ترتیب زمان؛ حتی بعد از ارجاع مشتری، سابقه حفظ می‌شود.</div>
              </div>
              <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2">
                <?= to_persian_digits((string) count($timeline)) ?> رویداد
              </span>
            </div>

            <?php if (!$timeline): ?>
              <div class="customer-timeline-empty">
                <i class="fa-regular fa-clock"></i>
                <div class="fw-bold mt-2">هنوز فعالیتی برای این مشتری ثبت نشده است.</div>
                <div class="small text-muted mt-1">با ثبت اولین پیگیری، فعالیت‌ها در این بخش نمایش داده می‌شوند.</div>
              </div>
            <?php else: ?>
              <?php
                $timelineMeta = [
                  'create'          => ['icon' => 'fa-user-plus',          'label' => 'ثبت مشتری',    'class' => 'is-create'],
                  'followup'        => ['icon' => 'fa-phone-volume',       'label' => 'پیگیری',       'class' => 'is-followup'],
                  'referral'        => ['icon' => 'fa-share-from-square',  'label' => 'ارجاع مشتری',  'class' => 'is-referral'],
                  'edit'            => ['icon' => 'fa-pen',                'label' => 'ویرایش',       'class' => 'is-edit'],
                  'note'            => ['icon' => 'fa-note-sticky',        'label' => 'یادداشت',      'class' => 'is-note'],
                  'service_request' => ['icon' => 'fa-headset',            'label' => 'درخواست خدمات', 'class' => 'is-referral'],
                ];
              ?>
              <div class="customer-timeline">
                <?php foreach ($timeline as $item):
                  $type = $item['activity_type'] ?? 'note';
                  $meta = $timelineMeta[$type] ?? ['icon' => 'fa-circle-dot', 'label' => 'فعالیت', 'class' => 'is-default'];
                  $dateRaw = (string) ($item['created_at'] ?? '');
                  $datePart = substr($dateRaw, 0, 10);
                  $timePart = (strlen($dateRaw) >= 16 && empty($item['date_only'])) ? substr($dateRaw, 11, 5) : '';
                ?>
                  <div class="customer-timeline-item <?= e($meta['class']) ?>">
                    <div class="customer-timeline-marker-col">
                      <div class="customer-timeline-marker"><i class="fa-solid <?= e($meta['icon']) ?>"></i></div>
                      <div class="customer-timeline-connector"></div>
                    </div>
                    <div class="customer-timeline-body">
                      <div class="customer-timeline-head">
                        <span class="customer-timeline-label"><?= e($meta['label']) ?></span>
                        <span class="customer-timeline-date" dir="ltr">
                          <?= $datePart ? e(to_jalali($datePart)) : '-' ?><?php if ($timePart): ?> <?= to_persian_digits($timePart) ?><?php endif; ?>
                        </span>
                      </div>
                      <div class="customer-timeline-title"><?= e($item['description'] ?? '') ?></div>
                      <?php if (!empty($item['auto'])): ?>
                        <div class="customer-timeline-user"><i class="fa-solid fa-gear"></i> به‌صورت خودکار ثبت شد</div>
                      <?php elseif (!empty($item['user_name'])): ?>
                        <div class="customer-timeline-user"><i class="fa-solid fa-user"></i> توسط <?= e($item['user_name']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="tab-pane fade" id="service-requests-pane" role="tabpanel" aria-labelledby="service-requests-tab" tabindex="0">
          <div class="customer-tab-panel compact-text">
            <div class="customer-tab-panel-title">
              <div>
                <h6 class="mb-1"><i class="fa-solid fa-headset text-primary"></i> درخواست خدمات</h6>
                <div class="small text-muted">درخواست‌های خدماتی که برای این مشتری ثبت/ارجاع شده.</div>
              </div>
              <?php if ($linkedServiceRequests): ?>
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2"><?= to_persian_digits((string) count($linkedServiceRequests)) ?> درخواست</span>
              <?php endif; ?>
            </div>

            <?php if (!$linkedServiceRequests): ?>
              <div class="customer-tab-empty">
                <i class="fa-solid fa-headset"></i>
                <div class="fw-bold mt-2">هنوز درخواست خدماتی برای این مشتری ثبت نشده است.</div>
              </div>
            <?php else: ?>
              <?php foreach ($linkedServiceRequests as $svcReq): ?>
                <div class="border rounded-3 p-3 mb-2">
                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <div><b>خدمت موردنیاز:</b> <?= e($svcReq['service_description'] ?: '—') ?></div>
                    <span class="badge <?= $svcReq['status'] === 'referred' ? 'bg-success' : 'bg-warning text-dark' ?>">
                      <?= $svcReq['status'] === 'referred' ? 'ارجاع شد' : 'در انتظار ارجاع' ?>
                    </span>
                  </div>
                  <?php if (!empty($svcReqEventsByReq[$svcReq['id']])): ?>
                    <ul class="small text-muted mb-0 ps-3">
                      <?php foreach ($svcReqEventsByReq[$svcReq['id']] as $evt): ?>
                        <li><?= e($evt['description']) ?> — <?= to_persian_digits(date('Y/m/d H:i', strtotime($evt['created_at']))) ?><?= $evt['actor_name'] ? ' (' . e($evt['actor_name']) . ')' : '' ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php merchant_services_render_block($pdo, $id, 'customer_view.php?id=' . $id, true, 'merchant-services-full'); ?>

<?php
$__msvmPath = __DIR__ . '/includes/services_functions.php';
if (file_exists($__msvmPath)) {
    require_once $__msvmPath;
    if (services_module_ready($pdo)) {
        render_missing_services_block($pdo, $id, $canManage, 'quote_edit.php', 'missing-services');
    }
}
?>

<script>
(function () {
  var NO_FOLLOWUP_STATUSES = ['انصرافی', 'نامرتبط'];
  var MEETING_STATUS = 'جلسه برگزار شد';
  var statusEl = document.getElementById('status_select');
  var wrapEl   = document.getElementById('next_followup_wrap');
  var inputEl  = document.getElementById('next_followup_input');
  var conductorWrap = document.getElementById('meeting_conductor_wrap');
  var conductorSearch = document.getElementById('meeting_conductor_search');
  var conductorSelect = document.getElementById('meeting_conductor_select');
  var conductorDatalist = document.getElementById('meeting_conductor_datalist');

  function sync() {
    var hide = NO_FOLLOWUP_STATUSES.indexOf(statusEl.value) !== -1;
    wrapEl.style.display = hide ? 'none' : '';
    inputEl.required = !hide;
    if (hide) inputEl.value = '';

    var isMeeting = statusEl.value === MEETING_STATUS;
    if (conductorWrap && conductorSearch && conductorSelect) {
      conductorWrap.style.display = isMeeting ? '' : 'none';
      conductorSearch.required = isMeeting;
      if (!isMeeting) {
        conductorSearch.value = '';
        conductorSelect.value = '';
      }
    }
  }

  if (conductorSearch && conductorSelect && conductorDatalist) {
    function syncConductorSelection() {
      var typed = conductorSearch.value;
      var options = conductorDatalist.querySelectorAll('option');
      var matched = null;
      for (var i = 0; i < options.length; i++) {
        if (options[i].value === typed) {
          matched = options[i];
          break;
        }
      }
      conductorSelect.value = matched ? matched.getAttribute('data-id') : '';
    }
    conductorSearch.addEventListener('input', syncConductorSelection);
    conductorSearch.addEventListener('change', syncConductorSelection);
  }

  if (statusEl && wrapEl && inputEl) {
    statusEl.addEventListener('change', sync);
    sync();
  }
})();
</script>


<script>
(function(){
  function toggleFollowupDate(){
    const status = document.getElementById('status_select');
    const wrap = document.getElementById('next_followup_wrap');
    const input = document.getElementById('next_followup_input');
    if(!status || !wrap || !input) return;
    const noFollowupStatuses = ['انصرافی','نامرتبط','شاکی'];
    const hide = noFollowupStatuses.includes(status.value);
    wrap.style.display = hide ? 'none' : '';
    input.required = !hide;
    if(hide) input.value = '';
  }
  document.addEventListener('DOMContentLoaded', toggleFollowupDate);
  document.addEventListener('change', function(e){
    if(e.target && e.target.id === 'status_select') toggleFollowupDate();
  });
})();
</script>

<?php
try {
    $__servicesFuncsPath = __DIR__ . '/includes/services_functions.php';
    if (!file_exists($__servicesFuncsPath)) {
        throw new RuntimeException('services module files not deployed');
    }
    require_once $__servicesFuncsPath;
    if (!services_module_ready($pdo)) {
        throw new RuntimeException('services module tables not migrated');
    }
    $quotesStmt = $pdo->prepare('SELECT q.*, u.full_name AS creator_name FROM quotes q JOIN users u ON u.id = q.created_by WHERE q.customer_id = ? ORDER BY q.created_at DESC');
    $quotesStmt->execute([$id]);
    $customerQuotes = $quotesStmt->fetchAll();
    require_once __DIR__ . '/includes/orders_functions.php';
    $__ordersOk = orders_ready($pdo);
    $__quoteOrders = [];
    if ($__ordersOk && $customerQuotes) {
        foreach ($customerQuotes as $__q) {
            $__o = orders_active_for_quote($pdo, (int) $__q['id']);
            if ($__o) $__quoteOrders[(int) $__q['id']] = $__o;
        }
    }
    ?>
<div class="card p-3 p-md-4 mb-3 mt-4 cv-quotes-card">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <span class="cv-quotes-icon"><i class="fa-solid fa-file-invoice"></i></span>
      <h6 class="mb-0 fw-bold">پیش‌فاکتورهای مشتری</h6>
    </div>
    <?php if ($canManage && user_can('quotes_manage', $user)): ?>
    <form method="post" action="quote_edit.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_new">
      <input type="hidden" name="customer_id" value="<?= (int) $id ?>">
      <button type="submit" class="cv-btn-gold"><i class="fa-solid fa-plus"></i> پیش‌فاکتور جدید</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$customerQuotes): ?>
    <div class="customer-tab-empty py-4">
      <i class="fa-regular fa-file-lines"></i>
      <div>هنوز پیش‌فاکتوری برای این مشتری ثبت نشده.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>شماره</th><th>تاریخ</th><th>مبلغ نهایی</th><th>وضعیت</th><th>فاکتور / سفارش</th><th>صادرکننده</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($customerQuotes as $qr): ?>
            <tr>
              <td><?= to_persian_digits($qr['quote_number']) ?></td>
              <td><?= to_jalali(date('Y-m-d', strtotime($qr['created_at']))) ?></td>
              <td><?= format_toman((int) $qr['total_amount']) ?></td>
              <td>
                <?php if ($qr['status'] === 'locked'): ?>
                  <span class="badge bg-danger-subtle text-danger-emphasis"><i class="fa-solid fa-lock"></i> قفل‌شده</span>
                <?php else: ?>
                  <span class="badge bg-success-subtle text-success-emphasis">در حال ویرایش</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $__qo = $__quoteOrders[(int) $qr['id']] ?? null; ?>
                <?php if ($__qo): ?>
                  <a href="order_view.php?id=<?= (int) $__qo['id'] ?>" class="text-decoration-none"><?= orders_status_badge((string) $__qo['status']) ?></a>
                <?php elseif ($qr['status'] === 'locked' && $canManage && user_can('orders_create', $user)): ?>
                  <a href="order_submit.php?quote_id=<?= (int) $qr['id'] ?>" class="btn btn-sm btn-success py-0"><i class="fa-solid fa-file-invoice-dollar"></i> ثبت سفارش</a>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
              <td><?= e($qr['creator_name']) ?></td>
              <td class="d-flex gap-1">
                <a href="quote_edit.php?id=<?= (int) $qr['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i></a>
                <?php if (!$__qo && (is_super_admin($user) || user_can('quotes_delete', $user))): ?>
                <form method="post" action="quote_edit.php" onsubmit="return confirm('این پیش‌فاکتور کامل حذف بشه؟ این کار برگشت‌ناپذیره.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_quote">
                  <input type="hidden" name="quote_id" value="<?= (int) $qr['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
    <?php
    if (file_exists(__DIR__ . '/includes/contracts_functions.php')) {
        require_once __DIR__ . '/includes/contracts_functions.php';
        if (ctr_ready($pdo)) {
            $__contracts = ctr_for_customer($pdo, $id);
            $__ctrByQuote = [];
            foreach ($__contracts as $__c) {
                if ($__c['status'] !== 'cancelled') $__ctrByQuote[(int) $__c['quote_id']] = true;
            }
            $__ctrCanCreate = ($canManage && user_can('quotes_manage', $user)) || ctr_can_approve($user);
            $__ctrCandidates = array_filter($customerQuotes, static fn($q) => $q['status'] === 'locked' && empty($__ctrByQuote[(int) $q['id']]));
            $__ctrSt = ctr_statuses();
            $__ctrCanDelete = ctr_can_delete($user);
            ?>
<div class="card p-3 p-md-4 mb-3 cv-quotes-card" id="customer-contracts">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <span class="cv-quotes-icon"><i class="fa-solid fa-file-signature"></i></span>
      <h6 class="mb-0 fw-bold">قراردادها</h6>
    </div>
    <?php if ($__ctrCanCreate && $__ctrCandidates): ?>
    <form method="post" action="contract_view.php" class="d-flex gap-2 align-items-center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <select name="quote_id" class="form-select form-select-sm" style="max-width:220px">
        <?php foreach ($__ctrCandidates as $__q): ?>
          <option value="<?= (int) $__q['id'] ?>">پیش‌فاکتور <?= e(to_persian_digits((string) $__q['quote_number'])) ?> — <?= format_toman((int) $__q['total_amount']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="cv-btn-gold text-nowrap"><i class="fa-solid fa-plus"></i> ساخت قرارداد</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$__contracts): ?>
    <div class="customer-tab-empty py-3">
      <i class="fa-regular fa-file-lines"></i>
      <div><?= $customerQuotes ? 'هنوز قراردادی ساخته نشده. قرارداد از روی پیش‌فاکتورِ قفل‌شده ساخته می‌شود.' : 'برای ساختِ قرارداد، اول پیش‌فاکتور بسازید و قفل کنید.' ?></div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>شماره قرارداد</th><th>تاریخ</th><th>سندِ مالی</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($__contracts as $__c): $__s = $__ctrSt[$__c['status']] ?? ['label' => $__c['status'], 'color' => 'secondary', 'icon' => 'fa-circle']; ?>
          <tr>
            <td><bdi dir="ltr"><?= e(to_persian_digits((string) $__c['contract_number'])) ?></bdi></td>
            <td><?= to_jalali((string) $__c['contract_date']) ?></td>
            <?php $__inv = function_exists('ctr_live_invoice_order') ? ctr_live_invoice_order($pdo, (int) $__c['quote_id']) : null; ?>
            <td><?php if ($__inv): ?>فاکتور <bdi dir="ltr"><?= e(to_persian_digits((string) $__inv['order_number'])) ?></bdi><?php else: ?>پیش‌فاکتور <bdi dir="ltr"><?= e(to_persian_digits((string) $__c['quote_number'])) ?></bdi><?php endif; ?></td>
            <td><span class="badge text-bg-<?= e($__s['color']) ?>"><i class="fa-solid <?= e($__s['icon']) ?>"></i> <?= e($__s['label']) ?></span></td>
            <td class="text-nowrap">
              <a href="contract_view.php?id=<?= (int) $__c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i> مشاهده</a>
              <a href="contract_print.php?id=<?= (int) $__c['id'] ?>&doc=contract" class="btn btn-sm btn-outline-dark" title="چاپ / PDF"><i class="fa-solid fa-print"></i></a>
              <?php if ($__ctrCanDelete): ?>
                <form method="post" action="contract_view.php" class="d-inline" onsubmit="return confirm('قرارداد <?= e((string) $__c['contract_number']) ?> برای همیشه حذف شود؟\nلینک‌ها و سابقه‌ی ارسالش هم پاک می‌شوند و این کار برگشت‌پذیر نیست.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $__c['id'] ?>">
                  <input type="hidden" name="return" value="customer_view.php?id=<?= (int) $id ?>#customer-contracts">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف قرارداد"><i class="fa-solid fa-trash-can"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
            <?php
        }
    }
    if ($__ordersOk) {
        try {
            require_once __DIR__ . '/includes/legacy_installments.php';
            if (li_ready($pdo) && li_can_manage($pdo, $user, (int) $customer['owner_user_id'])) {
                $__li = li_orders_of($pdo, $id);
                $__liPaid = 0; $__liBal = 0; $__liPend = 0;
                foreach ($__li as $__o) { $__lf = li_summary($pdo, $__o); $__liPaid += (int) $__lf['paid']; $__liBal += (int) $__lf['balance']; $__liPend += (int) $__lf['pending_paid']; }
                ?>
                <div class="card p-3 p-md-4 mb-3" id="legacy-installments" style="border-color:#fbbf24">
                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h6 class="mb-0 fw-bold"><i class="fa-solid fa-hand-holding-dollar text-warning"></i> اقساطِ قبل از سامانه</h6>
                    <a href="customer_legacy_installments.php?id=<?= (int) $id ?>" class="btn btn-sm btn-warning"><i class="fa-solid fa-receipt"></i> <?= $__li ? 'ثبتِ قسطِ دریافتی' : 'ثبتِ بدهیِ قبلی' ?></a>
                  </div>
                  <?php if ($__li): ?>
                    <div class="small">
                      <?= to_persian_digits((string) count($__li)) ?> پرونده — دریافت‌شده: <b class="text-success"><?= to_persian_digits(number_format($__liPaid)) ?></b> تومان
                      · مانده: <b class="text-danger"><?= to_persian_digits(number_format($__liBal)) ?></b> تومان
                      <?= $__liPend > 0 ? ' · در انتظارِ تأییدِ مالی: <b class="text-warning">' . to_persian_digits(number_format($__liPend)) . '</b> تومان' : '' ?>
                    </div>
                  <?php else: ?>
                    <div class="small text-muted">اگر این مشتری از قبلِ سامانه بدهی/قسط دارد و امروز قسطش را دریافت کرده‌اید، این‌جا ثبتش کنید (بدونِ ثبتِ سفارش و خدمتِ جدید). بعد از تأییدِ مالی، سهمِ عملکردِ ثبت‌کننده هم محاسبه می‌شود.</div>
                  <?php endif; ?>
                </div>
                <?php
            }
        } catch (Throwable $e) {
            error_log('legacy installments card: ' . $e->getMessage());
        }
        try { require __DIR__ . '/includes/perf_ownership_card.php'; } catch (Throwable $e) { error_log('ownership card: ' . $e->getMessage()); }
        render_customer_finance_block($pdo, $id);
        render_customer_orders_block($pdo, $id, '');
        render_customer_kyc_block($pdo, $id, $canManage || user_can('finance_orders_view', $user));
    }
} catch (Throwable $__svcErr) {
    if (($user['role'] ?? '') === 'admin' || is_super_admin($user)) {
        echo '<div class="alert alert-warning small">بخشِ پیش‌فاکتور موقتاً در دسترس نیست: ' . e($__svcErr->getMessage()) . '</div>';
    }
}
// پیوست‌های مشتری (هر کارشناس: افزودن؛ ویرایش/حذف: خودِ بارگذارکننده یا مدیر — حداکثر ۲ مگابایت)
try {
    require_once __DIR__ . '/includes/customer_attachments.php';
    catt_render_card($pdo, $id, $user);
} catch (Throwable $e) {
    error_log('customer attachments card: ' . $e->getMessage());
}
?>

</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>