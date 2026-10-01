<?php
/**
 * جزئیاتِ یک سفارش — برای کارشناس (پیگیریِ وضعیت) و واحد مالی (تأیید / رد / در انتظار).
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/contracts_functions.php';
require_once __DIR__ . '/includes/aradbranding_ticket.php';
require_once __DIR__ . '/includes/customer_credit.php';
require_once __DIR__ . '/includes/payment_duplicates.php';
require_once __DIR__ . '/includes/consent_functions.php';
require_once __DIR__ . '/includes/sales_credit.php';

if (!orders_ready($pdo)) {
    perm_deny('ماژولِ سفارش هنوز آماده نیست.', $user);
}
$orderId = (int) ($_GET['id'] ?? 0);
$order = orders_get($pdo, $orderId);
fin_ensure_payment_link_cols($pdo);
if (!$order || !orders_can_view($pdo, $user, $order)) {
    perm_deny('این سفارش پیدا نشد یا اجازه‌ی دیدنش را ندارید.', $user);
}
$canDecide = user_can('finance_orders_decide', $user);
// کارشناس/سرپرستِ مشتری هم می‌تواند پرداخت‌های بعدیِ مشتری را برای وصولِ مطالبات ثبت کند
$canCollect = (int) ($order['owner_user_id'] ?? 0) === (int) $user['id'] || can_manage_service_requests($user)
    || leader_supervises_owner($pdo, $user, (int) $order['seller_user_id']);
$isSeller = (int) $order['seller_user_id'] === (int) $user['id'];
$isLegacyOrder = !empty($order['is_legacy']);   // پرونده‌ی «اقساطِ قبل از سامانه» (بدونِ پیش‌فاکتور/خدمت)
// تعیینِ اقساط: مالی همیشه؛ کارشناس/جمع‌آورنده در سفارشِ «در انتظار/ردشده» — و در پرونده‌ی اقساطِ قبلی همیشه (این پرونده از اول «تأییدشده» است)
$__canEditInst = $canDecide || (($isSeller || $canCollect) && (in_array($order['status'], ['pending', 'rejected'], true) || ($isLegacyOrder && $order['status'] !== 'cancelled')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است.');
        redirect('order_view.php?id=' . $orderId);
    }
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['dup_separate', 'dup_same'], true) && $canDecide) {
        $otherId = (int) ($_POST['other_id'] ?? 0);
        $match = null;
        foreach (pdup_candidates($pdo, $order) as $c) {
            if ((int) $c['order']['id'] === $otherId) { $match = $c['order']; break; }
        }
        if (!$match) {
            flash_set('danger', 'این جفت دیگر به‌عنوانِ واریزیِ تکراری مطرح نیست.');
        } elseif ($action === 'dup_separate') {
            pdup_mark_separate($pdo, $orderId, $otherId, (int) $user['id'], (string) ($_POST['note'] ?? ''));
            flash_set('success', 'ثبت شد: واریزیِ این سفارش و سفارشِ ' . $match['order_number'] . ' دو واریزیِ جدا هستند.');
        } else {
            $r = pdup_mark_duplicate($pdo, $order, $match, $user);
            flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        }
        redirect('order_view.php?id=' . $orderId . '#dup-check');
    }
    if ($action === 'decide' && $canDecide && ($_POST['decision'] ?? '') === 'approved' && empty($_POST['dup_ack'])
        && pdup_ready($pdo) && pdup_candidates($pdo, $order)) {
        flash_set('danger', 'این سفارش هشدارِ «واریزیِ تکراری» دارد. اول در کادرِ قرمز مشخص کنید یک واریزی است یا دو واریزیِ جدا (یا تیکِ «بررسی کردم» را بزنید).');
        redirect('order_view.php?id=' . $orderId . '#dup-check');
    }
    if ($action === 'sales_split' && $canDecide) {
        $r = scr_save($pdo, $order, scr_parse_post($_POST), (int) $user['id']);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message'] !== '' ? $r['message'] : 'تغییری نبود.');
        redirect('order_view.php?id=' . $orderId . '#decide');
    }
    if ($action === 'decide' && $canDecide) {
        $status = (string) ($_POST['decision'] ?? '');
        $confirmed = trim((string) ($_POST['confirmed_amount'] ?? '')) !== '' ? orders_money($_POST['confirmed_amount']) : null;
        // ۱) صفحه‌ی کهنه: اگر سفارش بعد از باز شدنِ این صفحه تغییر کرده (مثلاً کارشناس مبلغ را اصلاح کرده)، تصمیم پذیرفته نمی‌شود
        $__ver = md5((string) $order['status'] . '|' . (string) $order['paid_amount'] . '|' . (string) ($order['submitted_at'] ?? '') . '|' . (string) ($order['payment_date'] ?? ''));
        if (isset($_POST['form_version']) && (string) $_POST['form_version'] !== $__ver) {
            flash_set('danger', 'این سفارش بعد از باز شدنِ صفحه تغییر کرده (مثلاً کارشناس مبلغ یا فیش را اصلاح کرده). صفحه تازه شد؛ اطلاعاتِ جدید را بررسی و دوباره تصمیم بگیرید.');
            redirect('order_view.php?id=' . $orderId);
        }
        // ۲) مبلغِ تأییدی با مبلغِ اعلامیِ کارشناس فرق دارد → تأییدِ آگاهانه لازم است
        if ($status === 'approved' && $confirmed !== null && $confirmed !== (int) $order['paid_amount'] && empty($_POST['amount_mismatch_ack'])) {
            flash_set('danger', 'مبلغِ تأییدی (' . number_format($confirmed) . ' تومان) با مبلغِ اعلامیِ کارشناس (' . number_format((int) $order['paid_amount']) . ' تومان) فرق دارد. اگر عمداً مبلغِ دیگری را تأیید می‌کنید، تیکِ «مبلغِ متفاوت را عمداً تأیید می‌کنم» را بزنید؛ وگرنه مبلغ را اصلاح کنید یا سفارش را رد کنید تا کارشناس اصلاح کند.');
            redirect('order_view.php?id=' . $orderId . '#decide');
        }
        // تاریخِ واریز طبقِ فیش (قبل از تأیید کنترل می‌شود)
        $__rd = trim((string) ($_POST['receipt_date'] ?? ''));
        $__rdG = $__rd !== '' ? to_gregorian(normalize_digits($__rd)) : null;
        if ($status === 'approved' && $__rd !== '' && (!$__rdG || $__rdG > date('Y-m-d'))) {
            flash_set('danger', 'تاریخِ واریزِ طبقِ فیش معتبر نیست (یا در آینده است).');
            redirect('order_view.php?id=' . $orderId);
        }
        // فروشِ مشترک: قبل از تأیید، جمعِ تفکیک با عددِ فروش کنترل می‌شود (فقط گزارشِ فروش؛ سهم عملکرد دست نمی‌خورد)
        $__splitRows = isset($_POST['split_user']) ? scr_parse_post($_POST) : null;
        if ($status === 'approved' && $__splitRows !== null && count($__splitRows) > 1) {
            $__want = $confirmed ?? (int) $order['total_amount'];
            $__sum = array_sum(array_column($__splitRows, 'amount'));
            if ($__sum !== $__want) {
                flash_set('danger', 'فروشِ مشترک: جمعِ مبالغِ تفکیک (' . number_format($__sum) . ') با عددِ فروش (' . number_format($__want) . ' تومان) برابر نیست. اصلاح کنید و دوباره تأیید بزنید.');
                redirect('order_view.php?id=' . $orderId . '#decide');
            }
        }
        $res = orders_decide($pdo, $orderId, $status, $user, trim((string) ($_POST['finance_note'] ?? '')), $confirmed);
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok'] && $status === 'approved' && $__splitRows !== null) {
            try {
                $__o2 = orders_get($pdo, $orderId);
                $__sr = scr_save($pdo, $__o2 ?: $order, $__splitRows, (int) $user['id']);
                if ($__sr['message'] !== '') flash_set($__sr['ok'] ? 'success' : 'warning', $__sr['message']);
            } catch (Throwable $e) {
                error_log('sales split on decide: ' . $e->getMessage());
            }
        }
        if ($res['ok'] && $status === 'approved' && $__rdG) {
            $pdo->prepare("UPDATE sales_order_payments SET paid_at = ? WHERE order_id = ? AND kind = 'initial'")->execute([$__rdG, $orderId]);
            if ($__rdG !== (string) ($order['payment_date'] ?? '')) {
                $pdo->prepare('UPDATE sales_orders SET payment_date = ? WHERE id = ?')->execute([$__rdG, $orderId]);
                orders_add_history($pdo, $orderId, (int) $user['id'], 'note', null, null, 'تاریخِ واریزِ پیش‌پرداخت طبقِ فیش: ' . to_jalali($__rdG));
            }
        }
        // بعد از تأییدِ مالی: تیکتِ سفارش برای مشتری در آراد برندینگ
        if ($res['ok'] && $status === 'approved') {
            try {
                $t = abt_on_order_approved($pdo, $orderId, (int) $user['id']);
                if ($t['message'] !== '') {
                    flash_set($t['ok'] === false ? 'warning' : ($t['ok'] ? 'success' : 'info'), $t['message']);
                }
            } catch (Throwable $e) {
                error_log('abt_on_order_approved: ' . $e->getMessage());
                flash_set('warning', 'سفارش تأیید شد، ولی ساخت/ارسالِ تیکتِ آراد برندینگ با خطا روبه‌رو شد.');
            }
        }
    } elseif ($action === 'abt_acc_sms_done' && abt_can_manage($user)) {
        // یادآوریِ «حسابِ جدید در آراد برندینگ»: مسئول اعلام می‌کند نام کاربری و رمز برای مشتری پیامک شد
        flash_set(abt_account_sms_done($pdo, (int) $order['customer_id'], (int) $user['id']) ? 'success' : 'warning', 'ثبت شد: اطلاعاتِ ورود برای مشتری پیامک شد.');
        redirect('order_view.php?id=' . $orderId . '#abt');
    } elseif (in_array($action, ['ticket_send', 'ticket_save', 'ticket_rebuild', 'ticket_manual', 'ticket_prepare', 'ticket_send_all', 'ticket_resend', 'ticket_reopen'], true) && abt_can_manage($user)) {
        if ($order['status'] !== 'approved') {
            flash_set('danger', 'تیکت فقط برای سفارشِ تأییدشده ساخته/ارسال می‌شود.');
            redirect('order_view.php?id=' . $orderId . '#abt');
        }
        $tid = (int) ($_POST['ticket_id'] ?? 0);
        $all = abt_prepare_items($pdo, $order, (int) $user['id'], $action === 'ticket_rebuild', $action === 'ticket_rebuild' ? $tid : null);
        $ticket = null;
        foreach ($all as $t) {
            if ((int) $t['id'] === $tid) { $ticket = $t; break; }
        }
        if ($action === 'ticket_prepare') {
            flash_set('success', 'تیکت‌های سفارش (یکی برای هر خدمت) آماده شد.');
        } elseif ($action === 'ticket_send_all') {
            $r = abt_send_all($pdo, $order, $all, (int) $user['id']);
            flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        } elseif (!$ticket) {
            flash_set('danger', 'تیکت پیدا نشد.');
        } else {
            if (in_array($action, ['ticket_save', 'ticket_send'], true) && isset($_POST['ticket_subject'], $_POST['ticket_message'])) {
                abt_update_text($pdo, $ticket, (string) $_POST['ticket_subject'], (string) $_POST['ticket_message'], (string) ($_POST['ticket_department'] ?? ''));
                $ticket = abt_get_ticket($pdo, $tid);
            }
            if ($action === 'ticket_send') {
                $r = abt_send($pdo, $order, $ticket, (int) $user['id']);
                flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
            } elseif ($action === 'ticket_resend' || $action === 'ticket_reopen') {
                $r = abt_resend($pdo, $order, $ticket, (int) $user['id'], $action === 'ticket_resend');
                flash_set($r['ok'] ? 'success' : 'danger', ($action === 'ticket_resend' && $r['ok'] ? 'ارسالِ مجدد: ' : '') . $r['message']);
            } elseif ($action === 'ticket_manual') {
                abt_mark_manual($pdo, $order, $ticket, (int) $user['id'], (string) ($_POST['external_id'] ?? ''), (string) ($_POST['external_url'] ?? ''));
                flash_set('success', 'تیکتِ «' . ($ticket['service_title'] ?? '') . '» به‌عنوانِ «ثبت‌شده به‌صورتِ دستی» علامت خورد.');
            } elseif ($action === 'ticket_rebuild') {
                flash_set('success', 'متنِ تیکت از روی تنظیماتِ خدمت دوباره ساخته شد.');
            } else {
                flash_set('success', 'تیکت ذخیره شد.');
            }
        }
        redirect('order_view.php?id=' . $orderId . '#abt');
    } elseif ($action === 'finance_note' && $canDecide) {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            orders_add_history($pdo, $orderId, (int) $user['id'], 'note', null, null, $note);
            flash_set('success', 'یادداشت ثبت شد.');
        }
    } elseif ($action === 'cancel' && (is_super_admin($user) || user_can('orders_delete', $user)) && in_array($order['status'], ['pending', 'rejected'], true)) {
        $pdo->prepare("UPDATE sales_orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
        orders_add_history($pdo, $orderId, (int) $user['id'], 'cancelled', $order['status'], 'cancelled', trim((string) ($_POST['reason'] ?? '')));
        flash_set('success', 'سفارش لغو شد. پیش‌فاکتور دوباره قابلِ ویرایش است.');
    } elseif ($action === 'add_payment' && ($user['role'] ?? '') === 'leader' && !$canDecide) {
        flash_set('danger', 'سرپرست نمی‌تواند پرداخت/فیش ثبت کند؛ فیش را کارشناسِ مشتری (A/B/C) ثبت می‌کند.');
    } elseif ($action === 'add_payment' && ($isSeller || $canDecide || $canCollect) && $order['status'] === 'approved') {
        // واریزیِ تکراری؟ (همین پرداخت را کسِ دیگری قبلاً برای همین مشتری ثبت کرده)
        $payFiles = orders_normalize_files($_FILES['receipts'] ?? null);
        $payHashes = [];
        foreach ($payFiles as $pf) { $h = @sha1_file((string) $pf['tmp_name']); if ($h) $payHashes[] = $h; }
        $payDups = pdup_ready($pdo) ? pdup_payment_candidates($pdo, [
            'customer_id' => (int) $order['customer_id'], 'amount' => orders_money($_POST['amount'] ?? ''), 'ref' => (string) ($_POST['ref'] ?? ''),
        ], $payHashes) : [];
        if ($payDups && empty($_POST['dup_confirm'])) {
            $_SESSION['pay_dup_warn'][$orderId] = array_map(static fn($d) => [
                'order_number' => $d['payment']['order_number'], 'oid' => (int) $d['payment']['oid'], 'amount' => (int) $d['payment']['amount'],
                'recorder' => (string) ($d['payment']['recorder_name'] ?? '—'), 'created_at' => (string) $d['payment']['created_at'],
                'status' => (string) $d['payment']['status'], 'level' => $d['level'], 'reasons' => $d['reasons'],
            ], $payDups);
            $_SESSION['pay_dup_form'][$orderId] = [
                'amount' => (string) ($_POST['amount'] ?? ''), 'paid_at' => (string) ($_POST['paid_at'] ?? ''), 'method' => (string) ($_POST['method'] ?? ''),
                'ref' => (string) ($_POST['ref'] ?? ''), 'note' => (string) ($_POST['note'] ?? ''), 'installment_id' => (int) ($_POST['installment_id'] ?? 0),
            ];
            flash_set('danger', 'این پرداخت احتمالاً قبلاً برای همین مشتری ثبت شده (جزئیات در فرمِ پرداخت). اگر مطمئنید پرداختِ جداست، تیکِ تأیید را بزنید و فیش را دوباره انتخاب کنید.');
            redirect('order_view.php?id=' . $orderId . '#payAdd');
        }
        unset($_SESSION['pay_dup_warn'][$orderId], $_SESSION['pay_dup_form'][$orderId]);
        $res = fin_add_payment($pdo, $order, [
            'amount' => orders_money($_POST['amount'] ?? ''),
            'paid_at' => trim((string) ($_POST['paid_at'] ?? '')) !== '' ? to_gregorian((string) $_POST['paid_at']) : date('Y-m-d'),
            'method' => (string) ($_POST['method'] ?? ''),
            'ref' => trim((string) ($_POST['ref'] ?? '')),
            'note' => trim((string) ($_POST['note'] ?? '')) . ($payDups ? ' [ثبت‌کننده تأیید کرد تکراری نیست]' : ''),
            'installment_id' => (int) ($_POST['installment_id'] ?? 0),
        ], $payFiles, $user, $canDecide && !empty($_POST['auto_confirm']));
        if ($res['ok'] && !$canDecide) {
            try {
                foreach ($pdo->query("SELECT id FROM users WHERE is_active = 1 AND service_access_role = 'financial_liaison'")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fid) {
                    orders_notify($pdo, (int) $user['id'], (int) $fid, 'پرداختِ جدید برای تأیید: مشتری ' . $order['customer_name'] . ' — فاکتور ' . $order['order_number'] . ' — ' . number_format(orders_money($_POST['amount'] ?? '')) . ' تومان');
                }
            } catch (Throwable $e) {
            }
        }
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'cheque_clear' && $canDecide && $order['status'] === 'approved') {
        $res = fin_cheque_clear($pdo, $order, (int) ($_POST['inst_id'] ?? 0), $user);
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'cheque_bounce' && $canDecide && $order['status'] === 'approved') {
        $res = fin_cheque_bounce($pdo, $order, (int) ($_POST['inst_id'] ?? 0), $user, (string) ($_POST['reason'] ?? ''));
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'legacy_resubmit' && $isLegacyOrder && $order['status'] === 'rejected' && ($isSeller || $canCollect || $canDecide)) {
        // پرونده‌ی اقساطِ قبلی پیش‌فاکتور ندارد ← «اصلاح و ارسالِ دوباره»ِ عادی کار نمی‌کند؛ همین‌جا دوباره برای مالی فرستاده می‌شود
        $pdo->prepare("UPDATE sales_orders SET status = 'pending', submitted_at = NOW(), decided_at = NULL, updated_at = NOW() WHERE id = ? AND status = 'rejected'")->execute([$orderId]);
        orders_add_history($pdo, $orderId, (int) $user['id'], 'resubmitted', 'rejected', 'pending', 'پرونده‌ی اقساطِ قبلی اصلاح و دوباره برای بررسیِ مالی ارسال شد.');
        if (!empty($order['finance_user_id']) && (int) $order['finance_user_id'] !== (int) $user['id']) {
            orders_notify($pdo, (int) $user['id'], (int) $order['finance_user_id'], 'پرونده‌ی اقساطِ قبلیِ ' . $order['order_number'] . ' (مشتری: ' . $order['customer_name'] . ') اصلاح و دوباره برای بررسی ارسال شد.');
        }
        flash_set('success', 'پرونده دوباره برای واحدِ مالی ارسال شد.');
    } elseif ($action === 'payment_resubmit' && ($isSeller || $canCollect || $canDecide)) {
        // فیشی که مالی رد کرده (مثلاً چون سررسیدِ اقساطِ بعدی مشخص نبود) بعد از اصلاح دوباره «در انتظارِ تأیید» می‌شود
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $pp = $pdo->prepare("SELECT * FROM sales_order_payments WHERE id = ? AND order_id = ? AND status = 'rejected' AND kind <> 'initial'");
        $pp->execute([$pid, $orderId]);
        if ($prow = $pp->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("UPDATE sales_order_payments SET status = 'pending', decided_by = NULL, decided_at = NULL WHERE id = ?")->execute([$pid]);
            orders_add_history($pdo, $orderId, (int) $user['id'], 'payment_pending', null, null,
                'فیشِ ' . number_format((int) $prow['amount']) . ' تومانی اصلاح و دوباره برای تأییدِ مالی ارسال شد' . ($prow['decision_note'] ? ' (دلیلِ ردِ قبلی: ' . $prow['decision_note'] . ')' : '') . '.');
            if (!empty($prow['decided_by']) && (int) $prow['decided_by'] !== (int) $user['id']) {
                orders_notify($pdo, (int) $user['id'], (int) $prow['decided_by'], 'فیشِ ' . number_format((int) $prow['amount']) . ' تومانیِ سفارشِ ' . $order['order_number'] . ' اصلاح و دوباره برای تأیید ارسال شد.');
            }
            flash_set('success', 'فیش دوباره برای تأییدِ مالی ارسال شد.');
        } else {
            flash_set('danger', 'این فیش «ردشده» نیست یا پیدا نشد.');
        }
    } elseif ($action === 'payment_decide' && $canDecide) {
        $__rd = trim((string) ($_POST['receipt_date'] ?? ''));
        $res = fin_decide_payment($pdo, (int) ($_POST['payment_id'] ?? 0), (string) ($_POST['decision'] ?? ''), $user,
            trim((string) ($_POST['decision_note'] ?? '')), trim((string) ($_POST['confirm_amount'] ?? '')) !== '' ? orders_money($_POST['confirm_amount']) : null,
            $__rd !== '' ? to_gregorian(normalize_digits($__rd)) : null);
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'set_installments' && $__canEditInst) {
        $rows = fin_parse_installment_post($_POST);
        foreach ($rows as $r) {
            if ($r['due_date'] === 'bad') { flash_set('danger', 'تاریخِ یکی از سررسیدها معتبر نیست.'); redirect('order_view.php?id=' . $orderId . '#finance'); }
        }
        $res = fin_set_installments($pdo, $order, $rows, (int) $user['id']);
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    } elseif ($action === 'add_receipt' && ($isSeller || $canDecide) && $order['status'] !== 'cancelled') {
        $files = orders_normalize_files($_FILES['receipts'] ?? null);
        $errs = orders_validate_files($files);
        if (!$files) $errs[] = 'فایلی انتخاب نشده است.';
        if ($errs) {
            flash_set('danger', implode(' ', $errs));
        } else {
            $n = orders_store_files($pdo, $orderId, $files, (int) $user['id']);
            orders_add_history($pdo, $orderId, (int) $user['id'], 'receipt_added', null, null, to_persian_digits((string) $n) . ' فایل اضافه شد.');
            flash_set('success', 'فایل اضافه شد.');
        }
    }
    // سهم عملکرد: هر تغییرِ وضعیتِ سفارش/پرداخت → محاسبه‌ی پرداخت‌های تازه‌تأییدشده و ابطالِ محاسبه‌ی پرداخت‌های لغوشده
    try { require_once __DIR__ . '/includes/performance_functions.php'; ps_sync_order($pdo, $orderId, (int) $user['id']); } catch (Throwable $e) { error_log('ps_sync_order: ' . $e->getMessage()); }
    redirect('order_view.php?id=' . $orderId);
}

$items = orders_items($pdo, $orderId);
$payments = fin_payments($pdo, $orderId);
$installments = fin_installments($pdo, $orderId);
$fin = fin_compute($order, $payments, $installments);
$payStatuses = fin_payment_statuses();
$instStatuses = fin_installment_statuses();
$files = orders_files($pdo, $orderId);
$history = orders_history($pdo, $orderId);
$methods = orders_payment_methods();
$statuses = orders_statuses();
$st = $statuses[$order['status']] ?? $statuses['pending'];
$diff = (int) $order['paid_amount'] - (int) $order['total_amount'];
$ccPos = cc_ready($pdo) ? cc_position($pdo, (int) $order['customer_id']) : null;
// واریزیِ تکراری (همان واریزی که کارشناسِ دیگری هم ثبت کرده)
$dupCands = (pdup_ready($pdo) && in_array($order['status'], ['pending', 'approved'], true)) ? pdup_candidates($pdo, $order) : [];

// قرارداد و شرحِ خدماتِ همین سفارش (برای واحد مالی)
$contract = (!$isLegacyOrder && ctr_ready($pdo)) ? ctr_for_quote($pdo, (int) $order['quote_id']) : null;
// قراردادی که به خودِ این سفارش وصل است (حتی اگر با پیش‌فاکتورِ دیگری ساخته شده) ← دکمه‌ی «ساخت قرارداد» نشان داده نمی‌شود
if (!$contract && !$isLegacyOrder && ctr_ready($pdo) && function_exists('ctr_existing_for') && ($__cid = ctr_existing_for($pdo, (int) $order['quote_id'], (int) $order['id']))) {
    $contract = ctr_get($pdo, $__cid);
}
$ctrSt = $contract ? (ctr_statuses()[$contract['status']] ?? ['label' => $contract['status'], 'color' => 'secondary', 'icon' => 'fa-circle']) : null;
$ctrCanCreate = !$contract && ctr_can_approve($user) && $order['status'] !== 'cancelled';
$ctrCanDelete = $contract && ctr_can_delete($user);
$ctrBack = rawurlencode('order_view.php?id=' . $orderId);
$servicesUrl = $contract
    ? 'contract_print.php?id=' . (int) $contract['id'] . '&doc=services&back=' . $ctrBack
    : 'quote_pdf.php?id=' . (int) $order['quote_id'] . '&order=' . $orderId . '&doc=services';

// تیکتِ آراد برندینگ
$abtOk = abt_ready($pdo);
if ($abtOk) { try { require_once __DIR__ . '/includes/edu_provision.php'; edu_bundle_fix_all($pdo); } catch (Throwable $e) {} }
$abtTickets = $abtOk ? abt_tickets_for_order($pdo, $orderId) : [];
$abtTicket = $abtTickets[0] ?? null;
$abtSettings = $abtOk ? abt_settings($pdo) : abt_settings_defaults();
$abtConn = abt_connection_ready($abtSettings);
$abtCan = abt_can_manage($user);

$historyLabels = [
    'submitted' => 'ثبت و ارسال برای مالی', 'resubmitted' => 'اصلاح و ارسالِ دوباره', 'approved' => 'تأیید و ثبتِ سفارش',
    'rejected' => 'رد شد', 'set_pending' => 'در انتظار بررسی', 'cancelled' => 'لغو توسطِ کارشناس', 'note' => 'یادداشتِ مالی',
    'receipt_added' => 'افزودنِ فیش', 'payment_added' => 'ثبتِ پرداخت', 'payment_confirmed' => 'تأییدِ پرداخت',
    'payment_rejected' => 'ردِ پرداخت', 'payment_pending' => 'پرداخت در انتظار', 'installments' => 'برنامه‌ی اقساط',
    'consent_uploaded' => 'اسکرین‌شاتِ پیامِ رضایت', 'consent_approved' => 'تأییدِ پیامِ رضایت', 'consent_rejected' => 'ردِ پیامِ رضایت',
];

$pageTitle = 'سفارش ' . $order['order_number'];
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.ov{--l:#e7e2d3}
.ov .hero{border-radius:18px;padding:18px 22px;color:#fff;margin-bottom:16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.ov .hero.pending{background:linear-gradient(135deg,#1c1917,#78350f 60%,#f59e0b 140%)}
.ov .hero.approved{background:linear-gradient(135deg,#052e1c,#14532d 60%,#22c55e 140%)}
.ov .hero.rejected{background:linear-gradient(135deg,#1c0a0a,#7f1d1d 60%,#ef4444 140%)}
.ov .hero.cancelled{background:linear-gradient(135deg,#1c1917,#44403c 60%,#a8a29e 140%)}
.ov .hero h5{margin:0;font-weight:800}.ov .hero .sub{font-size:.8rem;opacity:.85;margin-top:4px}
.ov .card{border:1px solid var(--l);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.ov .kv{display:flex;justify-content:space-between;border-bottom:1px dashed #eee;padding:6px 0;font-size:.84rem}
.ov .kv span:first-child{color:#78716c}
.ov .rc{display:flex;gap:10px;flex-wrap:wrap}
.ov .rc a{width:120px;height:120px;border:1px solid var(--l);border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#fafaf9;text-decoration:none;color:#44403c;font-size:.72rem;text-align:center}
.ov .rc img{width:100%;height:100%;object-fit:cover}
.ov .tl{border-inline-start:2px solid var(--l);padding-inline-start:14px}
.ov .tl .it{position:relative;padding-bottom:12px}
.ov .tl .it::before{content:'';position:absolute;inset-inline-start:-20px;top:5px;width:10px;height:10px;border-radius:50%;background:#c9a24b}
.ov .dec .btn{font-weight:800}
.ov .fbx{border:1px solid var(--l);border-radius:12px;padding:.55rem .4rem;height:100%}
.ov .fbx small{display:block;color:#78716c;font-size:.74rem}
.ov .fbx b{font-size:.92rem}
.ov .fbx.bad{background:#fef2f2;border-color:#fecaca}.ov .fbx.bad b{color:#b91c1c}
.ov .fbx.good{background:#f0fdf4;border-color:#bbf7d0}.ov .fbx.good b{color:#15803d}
</style>

<div class="ov">
  <div class="d-flex gap-2 flex-wrap mb-3">
    <?php if (user_can('finance_orders_view', $user)): ?><a href="admin/admin_orders.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-right"></i> سفارشات (مالی)</a><?php endif; ?>
    <?php if (user_can('orders_view_own', $user)): ?><a href="my_orders.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-cart-shopping"></i> سفارش‌های من</a><?php endif; ?>
    <?php if (user_can('customer_view', $user)): ?><a href="customer_view.php?id=<?= (int) $order['customer_id'] ?>#customer-orders" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-user"></i> پرونده‌ی مشتری</a><?php endif; ?>
    <?php if (!$isLegacyOrder): ?>
      <a href="quote_pdf.php?id=<?= (int) $order['quote_id'] ?>&amp;order=<?= $orderId ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-print"></i> چاپ فاکتور</a>
    <?php else: ?>
      <a href="customer_legacy_installments.php?id=<?= (int) $order['customer_id'] ?>" class="btn btn-sm btn-warning"><i class="fa-solid fa-hand-holding-dollar"></i> اقساطِ قبلیِ مشتری</a>
    <?php endif; ?>
    <?php if ($contract): ?>
      <a href="contract_print.php?id=<?= (int) $contract['id'] ?>&amp;doc=contract&amp;back=<?= e($ctrBack) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-file-signature"></i> چاپ قرارداد</a>
    <?php endif; ?>
    <?php if (!$isLegacyOrder): ?><a href="<?= e($servicesUrl) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-list-check"></i> شرح خدمات</a><?php endif; ?>
    <?php if ($contract): ?>
      <a href="contract_view.php?id=<?= (int) $contract['id'] ?>#edit-panel" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen-to-square"></i> ویرایش قرارداد</a>
    <?php elseif ($ctrCanCreate): ?>
      <form method="post" action="contract_view.php" class="d-inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="quote_id" value="<?= (int) $order['quote_id'] ?>">
        <button class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus"></i> ساخت قرارداد</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="hero <?= e($order['status']) ?>">
    <div>
      <h5><i class="fa-solid fa-file-invoice-dollar"></i> فاکتور / سفارش <?= e(to_persian_digits($order['order_number'])) ?></h5>
      <div class="sub">مشتری: <b><?= e($order['customer_name']) ?></b> — <span dir="ltr"><?= e($order['customer_mobile']) ?></span> | کارشناس: <?= e($order['seller_name'] ?? '—') ?> | ثبت: <?= to_jalali($order['created_at']) ?></div>
    </div>
    <?php if ($isLegacyOrder && $order['status'] === 'approved'): ?>
      <div class="fs-6"><span class="badge bg-light text-dark px-3 py-2"><i class="fa-solid fa-hand-holding-dollar"></i> پرونده‌ی اقساطِ قبلی</span></div>
    <?php else: ?>
    <div class="fs-6"><span class="badge bg-light text-dark px-3 py-2"><i class="fa-solid <?= e($st['icon']) ?>"></i> <?= e($st['label']) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($order['status'] === 'rejected' && $order['finance_note']): ?>
    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><b>دلیلِ رد:</b> <?= nl2br(e($order['finance_note'])) ?></div>
      <?php if ($isLegacyOrder && ($isSeller || $canCollect || $canDecide)): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('اقساطِ بعدی (پایینِ همین صفحه، بخشِ «وضعیتِ مالی») را تعیین کرده‌اید؟ پرونده دوباره برای مالی ارسال شود؟')">
          <?= csrf_field() ?><input type="hidden" name="action" value="legacy_resubmit">
          <button class="btn btn-sm btn-danger"><i class="fa-solid fa-paper-plane"></i> ارسالِ دوباره برای مالی</button>
        </form>
      <?php elseif ($isSeller || can_manage_service_requests($user)): ?><a class="btn btn-sm btn-danger" href="order_submit.php?order_id=<?= $orderId ?>"><i class="fa-solid fa-pen"></i> اصلاح و ارسالِ دوباره</a><?php endif; ?>
    </div>
  <?php elseif ($order['status'] === 'approved' && $isLegacyOrder): ?>
    <?php // پرونده‌ی اقساطِ قبلی تأییدِ مالی ندارد؛ کارشناس فقط آن را می‌سازد (ستونِ finance_user_id در این پرونده = سازنده) ?>
    <div class="alert alert-info"><i class="fa-solid fa-hand-holding-dollar"></i> پرونده‌ی اقساطِ قبلی توسطِ <?= e($order['finance_name'] ?? $order['seller_name'] ?? 'کارشناس') ?> در <?= to_jalali($order['created_at']) ?> ساخته شد.
      این پرونده سفارشِ جدید نیست و تأییدِ مالی ندارد؛ <b>هر فیشِ قسط جداگانه توسطِ واحدِ مالی تأیید می‌شود</b>.</div>
  <?php elseif ($order['status'] === 'approved'): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> سفارش توسطِ <?= e($order['finance_name'] ?? 'واحد مالی') ?> در <?= to_jalali($order['decided_at']) ?> تأیید و ثبت شد<?= $order['finance_note'] ? ' — ' . e($order['finance_note']) : '' ?>.</div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-list-ul text-success"></i> خدماتِ خریداری‌شده</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-2">
            <thead class="table-light"><tr><th>#</th><th>خدمت</th><th>مقدار</th><th>قیمت واحد</th><th class="text-end">مبلغ</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i => $it): ?>
              <tr><td><?= to_persian_digits((string) ($i + 1)) ?></td><td><?= e($it['title']) ?></td><td><?= to_persian_digits((string) (float) $it['quantity']) ?> <?= e((string) $it['unit']) ?></td><td><?= format_toman((int) $it['unit_price']) ?></td><td class="text-end"><?= format_toman((int) $it['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="row">
          <div class="col-md-6 offset-md-6">
            <div class="kv"><span>جمع خدمات</span><span><?= format_toman((int) $order['subtotal']) ?></span></div>
            <div class="kv"><span>تخفیف (<?= to_persian_digits((string) (float) $order['discount_percent']) ?>٪)</span><span class="text-danger">- <?= format_toman((int) $order['discount_amount']) ?></span></div>
            <div class="kv"><span>خدمات رایگان</span><span class="text-danger">- <?= format_toman((int) $order['free_amount']) ?></span></div>
            <div class="kv"><span>مالیات (<?= to_persian_digits((string) (float) $order['tax_percent']) ?>٪)</span><span>+ <?= format_toman((int) $order['tax_amount']) ?></span></div>
            <div class="kv fw-bold"><span class="text-dark">مبلغ فاکتور</span><span class="text-success"><?= format_toman((int) $order['total_amount']) ?></span></div>
          </div>
        </div>
      </div>

      <div class="card p-3 mb-3" id="finance">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-scale-balanced text-danger"></i> وضعیتِ مالی، پرداخت‌ها و اقساط</h6>
          <?php if ($order['status'] === 'approved'): ?>
            <?= $fin['balance'] > 0 ? '<span class="badge text-bg-danger">بدهکار: ' . format_toman($fin['balance']) . '</span>' : '<span class="badge text-bg-success"><i class="fa-solid fa-check"></i> تسویه شده</span>' ?>
          <?php else: ?><span class="badge text-bg-secondary">بعد از تأییدِ مالی محاسبه می‌شود</span><?php endif; ?>
        </div>
        <div class="row g-2 text-center mb-3">
          <div class="col-6 col-md-3"><div class="fbx"><small>مبلغ فاکتور</small><b><?= format_toman($fin['total']) ?></b></div></div>
          <div class="col-6 col-md-3"><div class="fbx"><small>پرداختِ تأییدشده</small><b class="text-success"><?= format_toman($fin['paid']) ?></b></div></div>
          <div class="col-6 col-md-3"><div class="fbx <?= $fin['balance'] > 0 ? 'bad' : 'good' ?>"><small>مانده‌ی بدهی</small><b><?= format_toman($fin['balance']) ?></b></div></div>
          <div class="col-6 col-md-3"><div class="fbx"><small>معوق</small><b class="<?= ($fin['overdue'] + $fin['unscheduled']) > 0 ? 'text-danger' : '' ?>"><?= format_toman($fin['overdue'] + ($order['status'] === 'approved' ? $fin['unscheduled'] : 0)) ?></b></div></div>
        </div>
        <?php if ($fin['pending_paid'] > 0): ?><div class="alert alert-info py-2 small"><i class="fa-solid fa-hourglass-half"></i> <?= format_toman($fin['pending_paid']) ?> پرداخت در انتظارِ تأییدِ واحد مالی.</div><?php endif; ?>
        <?php if ($order['status'] === 'approved' && (int) $fin['credit'] > 0): ?>
          <div class="alert alert-primary py-2 small"><i class="fa-solid fa-piggy-bank"></i> مشتری برای این فاکتور <?= format_toman((int) $fin['credit']) ?> بیشتر از مبلغِ فاکتور پرداخت کرده؛ این مبلغ به <b>بستانکاریِ مشتری</b> اضافه شد و در فاکتورهای بعدی قابلِ استفاده است.</div>
        <?php endif; ?>
        <?php if ($ccPos && $order['status'] === 'approved' && $fin['balance'] > 0 && $ccPos['credit'] > 0): ?>
          <div class="border rounded-3 p-2 mb-3" style="background:#eff6ff;border-color:#bfdbfe !important">
            <div class="small mb-2"><i class="fa-solid fa-hand-holding-dollar text-primary"></i> این مشتری (در کلِ پروفایل ۳۶۰) <b><?= format_toman((int) $ccPos['credit']) ?></b> بستانکاری دارد.</div>
            <?php if (cc_can_manage($user)): ?>
              <form method="post" action="customer_credit.php" class="d-flex gap-2 flex-wrap" onsubmit="return confirm('بستانکاری روی این فاکتور اعمال شود؟')">
                <?= csrf_field() ?><input type="hidden" name="action" value="apply">
                <input type="hidden" name="customer_id" value="<?= (int) $order['customer_id'] ?>"><input type="hidden" name="order_id" value="<?= $orderId ?>">
                <input type="hidden" name="return" value="order_view.php?id=<?= $orderId ?>#finance">
                <input name="amount" class="form-control form-control-sm" style="max-width:170px" dir="ltr" inputmode="numeric" value="<?= e(number_format(min((int) $fin['balance'], (int) $ccPos['credit']))) ?>">
                <button class="btn btn-sm btn-primary"><i class="fa-solid fa-check"></i> استفاده از بستانکاری</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($order['status'] === 'approved' && $fin['unscheduled'] > 0): ?><div class="alert alert-warning py-2 small"><i class="fa-solid fa-triangle-exclamation"></i> <?= format_toman($fin['unscheduled']) ?> از بدهی سررسیدِ مشخص ندارد؛ برایش قسط تعیین کنید.</div><?php endif; ?>

        <?php $__isChq = $installments && (($installments[0]['kind'] ?? '') === 'cheque'); ?>
        <h6 class="fw-bold small mt-2"><i class="fa-solid <?= $__isChq ? 'fa-money-check text-primary' : 'fa-calendar-days text-warning' ?>"></i> <?= $__isChq ? 'چک‌ها' : 'اقساط' ?>
          <span class="badge text-bg-light border ms-1">شیوه‌ی تسویه: <?= e(orders_settle_label($order, $installments)) ?></span></h6>
        <?php if (!$installments): ?><div class="small text-muted mb-2">برنامه‌ی اقساط/چکی ثبت نشده است.</div><?php else: ?>
        <div class="table-responsive mb-2">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>مانده</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($fin['installments'] as $i): ?>
              <tr>
                <td><?= to_persian_digits((string) $i['seq']) ?></td>
                <td><?= to_jalali($i['due_date']) ?>
                  <?php if (($i['kind'] ?? '') === 'cheque'): ?><div class="small text-primary"><i class="fa-solid fa-money-check"></i> چک <span dir="ltr"><?= e((string) ($i['cheque_no'] ?? '')) ?></span><?= !empty($i['bank']) ? ' — ' . e((string) $i['bank']) : '' ?></div>
                  <?php elseif (!empty($i['note'])): ?><div class="small text-muted"><?= e($i['note']) ?></div><?php endif; ?></td>
                <td><?= format_toman((int) $i['amount']) ?></td>
                <td class="text-success"><?= format_toman((int) $i['paid']) ?></td>
                <td class="<?= $i['remaining'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= format_toman((int) $i['remaining']) ?></td>
                <td><?= $order['status'] === 'approved' ? fin_badge($instStatuses, $i['state']) : '<span class="text-muted small">—</span>' ?>
                  <?php if (($i['cheque_status'] ?? '') === 'bounced' && !empty($i['cheque_status_note'])): ?><div class="small text-danger"><?= e((string) $i['cheque_status_note']) ?></div><?php endif; ?>
                  <?php if (($i['cheque_status'] ?? '') === 'cleared'): ?><div class="small text-success"><i class="fa-solid fa-check"></i> وصول‌شده</div><?php endif; ?>
                  <?php if ($canDecide && $order['status'] === 'approved' && ($i['kind'] ?? '') === 'cheque' && (int) $i['remaining'] > 0): ?>
                    <div class="d-flex gap-1 mt-1">
                      <form method="post" onsubmit="return confirm('<?= e(fin_installment_label($i)) ?> وصول شد؟ مبلغِ <?= e(number_format((int) $i['remaining'])) ?> تومان از بدهی کسر می‌شود.');">
                        <?= csrf_field() ?><input type="hidden" name="action" value="cheque_clear"><input type="hidden" name="inst_id" value="<?= (int) $i['id'] ?>">
                        <button class="btn btn-sm btn-success py-0 px-2"><i class="fa-solid fa-check"></i> وصول شد</button>
                      </form>
                      <?php if (($i['cheque_status'] ?? '') !== 'bounced'): ?>
                      <form method="post" onsubmit="var r=prompt('دلیلِ برگشت (مثلاً کسریِ موجودی):'); if(r===null) return false; this.reason.value=r; return true;">
                        <?= csrf_field() ?><input type="hidden" name="action" value="cheque_bounce"><input type="hidden" name="inst_id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="reason" value="">
                        <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="fa-solid fa-rotate-left"></i> برگشت خورد</button>
                      </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php if ($__canEditInst): ?>
          <button class="btn btn-sm btn-outline-secondary mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#instEdit"><i class="fa-solid fa-pen"></i> <?= $installments ? 'ویرایشِ اقساط' : 'تعیینِ اقساط' ?></button>
          <form method="post" class="collapse inst-edit mb-3" id="instEdit">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_installments">
            <div class="small text-muted mb-2">مانده‌ی فعلی: <b><?= format_toman(max(0, $fin['total'] - ($fin['paid'] ?: (int) $order['paid_amount']))) ?></b> — جمعِ اقساط نباید از مبلغِ فاکتور بیشتر شود.</div>
            <div id="ieRows">
              <?php foreach (array_merge($installments, [[], []]) as $i): ?>
                <div class="d-flex gap-1 mb-1">
                  <input name="inst_amount[]" class="form-control form-control-sm" dir="ltr" placeholder="مبلغ" value="<?= isset($i['amount']) ? e(number_format((int) $i['amount'])) : '' ?>">
                  <input name="inst_due[]" class="form-control form-control-sm jalali-date" autocomplete="off" placeholder="سررسید" value="<?= isset($i['due_date']) ? e(to_jalali($i['due_date'])) : '' ?>">
                  <input name="inst_note[]" class="form-control form-control-sm" placeholder="توضیح" value="<?= e((string) ($i['note'] ?? '')) ?>">
                </div>
              <?php endforeach; ?>
            </div>
            <div class="small text-muted mb-2">برای حذفِ یک قسط، مبلغ و تاریخش را خالی کنید.</div>
            <button class="btn btn-sm btn-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره‌ی اقساط</button>
          </form>
        <?php endif; ?>

        <h6 class="fw-bold small mt-2"><i class="fa-solid fa-money-bill-transfer text-success"></i> پرداخت‌ها</h6>
        <div class="table-responsive mb-2">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>تاریخ</th><th>مبلغ</th><th>نوع / روش</th><th>ثبت</th><th>فیش</th><th>وضعیت</th><?php if ($canDecide): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted small">پرداختی ثبت نشده.</td></tr><?php endif; ?>
            <?php
              $__instById = [];
              foreach ($fin['installments'] as $__i) $__instById[(int) $__i['id']] = $__i;
            ?>
            <?php foreach ($payments as $p):
              $__for = !empty($p['installment_id']) && isset($__instById[(int) $p['installment_id']]) ? fin_installment_label($__instById[(int) $p['installment_id']]) : '';
              // برای واحد مالی: پرداختِ در انتظار که احتمالاً تکراری است
              $__pd = ($canDecide && $p['status'] === 'pending' && !in_array($p['kind'], ['credit', 'transfer', 'initial'], true) && pdup_ready($pdo))
                  ? pdup_payment_candidates($pdo, ['customer_id' => (int) $order['customer_id'], 'amount' => (int) $p['amount'], 'ref' => (string) ($p['ref'] ?? ''),
                      'payment_id' => (int) $p['id'], 'created_at' => (string) $p['created_at']], pdup_payment_hashes($pdo, (int) $p['id']))
                  : [];
            ?>
              <tr>
                <td class="text-nowrap"><?= $p['paid_at'] ? to_jalali($p['paid_at']) : to_jalali($p['created_at']) ?></td>
                <td class="text-nowrap fw-semibold"><?= format_toman((int) $p['amount']) ?>
                  <?php if ($__for !== ''): ?><div class="small text-primary fw-normal">برای: <?= e($__for) ?></div><?php endif; ?>
                  <?php if ($__pd): $__pc = $__pd[0]['level'] === 'certain'; ?>
                    <div><span class="badge <?= $__pc ? 'text-bg-danger' : 'text-bg-warning' ?>" title="<?= e(implode(' | ', array_map(static fn($d) => 'فاکتور ' . $d['payment']['order_number'] . ' — ' . number_format((int) $d['payment']['amount']) . ' — ثبت: ' . ($d['payment']['recorder_name'] ?? '—') . ' — ' . implode('، ', $d['reasons']), $__pd))) ?>"><i class="fa-solid fa-clone"></i> <?= $__pc ? 'واریزیِ تکراری' : 'احتمالِ تکراری' ?></span></div>
                  <?php endif; ?></td>
                <td class="small"><?= e(fin_payment_kind_label((string) $p['kind'])) ?><div class="text-muted"><?= e(in_array($p['kind'], ['transfer', 'credit'], true) ? (string) ($p['note'] ?? '') : ($methods[$p['method']] ?? (string) $p['method'])) ?><?= $p['ref'] ? ' — <span dir="ltr">' . e($p['ref']) . '</span>' : '' ?></div></td>
                <td class="small"><?= e($p['recorder_name'] ?? '—') ?><?php if ($p['note']): ?><div class="text-muted"><?= e($p['note']) ?></div><?php endif; ?></td>
                <td><?= (int) $p['files_cnt'] > 0 ? '<span class="badge text-bg-light border"><i class="fa-solid fa-receipt"></i> ' . to_persian_digits((string) $p['files_cnt']) . '</span>' : '—' ?></td>
                <td><?= fin_badge($payStatuses, (string) $p['status']) ?><?php if ($p['decision_note']): ?><div class="small text-muted"><?= e($p['decision_note']) ?></div><?php endif; ?>
                  <?php if ($p['status'] === 'rejected' && $p['kind'] !== 'initial' && ($isSeller || $canCollect)): ?>
                    <form method="post" class="mt-1" onsubmit="return confirm('ایرادی که مالی گفته (مثلاً تعیینِ سررسیدِ اقساطِ بعدی) را اصلاح کرده‌اید؟ این فیش دوباره برای تأیید ارسال شود؟')">
                      <?= csrf_field() ?><input type="hidden" name="action" value="payment_resubmit"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0"><i class="fa-solid fa-paper-plane"></i> ارسالِ دوباره برای مالی</button>
                    </form>
                  <?php endif; ?></td>
                <?php if ($canDecide): ?>
                <td class="text-nowrap">
                  <?php if ($p['kind'] === 'credit'): ?><span class="small text-muted">از دفترِ بستانکاری</span>
                  <?php elseif ($p['kind'] !== 'initial' && $p['status'] !== 'confirmed'): ?>
                    <form method="post" class="d-inline-flex gap-1 align-items-center">
                      <?= csrf_field() ?><input type="hidden" name="action" value="payment_decide"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                      <input name="receipt_date" class="form-control form-control-sm jalali-date" style="width:108px" autocomplete="off" title="تاریخِ واریز طبقِ فیش (مبنای سهم عملکرد)" value="<?= e($p['paid_at'] ? to_jalali((string) $p['paid_at']) : '') ?>">
                      <button name="decision" value="confirmed" class="btn btn-sm btn-success" title="تأیید" onclick="return confirm('تاریخِ واریز را با فیش چک کردید؟ این پرداخت تأیید و از بدهی کسر شود؟')"><i class="fa-solid fa-check"></i></button>
                    </form>
                    <?php if ($p['status'] === 'pending'): ?>
                    <form method="post" class="d-inline-flex gap-1" onsubmit="var n=prompt('دلیلِ رد:'); if(!n) return false; this.decision_note.value=n; return true;">
                      <?= csrf_field() ?><input type="hidden" name="action" value="payment_decide"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="decision_note" value="">
                      <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger" title="رد"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                    <?php endif; ?>
                  <?php elseif ($p['kind'] === 'initial'): ?><span class="small text-muted">با تأییدِ سفارش</span><?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($order['status'] === 'approved' && ($isSeller || $canDecide || $canCollect) && !(($user['role'] ?? '') === 'leader' && !$canDecide)): ?>
          <button class="btn btn-sm btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#payAdd"><i class="fa-solid fa-plus"></i> ثبتِ پرداختِ جدید (قسط / مانده)</button>
          <form method="post" enctype="multipart/form-data" class="collapse border rounded-3 p-3 mt-2" id="payAdd" style="background:#f7fdf9">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">
            <?php
              $__pw = $_SESSION['pay_dup_warn'][$orderId] ?? [];
              $__pf = $_SESSION['pay_dup_form'][$orderId] ?? [];
              unset($_SESSION['pay_dup_warn'][$orderId], $_SESSION['pay_dup_form'][$orderId]); // یک‌بار نمایش؛ ارسالِ بعدی دوباره بررسی می‌شود
              $__openInst = array_values(array_filter($fin['installments'], static fn($x) => (int) $x['remaining'] > 0));
              $__defInst = (int) ($__pf['installment_id'] ?? ($__openInst[0]['id'] ?? 0));
              $__defAmt = $__pf['amount'] ?? ($__openInst ? number_format((int) $__openInst[0]['remaining']) : ($fin['next_due'] ? number_format((int) $fin['next_due']['amount']) : ''));
            ?>
            <?php if ($__pw): ?>
              <div class="alert alert-danger py-2 small">
                <div class="fw-bold mb-1"><i class="fa-solid fa-clone"></i> این پرداخت قبلاً ثبت شده؟</div>
                <?php foreach ($__pw as $w): ?>
                  <div class="border-top pt-1 mt-1">
                    <a href="order_view.php?id=<?= (int) $w['oid'] ?>" target="_blank">فاکتور <?= e(to_persian_digits((string) $w['order_number'])) ?></a>
                    — <?= format_toman((int) $w['amount']) ?> — ثبت: <?= e($w['recorder']) ?> — <?= to_jalali($w['created_at']) ?>
                    <span class="badge <?= $w['level'] === 'certain' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $w['level'] === 'certain' ? 'قطعی' : 'محتمل' ?></span>
                    <div class="text-muted"><?= e(implode('؛ ', $w['reasons'])) ?></div>
                  </div>
                <?php endforeach; ?>
                <label class="form-check mt-2 mb-0"><input type="checkbox" name="dup_confirm" value="1" class="form-check-input"> <b>مطمئنم این یک پرداختِ جداست و تکراری نیست.</b></label>
              </div>
            <?php endif; ?>
            <div class="row g-2">
              <?php if ($__openInst): ?>
              <div class="col-12"><label class="form-label small mb-1">این پرداخت برای کدام قسط / چک است؟</label>
                <select name="installment_id" id="payInst" class="form-select form-select-sm">
                  <?php foreach ($__openInst as $__oi): ?>
                    <option value="<?= (int) $__oi['id'] ?>" data-rem="<?= (int) $__oi['remaining'] ?>" <?= $__defInst === (int) $__oi['id'] ? 'selected' : '' ?>>
                      <?= e(fin_installment_label($__oi)) ?> — سررسید <?= e(to_jalali($__oi['due_date'])) ?> — مانده <?= e(number_format((int) $__oi['remaining'])) ?> تومان<?= ($__oi['state'] ?? '') === 'bounced' ? ' (برگشت‌خورده)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                  <option value="0" data-rem="" <?= $__defInst === 0 && isset($__pf['installment_id']) ? 'selected' : '' ?>>نامشخص / کلِ مانده (به ترتیبِ سررسید کسر شود)</option>
                </select></div>
              <?php endif; ?>
              <div class="col-md-4"><label class="form-label small mb-1">مبلغ (تومان) *</label><input name="amount" id="payAmt" class="form-control form-control-sm" dir="ltr" required value="<?= e((string) $__defAmt) ?>"></div>
              <div class="col-md-4"><label class="form-label small mb-1">تاریخ پرداخت</label><input name="paid_at" class="form-control form-control-sm jalali-date" autocomplete="off" value="<?= e(today_jalali()) ?>"></div>
              <div class="col-md-4"><label class="form-label small mb-1">روش</label><select name="method" class="form-select form-select-sm"><?php foreach (orders_payment_methods(true) as $mk => $ml): ?><option value="<?= e($mk) ?>"><?= e($ml) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-6"><label class="form-label small mb-1">شماره پیگیری</label><input name="ref" class="form-control form-control-sm" dir="ltr" value="<?= e((string) ($__pf['ref'] ?? '')) ?>"></div>
              <div class="col-md-6"><label class="form-label small mb-1">توضیح</label><input name="note" class="form-control form-control-sm" placeholder="مثلاً: واریزِ مشتری" value="<?= e((string) ($__pf['note'] ?? '')) ?>"></div>
              <div class="col-12"><label class="form-label small mb-1">فیش / رسید <?= $canDecide ? '' : '*' ?></label><input type="file" name="receipts[]" multiple class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
              <?php if ($canDecide): ?><div class="col-12"><label class="form-check small"><input type="checkbox" name="auto_confirm" value="1" class="form-check-input" checked> همین حالا توسطِ مالی تأیید شود</label></div><?php endif; ?>
            </div>
            <button class="btn btn-sm btn-success mt-2"><i class="fa-solid fa-paper-plane"></i> ثبتِ پرداخت</button>
          </form>
          <?php if ($__pw): ?><script>document.getElementById('payAdd').classList.add('show');</script><?php endif; ?>
          <script>
          (function () {
            var sel = document.getElementById('payInst'), amt = document.getElementById('payAmt');
            if (!sel || !amt) return;
            sel.addEventListener('change', function () {
              var r = sel.options[sel.selectedIndex].getAttribute('data-rem');
              if (r) amt.value = Number(r).toLocaleString('en-US');
            });
          })();
          </script>
        <?php endif; ?>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-receipt text-success"></i> فیش‌ها و رسیدهای پرداخت</h6>
        <?php if (!$files): ?><div class="text-muted small">فایلی بارگذاری نشده است.</div><?php endif; ?>
        <div class="rc">
          <?php foreach ($files as $f): $isImg = strpos((string) $f['mime'], 'image/') === 0; ?>
            <a href="order_file.php?id=<?= (int) $f['id'] ?>" target="_blank" title="<?= e((string) $f['original_name']) ?>">
              <?php if ($isImg): ?><img src="order_file.php?id=<?= (int) $f['id'] ?>" alt="فیش" loading="lazy"><?php else: ?><span><i class="fa-solid fa-file-pdf fs-2 text-danger"></i><br><?= e(mb_strimwidth((string) $f['original_name'], 0, 22, '…')) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if (($isSeller || $canDecide) && $order['status'] !== 'cancelled'): ?>
          <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mt-3 align-items-center flex-wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_receipt">
            <input type="file" name="receipts[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="form-control form-control-sm" style="max-width:320px">
            <button class="btn btn-sm btn-outline-success"><i class="fa-solid fa-plus"></i> افزودن فیش</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($order['status'] !== 'cancelled') { try { consent_render_card($pdo, $order, $user); } catch (Throwable $e) { error_log('consent card: ' . $e->getMessage()); } } ?>
    </div>

    <div class="col-lg-5">
      <?php $kyc = kyc_get($pdo, (int) $order['customer_id']); ?>
      <div class="card p-3 mb-3" style="border-color:<?= $kyc['complete'] ? '#bbf7d0' : '#fde68a' ?>">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-id-card text-info"></i> مدارکِ مشتری</h6>
          <?= $kyc['complete'] ? '<span class="badge text-bg-success"><i class="fa-solid fa-check"></i> کامل</span>' : '<span class="badge text-bg-warning">ناقص: ' . e(implode('، ', kyc_missing_labels($kyc))) . '</span>' ?>
        </div>
        <div class="d-flex gap-3 align-items-start">
          <?php if ($kyc['has_card']): ?>
            <a href="customer_doc.php?customer_id=<?= (int) $order['customer_id'] ?>" target="_blank" class="border rounded-3 overflow-hidden d-block flex-shrink-0 bg-light text-center" style="width:120px;height:78px">
              <?php if (strpos((string) $kyc['card_mime'], 'image/') === 0): ?><img src="customer_doc.php?customer_id=<?= (int) $order['customer_id'] ?>" alt="کارت ملی" style="width:100%;height:100%;object-fit:cover"><?php else: ?><i class="fa-solid fa-file-pdf fs-3 text-danger mt-3"></i><?php endif; ?>
            </a>
          <?php endif; ?>
          <div class="small flex-grow-1">
            <div><?= e(function_exists('kyc_id_label') ? kyc_id_label($kyc) : 'کد ملی') ?>: <b dir="ltr"><?= e((string) ($kyc['national_id'] ?? '—')) ?></b><?= !empty($kyc['is_foreign']) ? ' <span class="badge text-bg-info">اتباع</span>' : '' ?></div>
            <div>کد پستی: <b dir="ltr"><?= e((string) ($kyc['postal_code'] ?? '—')) ?></b></div>
            <div class="text-muted"><?= e((string) ($kyc['address'] ?? '')) ?></div>
            <?php if (!empty($kyc['card_verified_at'])): ?><div class="text-success mt-1"><i class="fa-solid fa-shield-halved"></i> کارت ملی توسطِ مالی تأیید شده</div>
            <?php elseif ($kyc['has_card'] && $canDecide): ?>
              <form method="post" action="customer_doc.php" class="mt-1">
                <?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= (int) $order['customer_id'] ?>"><input type="hidden" name="verify_card" value="1">
                <input type="hidden" name="return" value="order_view.php?id=<?= $orderId ?>">
                <button class="btn btn-sm btn-outline-success py-0"><i class="fa-solid fa-check"></i> تأییدِ کارت ملی</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php
        // سهم عملکردِ این سفارش (فقط برای دارنده‌ی «مشاهده‌ی سهمِ همه»)
        require_once __DIR__ . '/includes/performance_functions.php';
        if (perf_ready($pdo) && perf_can('view_all', $user)) { $oid = $orderId; $pbCompact = true; require __DIR__ . '/includes/perf_order_breakdown.php'; }
      ?>
      <?php if ($isLegacyOrder): ?>
        <div class="alert alert-warning small"><i class="fa-solid fa-hand-holding-dollar"></i>
          این یک <b>پرونده‌ی اقساطِ قبل از سامانه</b> است: خدمتِ جدیدی به مشتری داده نشده و فقط قسط‌های بدهیِ قدیمی روی آن ثبت می‌شود؛ پس پیش‌فاکتور، فاکتورِ چاپی و قرارداد ندارد.</div>
      <?php endif; ?>
      <!-- قرارداد -->
      <div class="card p-3 mb-3 <?= $isLegacyOrder ? 'd-none' : '' ?>" id="contract" style="border-color:#e0d4f5">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-file-signature" style="color:#6d28d9"></i> قرارداد و پیوست‌ها</h6>
          <?php if ($contract): ?><span class="badge text-bg-<?= e($ctrSt['color']) ?>"><i class="fa-solid <?= e($ctrSt['icon']) ?>"></i> <?= e($ctrSt['label']) ?></span><?php endif; ?>
        </div>
        <?php if ($contract): ?>
          <div class="kv"><span>فاکتورِ مبنای قرارداد</span><span><bdi dir="ltr"><?= e(to_persian_digits((string) $order['order_number'])) ?></bdi></span></div>
          <div class="kv"><span>شماره قرارداد</span><span><bdi dir="ltr"><?= e(to_persian_digits((string) $contract['contract_number'])) ?></bdi></span></div>
          <div class="kv"><span>تاریخ قرارداد</span><span><?= to_jalali((string) $contract['contract_date']) ?></span></div>
          <div class="kv"><span>ساخته‌شده توسط</span><span><?= e((string) ($contract['creator_name'] ?? '—')) ?></span></div>
          <?php if ($contract['status'] === 'issued'): ?>
            <div class="kv"><span>صادرشده</span><span><?= e((string) ($contract['issuer_name'] ?? '')) ?> — <?= to_jalali(substr((string) $contract['issued_at'], 0, 10)) ?></span></div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <a href="contract_print.php?id=<?= (int) $contract['id'] ?>&amp;doc=contract&amp;back=<?= e($ctrBack) ?>" target="_blank" class="btn btn-sm btn-dark"><i class="fa-solid fa-print"></i> قرارداد</a>
            <a href="contract_print.php?id=<?= (int) $contract['id'] ?>&amp;doc=quote&amp;back=<?= e($ctrBack) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-file-invoice"></i> فاکتور</a>
            <a href="<?= e($servicesUrl) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-list-check"></i> شرح خدمات</a>
          </div>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <a href="contract_view.php?id=<?= (int) $contract['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i> مشاهده و مدیریت</a>
            <?php if (in_array($contract['status'], ['draft', 'approved'], true) && ctr_can_approve($user)): ?>
              <a href="contract_view.php?id=<?= (int) $contract['id'] ?>#edit-panel" class="btn btn-sm btn-primary"><i class="fa-solid fa-pen"></i> ویرایش اطلاعات</a>
              <a href="contract_view.php?id=<?= (int) $contract['id'] ?>&amp;edit_body=1#body-editor" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-file-pen"></i> اصلاح متن</a>
            <?php elseif ($contract['status'] === 'issued' && ctr_can_approve($user)): ?>
              <form method="post" action="contract_view.php" class="d-inline" onsubmit="return confirm('قرارداد برای اصلاح باز شود؟ بعد از اصلاح باید دوباره «صدور» زده شود.');">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $contract['id'] ?>"><input type="hidden" name="action" value="reopen">
                <button class="btn btn-sm btn-warning"><i class="fa-solid fa-lock-open"></i> بازگشایی برای ویرایش</button>
              </form>
            <?php endif; ?>
            <?php if ($ctrCanDelete): ?>
              <form method="post" action="contract_view.php" class="d-inline" onsubmit="return confirm('قرارداد <?= e((string) $contract['contract_number']) ?> برای همیشه حذف شود؟');">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $contract['id'] ?>"><input type="hidden" name="action" value="delete">
                <input type="hidden" name="return" value="order_view.php?id=<?= $orderId ?>#contract">
                <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash-can"></i> حذف</button>
              </form>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="text-muted small mb-2">برای این سفارش هنوز قرارداد ساخته نشده. شرحِ خدمات از روی همین فاکتور قابلِ چاپ است.</div>
          <div class="d-flex flex-wrap gap-2">
            <a href="<?= e($servicesUrl) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-list-check"></i> شرح خدمات</a>
            <?php if ($ctrCanCreate): ?>
              <form method="post" action="contract_view.php" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="quote_id" value="<?= (int) $order['quote_id'] ?>">
                <button class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> ساخت قرارداد</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- تیکت‌های آراد برندینگ (یکی برای هر خدمت) -->
      <?php if ($abtOk && ($abtCan || $abtTickets)):
        $abtUnsent = array_values(array_filter($abtTickets, static fn($t) => !in_array($t['status'], ['sent', 'manual', 'bundled'], true))); ?>
      <div class="card p-3 mb-3" id="abt" style="border-color:#bfdbfe">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-ticket text-primary"></i> تیکت‌های آراد برندینگ</h6>
          <?php if ($abtTickets): ?><span class="small text-muted"><?= to_persian_digits((string) (count($abtTickets) - count($abtUnsent))) ?> از <?= to_persian_digits((string) count($abtTickets)) ?> ارسال شده</span><?php endif; ?>
        </div>
        <?php $abtAcc = abt_account_get($pdo, (int) $order['customer_id']);
          if ($abtAcc && $abtAcc['status'] === 'created' && empty($abtAcc['sms_done_at'])): ?>
          <div class="alert alert-warning py-2 small mb-2" id="abt-account">
            <div class="fw-bold mb-1"><i class="fa-solid fa-user-plus"></i> این مشتری قبلاً در آراد برندینگ حساب نداشت و برایش حسابِ جدید ساخته شد.</div>
            <?php if ($abtCan || !empty($isSeller)): ?>
              <div>نام کاربری: <b dir="ltr"><?= e((string) $abtAcc['username']) ?></b> — رمز عبور: <b dir="ltr"><?= e((string) $abtAcc['password']) ?></b> — آدرس ورود: <span dir="ltr"><?= e((string) ($abtSettings['acc_login_url'] ?? '')) ?></span></div>
            <?php endif; ?>
            <div class="mt-1">لطفاً <b>نام کاربری و رمز عبور را برای مشتری (<span dir="ltr"><?= e((string) $abtAcc['mobile']) ?></span>) پیامک کنید</b> و بعد «پیامک شد» را بزنید.
              <?= !empty($abtAcc['welcome_ticket_id']) ? 'تیکتِ «اطلاعاتِ حساب» با فهرستِ همه‌ی خدمات هم برای مشتری ثبت شد.' : '<span class="text-danger">تیکتِ «اطلاعاتِ حساب» ارسال نشد' . (!empty($abtAcc['welcome_error']) ? ': ' . e((string) $abtAcc['welcome_error']) : '') . '</span>' ?></div>
            <?php if ($abtCan): ?>
              <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="abt_acc_sms_done">
                <button class="btn btn-sm btn-warning"><i class="fa-solid fa-comment-sms"></i> پیامک شد</button></form>
            <?php endif; ?>
          </div>
        <?php elseif ($abtAcc && $abtAcc['status'] === 'created'): ?>
          <div class="small text-muted mb-2"><i class="fa-solid fa-user-check"></i> حسابِ آراد برندینگِ این مشتری توسطِ آراد کانتکت ساخته شد (<span dir="ltr"><?= e((string) $abtAcc['mobile']) ?></span>)؛ اطلاعاتِ ورود <?= to_jalali(substr((string) $abtAcc['sms_done_at'], 0, 10)) ?> پیامک شد.</div>
        <?php elseif ($abtAcc && $abtAcc['status'] === 'found'): ?>
          <div class="small text-muted mb-2"><i class="fa-solid fa-mobile-screen"></i> تیکت‌ها با شماره‌ی <b dir="ltr"><?= e((string) $abtAcc['mobile']) ?></b> (از پروفایلِ ۳۶۰) ثبت می‌شوند — شماره‌ای که در آراد برندینگ حساب دارد.</div>
        <?php endif; ?>
        <?php if ($order['status'] !== 'approved' && !$abtTickets): ?>
          <div class="small text-muted">بعد از «تأیید و ثبت سفارش»، برای <b>هر خدمت</b> یک تیکتِ جدا (با موضوع، متن و واحدِ همان خدمت) برای مشتری در aradbranding.me ساخته<?= $abtConn && $abtSettings['auto_send'] === '1' ? ' و خودکار ارسال' : '' ?> می‌شود.</div>
        <?php elseif (!$abtTickets): ?>
          <div class="small text-muted mb-2">هنوز تیکتی برای این سفارش ساخته نشده.</div>
          <?php if ($abtCan): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ticket_prepare">
              <button class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> ساختِ تیکت‌ها (یکی برای هر خدمت)</button></form>
          <?php endif; ?>
        <?php else: ?>
          <?php if (!$abtConn && $abtUnsent): ?>
            <div class="alert alert-warning py-2 small mb-2"><i class="fa-solid fa-plug-circle-xmark"></i> اتصالِ API آراد برندینگ هنوز تنظیم/فعال نشده.
              <?php if (user_can('finance_settings', $user)): ?><a href="admin/admin_aradbranding_ticket.php" class="alert-link">تنظیمِ اتصال</a><?php endif; ?>
              — می‌توانید متن را کپی کنید، در aradbranding.me تیکت بزنید و «ثبت دستی» را بزنید.</div>
          <?php endif; ?>
          <?php if ($abtCan && $abtConn && count($abtUnsent) > 1): ?>
            <form method="post" class="mb-2" onsubmit="return confirm('همه‌ی تیکت‌های ارسال‌نشده ارسال شوند؟');"><?= csrf_field() ?><input type="hidden" name="action" value="ticket_send_all">
              <button class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-paper-plane"></i> ارسالِ همه (<?= to_persian_digits((string) count($abtUnsent)) ?> تیکت)</button></form>
          <?php endif; ?>
          <?php foreach ($abtTickets as $tk):
            $ts = abt_statuses()[$tk['status']] ?? ['label' => $tk['status'], 'color' => 'secondary', 'icon' => 'fa-circle'];
            $done = in_array($tk['status'], ['sent', 'manual', 'bundled'], true); $tkId = (int) $tk['id']; ?>
            <div class="border rounded-3 p-2 mb-2">
              <div class="d-flex justify-content-between align-items-center gap-1">
                <div class="small fw-bold"><i class="fa-solid fa-cube text-primary"></i> <?= e((string) ($tk['service_title'] ?: 'کلِ سفارش')) ?>
                  <?php if (!empty($tk['department'])): ?><span class="badge text-bg-light border">واحد: <?= e((string) $tk['department']) ?></span><?php endif; ?></div>
                <span class="badge text-bg-<?= e($ts['color']) ?>"><i class="fa-solid <?= e($ts['icon']) ?>"></i> <?= e($ts['label']) ?></span>
              </div>
              <?php if ($done): ?>
                <div class="small text-muted mt-1">
                  <?= $tk['status'] === 'sent' ? 'ارسال‌شده' : 'ثبتِ دستی' ?> توسطِ <?= e((string) ($tk['sender_name'] ?? '—')) ?> — <?= to_jalali(substr((string) $tk['sent_at'], 0, 10)) ?>
                  <?php if ($tk['external_id']): ?> — تیکت <b dir="ltr"><?= e((string) $tk['external_id']) ?></b><?php endif; ?>
                  <?php if ($__tl = abt_ticket_link($tk)): ?> — <a href="<?= e($__tl) ?>" target="_blank" rel="noopener" class="fw-bold"><i class="fa-solid fa-arrow-up-right-from-square"></i> مشاهده‌ی تیکت در آراد برندینگ</a><?php endif; ?>
                </div>
                <details class="small mt-1"><summary class="text-muted"><?= e((string) $tk['subject']) ?></summary>
                  <div class="p-2 rounded mt-1" style="background:#f8fafc;white-space:pre-wrap;max-height:200px;overflow:auto"><?= e((string) $tk['message']) ?></div></details>
                <?php if ($abtCan): ?>
                  <form method="post" class="d-flex flex-wrap gap-1 mt-2"><?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= $tkId ?>">
                    <button name="action" value="ticket_resend" class="btn btn-sm btn-outline-primary" <?= $abtConn ? '' : 'disabled' ?>
                      onclick="return confirm('این تیکت دوباره در آراد برندینگ ثبت شود؟ فقط وقتی بزنید که تیکتِ قبلی در سایتِ اصلی حذف شده؛ وگرنه مشتری دو تیکت خواهد داشت.');"><i class="fa-solid fa-rotate-right"></i> ارسالِ مجدد</button>
                    <button name="action" value="ticket_reopen" class="btn btn-sm btn-outline-secondary"
                      onclick="return confirm('تیکت برای ویرایشِ متن/واحد و ارسالِ دوباره بازگشایی شود؟');"><i class="fa-solid fa-pen-to-square"></i> ویرایش و ارسالِ مجدد</button>
                  </form>
                <?php endif; ?>
              <?php elseif ($abtCan): ?>
                <?php if ($tk['status'] === 'failed' && $tk['last_error']): ?>
                  <div class="text-danger small mt-1"><i class="fa-solid fa-triangle-exclamation"></i> <?= e((string) $tk['last_error']) ?></div>
                <?php endif; ?>
                <details class="mt-1" <?= $tk['status'] === 'failed' ? 'open' : '' ?>>
                  <summary class="small text-primary">موضوع / متن / واحد (قابلِ ویرایش پیش از ارسال)</summary>
                  <form method="post" class="mt-2">
                    <?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= $tkId ?>">
                    <div class="row g-1 mb-1">
                      <div class="col-8"><input name="ticket_subject" class="form-control form-control-sm" value="<?= e((string) $tk['subject']) ?>" placeholder="موضوع"></div>
                      <div class="col-4"><input name="ticket_department" class="form-control form-control-sm" value="<?= e((string) ($tk['department'] ?? '')) ?>" placeholder="واحد"></div>
                    </div>
                    <textarea name="ticket_message" id="abt-msg-<?= $tkId ?>" class="form-control form-control-sm mb-1" rows="7" dir="rtl" style="font-size:13px;line-height:1.9"><?= e((string) $tk['message']) ?></textarea>
                    <div class="d-flex flex-wrap gap-1">
                      <button name="action" value="ticket_send" class="btn btn-sm btn-primary" <?= $abtConn ? '' : 'disabled' ?> onclick="return confirm('این تیکت ارسال شود؟');"><i class="fa-solid fa-paper-plane"></i> <?= $tk['status'] === 'failed' ? 'ارسالِ دوباره' : 'ارسال' ?></button>
                      <button name="action" value="ticket_save" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button>
                      <button name="action" value="ticket_rebuild" class="btn btn-sm btn-outline-secondary" onclick="return confirm('متن از تنظیماتِ همین خدمت دوباره ساخته شود؟');"><i class="fa-solid fa-rotate"></i> از قالب</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('abt-msg-<?= $tkId ?>').value).then(()=>this.innerHTML='<i class=&quot;fa-solid fa-check&quot;></i> کپی شد')"><i class="fa-regular fa-copy"></i> کپی</button>
                    </div>
                  </form>
                  <form method="post" class="d-flex gap-1 mt-2 flex-wrap">
                    <?= csrf_field() ?><input type="hidden" name="action" value="ticket_manual"><input type="hidden" name="ticket_id" value="<?= $tkId ?>">
                    <input name="external_id" class="form-control form-control-sm" style="max-width:130px" dir="ltr" placeholder="شماره تیکت">
                    <input name="external_url" class="form-control form-control-sm" style="max-width:220px" dir="ltr" placeholder="لینکِ تیکت (اختیاری)">
                    <button class="btn btn-sm btn-outline-info"><i class="fa-solid fa-hand"></i> ثبت دستی</button>
                  </form>
                </details>
              <?php else: ?>
                <div class="small text-muted mt-1"><?= e((string) $tk['subject']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-money-check-dollar text-success"></i> اطلاعاتِ پرداخت</h6>
        <div class="kv"><span>مبلغِ اعلامیِ پرداخت</span><span class="fw-bold"><?= format_toman((int) $order['paid_amount']) ?></span></div>
        <?php if ($diff !== 0): ?>
          <div class="kv"><span>اختلاف با فاکتور</span><span class="<?= $diff < 0 ? 'text-warning' : 'text-info' ?>"><?= $diff < 0 ? 'کسری ' : 'مازاد ' ?><?= format_toman(abs($diff)) ?></span></div>
        <?php endif; ?>
        <?php if ($order['confirmed_amount'] !== null): ?><div class="kv"><span>مبلغِ تأییدشده‌ی مالی</span><span class="text-success fw-bold"><?= format_toman((int) $order['confirmed_amount']) ?></span></div><?php endif; ?>
        <?php $__splits = scr_ready($pdo) ? scr_get($pdo, $orderId) : []; if ($__splits): ?>
          <div class="kv"><span>فروشِ مشترک <span class="text-muted small">(فقط گزارشِ فروش)</span></span><span class="small"><?php foreach ($__splits as $__sp): ?><span class="badge text-bg-light border ms-1"><?= e((string) $__sp['full_name']) ?>: <?= format_toman((int) $__sp['amount']) ?></span><?php endforeach; ?></span></div>
        <?php endif; ?>
        <div class="kv"><span>روش پرداخت</span><span><?= e($methods[$order['payment_method']] ?? (string) $order['payment_method']) ?></span></div>
        <div class="kv"><span>تاریخ پرداخت</span><span><?= $order['payment_date'] ? to_jalali($order['payment_date']) : '—' ?></span></div>
        <div class="kv"><span>شماره پیگیری</span><span dir="ltr"><?= e((string) ($order['payment_ref'] ?? '—')) ?></span></div>
        <?php if (($order['payment_method'] ?? '') === 'barter'): ?>
          <div class="kv"><span>تهاتر با</span><span><?= e((string) ($order['barter_desc'] ?? '—')) ?></span></div>
          <div class="kv"><span>ارزشِ تهاتر</span><span><?= format_toman((int) $order['paid_amount']) ?></span></div>
        <?php endif; ?>
        <div class="kv"><span>شیوه‌ی تسویه</span><span><?= e(orders_settle_label($order, $installments ?? [])) ?></span></div>
        <div class="kv"><span>واریزکننده</span><span><?= e((string) ($order['payer_name'] ?? '—')) ?></span></div>
        <div class="kv"><span>شماره فاکتور</span><span><bdi dir="ltr"><?= e(to_persian_digits((string) $order['order_number'])) ?></bdi></span></div>
        <?php if (!$isLegacyOrder): ?><div class="kv"><span>پیش‌فاکتورِ اولیه</span><span class="text-muted"><?= e(to_persian_digits((string) $order['quote_number'])) ?></span></div><?php endif; ?>
        <?php if ($order['seller_note']): ?><div class="small mt-2 p-2 rounded" style="background:#fafaf9"><b>توضیحِ کارشناس:</b> <?= nl2br(e($order['seller_note'])) ?></div><?php endif; ?>
      </div>

      <?php if ($dupCands): $dupCertain = $dupCands[0]['level'] === 'certain'; ?>
      <div class="card p-3 mb-3" id="dup-check" style="border:2px solid <?= $dupCertain ? '#dc2626' : '#f59e0b' ?>;background:<?= $dupCertain ? '#fef2f2' : '#fffbeb' ?>">
        <h6 class="fw-bold mb-1 <?= $dupCertain ? 'text-danger' : 'text-warning-emphasis' ?>"><i class="fa-solid fa-clone"></i> <?= $dupCertain ? 'واریزیِ تکراری — تقریباً قطعی' : 'احتمالِ واریزیِ تکراری' ?></h6>
        <div class="small text-muted mb-2">همین واریزی احتمالاً توسطِ کارشناسِ دیگری هم در سفارشِ جداگانه ثبت شده. قبل از تأیید، فیش‌ها را کنارِ هم ببینید و مشخص کنید.</div>
        <?php foreach ($dupCands as $dc): $o2 = $dc['order']; ?>
          <div class="border rounded-3 p-2 mb-2 bg-white">
            <div class="d-flex justify-content-between flex-wrap gap-1">
              <div>
                <a href="order_view.php?id=<?= (int) $o2['id'] ?>" target="_blank" class="fw-bold text-decoration-none"><?= e(to_persian_digits((string) $o2['order_number'])) ?></a>
                <?= orders_status_badge((string) $o2['status']) ?>
                <span class="badge <?= $dc['level'] === 'certain' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $dc['level'] === 'certain' ? 'قطعی' : 'محتمل' ?></span>
              </div>
              <div class="small text-muted"><?= to_jalali((string) ($o2['submitted_at'] ?? $o2['created_at'])) ?> <?= e(to_persian_digits(substr((string) ($o2['submitted_at'] ?? $o2['created_at']), 11, 5))) ?></div>
            </div>
            <div class="small mt-1">
              کارشناس: <b><?= e((string) ($o2['seller_name'] ?? '—')) ?></b> — مشتری: <?= e((string) ($o2['customer_name'] ?? '—')) ?>
              — واریزی: <b><?= format_toman((int) $o2['paid_amount']) ?></b><?= $o2['payment_ref'] ? ' — پیگیری: <span dir="ltr">' . e((string) $o2['payment_ref']) . '</span>' : '' ?>
            </div>
            <ul class="small mb-2 mt-1 ps-3">
              <?php foreach ($dc['reasons'] as $why): ?><li><?= e($why) ?></li><?php endforeach; ?>
            </ul>
            <?php if ($canDecide): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php if ($order['status'] === 'pending'): ?>
                  <form method="post" onsubmit="return confirm('یک واریزی است؛ همین سفارش (<?= e((string) $order['order_number']) ?>) به‌عنوانِ تکراری رد شود و سفارشِ <?= e((string) $o2['order_number']) ?> بماند؟');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="dup_same"><input type="hidden" name="other_id" value="<?= (int) $o2['id'] ?>">
                    <button class="btn btn-sm btn-danger"><i class="fa-solid fa-clone"></i> یک واریزی است — این سفارش رد شود</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('مطمئنید این‌ها دو واریزیِ جدا هستند؟');">
                  <?= csrf_field() ?><input type="hidden" name="action" value="dup_separate"><input type="hidden" name="other_id" value="<?= (int) $o2['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-code-branch"></i> دو واریزیِ جداست</button>
                </form>
                <a href="order_view.php?id=<?= (int) $o2['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-receipt"></i> دیدنِ فیشِ آن سفارش</a>
              </div>
              <?php if ($order['status'] !== 'pending'): ?><div class="small text-muted mt-1">این سفارش تأیید شده؛ اگر تکراری است، از داخلِ سفارشِ <?= e((string) $o2['order_number']) ?> آن را رد کنید.</div><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($canDecide && $order['status'] !== 'cancelled'): ?>
      <div class="card p-3 mb-3 dec" id="decide" style="border-color:#bbf7d0">
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-scale-balanced text-success"></i> تصمیمِ واحد مالی</h6>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="decide">
          <input type="hidden" name="form_version" value="<?= e(md5((string) $order['status'] . '|' . (string) $order['paid_amount'] . '|' . (string) ($order['submitted_at'] ?? '') . '|' . (string) ($order['payment_date'] ?? ''))) ?>">
          <label class="form-label small mb-1">مبلغِ دریافتیِ تأییدشده (تومان) <span class="text-muted">— مبلغِ اعلامیِ کارشناس: <b dir="ltr"><?= e(number_format((int) $order['paid_amount'])) ?></b></span></label>
          <input name="confirmed_amount" id="decConfirmed" data-declared="<?= (int) $order['paid_amount'] ?>" class="form-control form-control-sm mb-1" dir="ltr" value="<?= e(number_format((int) ($order['status'] === 'approved' ? ($order['confirmed_amount'] ?? $order['paid_amount']) : $order['paid_amount']))) ?>">
          <div class="form-check small mb-2 p-2 rounded" id="decMismatch" style="background:#fff7ed;display:none">
            <input class="form-check-input ms-0 me-1" type="checkbox" name="amount_mismatch_ack" value="1" id="decMismatchAck">
            <label class="form-check-label text-warning-emphasis" for="decMismatchAck">مبلغِ تأییدی با مبلغِ اعلامیِ کارشناس فرق دارد؛ مبلغِ متفاوت را عمداً تأیید می‌کنم (طبقِ فیش).</label>
          </div>
          <label class="form-label small mb-1">تاریخِ واریز طبقِ فیش <span class="text-muted">(مبنای «تاریخ عملکرد» در سهم عملکرد — با فیش چک کنید)</span></label>
          <input name="receipt_date" class="form-control form-control-sm mb-2 jalali-date" autocomplete="off" value="<?= e(!empty($order['payment_date']) ? to_jalali((string) $order['payment_date']) : '') ?>">
          <label class="form-label small mb-1">توضیح (برای «رد» الزامی است)</label>
          <textarea name="finance_note" class="form-control form-control-sm mb-2" rows="2"><?= e((string) ($order['finance_note'] ?? '')) ?></textarea>
          <?php if ($order['status'] !== 'approved' && scr_ready($pdo)) echo scr_editor_html($pdo, $order, 'decConfirmed'); ?>
          <?php if ($dupCands): ?>
            <div class="form-check small mb-2 p-2 rounded" style="background:#fef2f2">
              <input class="form-check-input ms-0 me-1" type="checkbox" name="dup_ack" value="1" id="dup_ack">
              <label class="form-check-label text-danger" for="dup_ack">هشدارِ واریزیِ تکراری را بررسی کردم و این سفارش جداگانه تأیید شود.</label>
            </div>
          <?php endif; ?>
          <script>
          (function () {
            var f = document.getElementById('decConfirmed'), box = document.getElementById('decMismatch');
            if (!f || !box) return;
            function sync() {
              var v = Number(String(f.value).replace(/[^0-9]/g, '')), d = Number(f.dataset.declared);
              box.style.display = (v && v !== d) ? 'block' : 'none';
            }
            f.addEventListener('input', sync); sync();
          })();
          </script>
          <div class="d-flex gap-2 flex-wrap">
            <?php if ($order['status'] !== 'approved'): ?><button name="decision" value="approved" class="btn btn-success btn-sm flex-grow-1" onclick="var f=document.getElementById('decConfirmed');return confirm('سفارش با مبلغِ تأییدیِ ' + f.value + ' تومان تأیید و ثبت شود؟\n(مبلغِ اعلامیِ کارشناس: ' + Number(f.dataset.declared).toLocaleString('en-US') + ' تومان — با فیش مطابقت دارد؟)')"><i class="fa-solid fa-circle-check"></i> تأیید و ثبت سفارش</button><?php endif; ?>
            <?php if ($order['status'] !== 'pending'): ?><button name="decision" value="pending" class="btn btn-warning btn-sm flex-grow-1"><i class="fa-solid fa-hourglass-half"></i> در انتظار بررسی</button><?php endif; ?>
            <?php if ($order['status'] !== 'rejected'): ?><button name="decision" value="rejected" class="btn btn-outline-danger btn-sm flex-grow-1"><i class="fa-solid fa-circle-xmark"></i> رد</button><?php endif; ?>
          </div>
        </form>
        <?php if ($order['status'] === 'approved' && scr_ready($pdo)): ?>
          <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="sales_split">
            <?= scr_editor_html($pdo, $order) ?>
            <button class="btn btn-sm" style="background:#6d28d9;color:#fff"><i class="fa-solid fa-floppy-disk"></i> ذخیره‌ی تفکیکِ فروش</button>
          </form>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2 mt-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="finance_note">
          <input name="note" class="form-control form-control-sm" placeholder="یادداشتِ داخلیِ مالی (بدونِ تغییرِ وضعیت)">
          <button class="btn btn-sm btn-outline-secondary">ثبت</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ((is_super_admin($user) || user_can('orders_delete', $user)) && in_array($order['status'], ['pending', 'rejected'], true)): ?>
        <form method="post" class="card p-3 mb-3" onsubmit="return confirm('این سفارش لغو شود؟');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <div class="d-flex gap-2">
            <input name="reason" class="form-control form-control-sm" placeholder="دلیلِ لغو (اختیاری)">
            <button class="btn btn-sm btn-outline-danger text-nowrap"><i class="fa-solid fa-ban"></i> لغو سفارش</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="card p-3">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left text-warning"></i> تاریخچه</h6>
        <div class="tl">
          <?php foreach ($history as $h): ?>
            <div class="it">
              <div class="small fw-bold"><?= e($historyLabels[$h['action']] ?? $h['action']) ?></div>
              <div class="small text-muted"><?= to_jalali($h['created_at']) ?> <?= e(substr((string) $h['created_at'], 11, 5)) ?> — <?= e($h['user_name'] ?? '—') ?></div>
              <?php if ($h['note']): ?><div class="small"><?= nl2br(e($h['note'])) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
