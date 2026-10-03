<?php
/**
 * قرارداد: ساخت از پیش‌فاکتورِ قفل‌شده، تکمیل/ویرایش، تأیید، صدور، چاپ، ارسال برای مشتری
 */
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
require_once __DIR__ . '/includes/services_functions.php';
require_once __DIR__ . '/includes/orders_functions.php';
require_once __DIR__ . '/includes/contracts_functions.php';
require_once __DIR__ . '/includes/aradbranding_ticket.php';

if (!ctr_ready($pdo)) {
    flash_set('danger', 'ماژولِ قرارداد آماده نیست (ساختِ جدول‌ها ناموفق بود).');
    redirect('customer_list.php');
}
$uid = (int) $user['id'];
$isAjax = ($_POST['ajax'] ?? '') === '1';
$json = static function (array $d): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
};

/* ---------------- ساختِ قرارداد از پیش‌فاکتور ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $quoteId = (int) ($_POST['quote_id'] ?? 0);
    $st = $pdo->prepare('SELECT q.id, q.customer_id, c.owner_user_id FROM quotes q JOIN customers c ON c.id = q.customer_id WHERE q.id = ?');
    $st->execute([$quoteId]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    $back = $q ? 'customer_view.php?id=' . (int) $q['customer_id'] . '#customer-contracts' : 'customer_list.php';
    if (!csrf_verify()) {
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect($back);
    }
    if (!$q || !((ctr_can_manage_customer($pdo, $user, (int) $q['owner_user_id']) && user_can('quotes_manage', $user)) || ctr_can_approve($user))) {
        flash_set('danger', 'اجازه‌ی ساختِ قرارداد برای این مشتری را ندارید.');
        redirect($back);
    }
    $r = ctr_create($pdo, $quoteId, $uid);
    flash_set($r['ok'] ? (!empty($r['existing']) ? 'info' : 'success') : 'danger', $r['message']);
    if (!$r['ok'] && !empty($r['kyc_incomplete'])) {
        // مستقیم به فرمِ تکمیلِ مدارک در پرونده‌ی مشتری
        redirect('customer_view.php?id=' . (int) $r['customer_id'] . '&kyc=1#customer-kyc');
    }
    redirect($r['ok'] ? 'contract_view.php?id=' . $r['id'] : $back);
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$contract = ctr_get($pdo, $id);
if (!$contract) {
    if ($isAjax) $json(['ok' => false, 'message' => 'قرارداد پیدا نشد.']);
    flash_set('danger', 'قرارداد پیدا نشد.');
    redirect('customer_list.php');
}
if (!ctr_can_view($pdo, $user, $contract)) {
    if ($isAjax) $json(['ok' => false, 'message' => 'دسترسی ندارید.']);
    perm_deny('اجازه‌ی مشاهده‌ی این قرارداد را ندارید.', $user);
}

$status = (string) $contract['status'];
$canManage = ctr_can_manage_customer($pdo, $user, (int) $contract['owner_user_id']) && user_can('quotes_manage', $user);
$canApprove = ctr_can_approve($user);
$canEdit = ($status === 'draft' && ($canManage || $canApprove)) || ($status === 'approved' && $canApprove);
$canSend = $status === 'issued' && ($canManage || $canApprove || user_can('finance_orders_view', $user)
    || ctr_can_manage_customer($pdo, $user, (int) $contract['owner_user_id']));
$canDelete = ctr_can_delete($user);
$canEditBody = in_array($status, ['draft', 'approved'], true) && $canApprove;
$self = 'contract_view.php?id=' . $id;
// سفارشِ همین پیش‌فاکتور (برای برگشت به صفحه‌ی بررسیِ مالی)
$linkedOrder = function_exists('orders_active_for_quote') ? orders_active_for_quote($pdo, (int) $contract['quote_id']) : null;
$safeReturn = static function (?string $r): ?string {
    $r = (string) $r;
    return ($r !== '' && preg_match('#^[a-z_]+\.php(\?[^\s"\'<>]*)?$#i', $r)) ? $r : null;
};

/* ---------------- عملیات ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_verify()) {
        if ($isAjax) $json(['ok' => false, 'message' => 'نشست منقضی شده است؛ صفحه را تازه کنید.']);
        flash_set('danger', 'نشست منقضی شده است؛ دوباره تلاش کنید.');
        redirect($self);
    }
    $now = date('Y-m-d H:i:s');

    if ($action === 'delete') {
        if (!$canDelete) {
            flash_set('danger', 'اجازه‌ی حذفِ قرارداد را ندارید.');
            redirect($self);
        }
        $r = ctr_delete($pdo, $contract, $uid);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        if (!$r['ok']) redirect($self);
        redirect($safeReturn($_POST['return'] ?? null) ?? ('customer_view.php?id=' . (int) $contract['customer_id'] . '#customer-contracts'));
    }
    if ($action === 'reopen' && $status === 'issued' && $canApprove) {
        $r = ctr_reopen($pdo, $contract, $uid);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect($self . '#edit-panel');
    }
    if ($action === 'save_body' && $canEditBody) {
        $body = str_replace(["\r\n", "\r"], "\n", trim((string) ($_POST['template_body'] ?? '')));
        if (mb_strlen($body) < 50) {
            flash_set('danger', 'متنِ قرارداد خیلی کوتاه است؛ ذخیره نشد.');
        } else {
            $pdo->prepare('UPDATE contracts SET template_body = ?, updated_at = ? WHERE id = ?')->execute([$body, $now, $id]);
            flash_set('success', 'متنِ همین قرارداد اصلاح شد (قالبِ اصلی و قراردادهای دیگر تغییری نکردند).');
        }
        redirect($self . '#body-editor');
    }
    if ($action === 'save' && $canEdit) {
        $manual = json_decode((string) $contract['fields_json'], true) ?: [];
        $date = to_gregorian(normalize_digits(trim((string) ($_POST['contract_date'] ?? '')))) ?: (string) $contract['contract_date'];
        $settle = trim((string) ($_POST['settle_date'] ?? ''));
        $manual['تاریخ_تسویه_بدهی'] = $settle !== '' ? (to_gregorian(normalize_digits($settle)) ?: '') : '';
        $manual['مبلغ_تبدیل'] = (string) orders_money($_POST['transfer_amount'] ?? '');
        // انتقال از سفارش/قراردادِ قبلی (با صدور، قبلی لغو و مبلغش منتقل می‌شود)
        $trErr = [];
        $srcById = [];
        $trLocked = !empty($manual['transfers_applied']); // در صدورِ قبلی انجام شده؛ قابلِ تغییر نیست
        if (!$trLocked) {
            $manual['transfers'] = [];
            foreach (ctr_transfer_sources($pdo, $contract) as $src) {
                $srcById[$src['order_id']] = $src;
            }
        }
        foreach ($trLocked ? [] : (array) ($_POST['tr_order'] ?? []) as $oid) {
            $oid = (int) $oid;
            if (!isset($srcById[$oid])) continue;
            $src = $srcById[$oid];
            $amt = orders_money($_POST['tr_amount'][$oid] ?? '') ?: $src['paid'];
            if ($amt > $src['paid']) {
                $trErr[] = 'مبلغِ انتقال از «' . $src['label'] . '» نمی‌تواند بیشتر از پرداختیِ آن (' . number_format($src['paid']) . ' تومان) باشد؛ همان مبلغِ پرداختی ثبت شد.';
                $amt = $src['paid'];
            }
            $manual['transfers'][] = ['order_id' => $oid, 'amount' => $amt, 'label' => $src['label']];
        }
        $manual['سطح_پروموشن'] = mb_substr(trim((string) ($_POST['promotion'] ?? '')), 0, 100);
        // اطلاعاتِ هویتی در پرونده‌ی مشتری ذخیره می‌شود (یک‌بار ثبت، همه‌جا استفاده)
        $kycData = [];
        foreach (['national_id', 'postal_code', 'address'] as $k) {
            if (isset($_POST[$k]) && trim((string) $_POST[$k]) !== '') $kycData[$k] = (string) $_POST[$k];
        }
        if (isset($kycData['national_id']) && isset($_POST['id_type'])) $kycData['id_type'] = (string) $_POST['id_type']; // خالی = تشخیصِ خودکار (اتباع)
        $errors = $kycData ? kyc_save($pdo, (int) $contract['customer_id'], $kycData, null, $uid) : [];
        $pdo->prepare('UPDATE contracts SET contract_date = ?, fields_json = ?, updated_at = ? WHERE id = ?')
            ->execute([$date, json_encode($manual, JSON_UNESCAPED_UNICODE), $now, $id]);
        $errors = array_merge($errors, $trErr);
        flash_set($errors ? 'warning' : 'success', $errors ? 'قرارداد ذخیره شد، ولی: ' . implode(' ', $errors) : 'تغییراتِ قرارداد ذخیره شد.');
        redirect($self);
    }
    if ($action === 'refresh_template' && $status === 'draft' && ($canManage || $canApprove)) {
        $tpl = ctr_template_active($pdo);
        $pdo->prepare('UPDATE contracts SET template_id = ?, template_version = ?, template_body = ?, attachment_text = ?, updated_at = ? WHERE id = ?')
            ->execute([$tpl['id'] ?? null, (int) $tpl['version'], (string) $tpl['body'], (string) ($tpl['attachment_text'] ?? 'دارد'), $now, $id]);
        flash_set('success', 'متنِ قرارداد با آخرین نسخه‌ی قالب (نسخه‌ی ' . to_persian_digits((string) $tpl['version']) . ') به‌روز شد.');
        redirect($self);
    }
    if ($action === 'approve' && $status === 'draft' && $canApprove) {
        $d = ctr_document($pdo, $contract, false);
        if ($d['missing']) {
            flash_set('danger', 'پیش از تأیید این موارد را تکمیل کنید: ' . implode('، ', $d['missing']));
        } else {
            $pdo->prepare("UPDATE contracts SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?")->execute([$uid, $now, $now, $id]);
            flash_set('success', 'قرارداد تأیید شد. برای نهایی‌شدن «صدور قرارداد» را بزنید.');
        }
        redirect($self);
    }
    if ($action === 'unapprove' && $status === 'approved' && $canApprove) {
        $pdo->prepare("UPDATE contracts SET status = 'draft', approved_by = NULL, approved_at = NULL, updated_at = ? WHERE id = ?")->execute([$now, $id]);
        flash_set('success', 'قرارداد به پیش‌نویس برگشت.');
        redirect($self);
    }
    if ($action === 'issue' && in_array($status, ['draft', 'approved'], true) && $canApprove) {
        $r = ctr_issue($pdo, $contract, $uid);
        flash_set($r['ok'] ? 'success' : 'danger', $r['message']);
        redirect($self);
    }
    if ($action === 'cancel' && $status !== 'cancelled' && $canApprove) {
        $pdo->prepare("UPDATE contracts SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, updated_at = ? WHERE id = ?")->execute([$uid, $now, $now, $id]);
        $pdo->prepare('UPDATE contract_links SET revoked = 1 WHERE contract_id = ?')->execute([$id]);
        flash_set('success', 'قرارداد باطل شد و همه‌ی لینک‌های ارسال‌شده‌ی آن غیرفعال شدند.');
        redirect($self);
    }

    /* ---------- ارسال برای مشتری ---------- */
    // PDFِ ساخته‌شده در مرورگر (برای پیوستِ تیکت)
    if ($action === 'pdf_upload') {
        if (!$canSend) $json(['ok' => false, 'message' => 'اجازه‌ی ارسالِ این قرارداد را ندارید.']);
        require_once __DIR__ . '/includes/contract_pdf.php';
        $dt = (string) ($_POST['doc'] ?? '');
        if (!isset(ctr_doc_types(true)[$dt])) $json(['ok' => false, 'message' => 'نوعِ سند نامعتبر است.']);
        $r = cpdf_store($pdo, $contract, $dt, $_FILES['pdf'] ?? [], $uid, ctr_link_days());
        $json($r);
    }
    if ($action === 'send_start') {
        if (!$canSend) $json(['ok' => false, 'message' => 'اجازه‌ی ارسالِ این قرارداد را ندارید (یا قرارداد هنوز صادر نشده).']);
        $docs = array_values(array_intersect(['contract', 'quote', 'services'], (array) ($_POST['docs'] ?? [])));
        $chKey = (string) ($_POST['channel'] ?? '');
        $retryOf = (int) ($_POST['retry_of'] ?? 0);
        if ($retryOf) {
            $r = $pdo->prepare('SELECT * FROM contract_sends WHERE id = ? AND contract_id = ?');
            $r->execute([$retryOf, $id]);
            if ($old = $r->fetch(PDO::FETCH_ASSOC)) {
                $docs = array_values(array_filter(explode(',', (string) $old['doc_types'])));
                $chKey = (string) $old['channel'];
            }
        }
        if (!$docs) $json(['ok' => false, 'message' => 'سندی برای ارسال انتخاب نشده.']);
        $isTicket = $chKey === 'ticket';
        $channels = ctr_customer_channels($pdo, (int) $contract['customer_id']);
        $ch = null;
        if ($isTicket) {
            if (!ctr_ticket_available($pdo)) $json(['ok' => false, 'message' => 'ماژولِ تیکتِ آراد برندینگ آماده نیست.']);
            $ch = ['key' => 'ticket', 'messenger' => 'ticket', 'mobile' => (string) ($contract['customer_mobile'] ?? ''), 'label' => 'تیکتِ آراد برندینگ'];
        } else {
            foreach ($channels as $c) {
                if ($c['key'] === $chKey) { $ch = $c; break; }
            }
        }
        if (!$ch) $json(['ok' => false, 'message' => 'پیام‌رسانِ انتخاب‌شده در پرونده‌ی مشتری ثبت نیست.']);

        $types = ctr_doc_types((bool) ctr_invoice_order($pdo, $contract));
        $links = [];
        $linkIds = [];
        $base = ctr_site_url();
        foreach ($docs as $dt) {
            $l = ctr_link_for($pdo, $id, $dt, $uid, ctr_link_days());
            $linkIds[] = (int) $l['id'];
            $links[] = ['type' => $dt, 'label' => $types[$dt]['label'], 'url' => $base . 'doc.php?t=' . $l['token'], 'expires' => to_jalali(substr((string) $l['expires_at'], 0, 10))];
            // اگر PDFِ این سند در مرورگر ساخته و آپلود شده ← لینکِ مستقیمِ PDF برای پیوستِ تیکت
            $pt = (string) (($_POST['pdf_tokens'] ?? [])[$dt] ?? '');
            if ($pt !== '') {
                require_once __DIR__ . '/includes/contract_pdf.php';
                // آدرسِ فایل با پسوندِ .pdf تمام می‌شود (بعضی سامانه‌ها پیوست را از روی پسوند می‌شناسند)
                if (cpdf_get($pdo, $pt, $id)) $links[count($links) - 1]['pdf_url'] = $base . 'doc_pdf.php/' . $pt . '.pdf';
            }
        }
        $d = ctr_document($pdo, $contract, false);
        $f = $d['fields'];
        $msg = 'سلام ' . trim(($f['عنوان'] ?? '') . ' ' . ($contract['customer_name'] ?? '')) . "،\n"
            . 'اسنادِ قرارداد شماره ' . ($f['شماره_قرارداد'] ?? $contract['contract_number']) . " آراد برندینگ برای شما آماده است:\n\n";
        foreach ($links as $l) {
            $msg .= '📄 ' . $l['label'] . ":\n" . $l['url'] . "\n\n";
        }
        $msg .= 'هر لینک یک فایلِ جداست و تا ' . $links[0]['expires'] . " معتبر است. برای ذخیره، در صفحه‌ی باز‌شده «دانلود / ذخیره PDF» را بزنید.\nبا سپاس — آراد برندینگ";
        if ($isTicket) {
            $dept = trim((string) ($_POST['department'] ?? '')) ?: ctr_ticket_default_department();
            $tr = ctr_send_ticket($pdo, $contract, $docs, $links, $msg, $dept, $uid);
            if (($tr['status'] ?? '') === 'duplicate') $json(['ok' => false, 'message' => $tr['message']]); // تکراری: نه تیکتِ تازه، نه سابقه‌ی ارسال
            $pdo->prepare('INSERT INTO contract_sends (contract_id, customer_id, quote_id, doc_types, channel, status, result_note, link_ids, sent_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, (int) $contract['customer_id'], (int) $contract['quote_id'], implode(',', $docs), 'ticket',
                    $tr['ok'] ? 'sent' : ($tr['status'] === 'queued' ? 'sending' : 'failed'),
                    mb_substr('تیکت (دپارتمان: ' . $dept . ') — ' . $tr['message'], 0, 500), implode(',', $linkIds), $uid, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $json(['ok' => true, 'ticket' => true, 'send_id' => (int) $pdo->lastInsertId(), 'message' => (string) ($tr['body'] ?? $msg), 'open_url' => '', 'prefill' => false,
                   'channel_label' => 'تیکتِ آراد برندینگ (دپارتمان: ' . $dept . ')', 'links' => $links,
                   'ticket_ok' => (bool) $tr['ok'], 'ticket_message' => $tr['message'],
                   'pdf_count' => count(array_filter($links, static fn($l) => !empty($l['pdf_url'])))]);
        }
        $open = ctr_channel_open_url($ch['messenger'], $ch['mobile'], $msg);

        $pdo->prepare('INSERT INTO contract_sends (contract_id, customer_id, quote_id, doc_types, channel, status, result_note, link_ids, sent_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, (int) $contract['customer_id'], (int) $contract['quote_id'], implode(',', $docs), $ch['key'], 'sending',
                $retryOf ? 'تلاشِ مجدد برای ارسالِ #' . $retryOf : null, implode(',', $linkIds), $uid, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $json(['ok' => true, 'send_id' => (int) $pdo->lastInsertId(), 'message' => $msg, 'open_url' => $open['url'], 'prefill' => $open['prefill'],
               'channel_label' => $ch['label'], 'links' => $links]);
    }
    if ($action === 'send_mark') {
        if (!$canSend) $json(['ok' => false, 'message' => 'دسترسی ندارید.']);
        $sid = (int) ($_POST['send_id'] ?? 0);
        $new = (string) ($_POST['status'] ?? '');
        if (!in_array($new, ['sent', 'failed'], true)) $json(['ok' => false, 'message' => 'وضعیت نامعتبر است.']);
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 400);
        $pdo->prepare('UPDATE contract_sends SET status = ?, result_note = CASE WHEN ? = \'\' THEN result_note ELSE ? END, updated_at = ? WHERE id = ? AND contract_id = ?')
            ->execute([$new, $note, $note, date('Y-m-d H:i:s'), $sid, $id]);
        if ($isAjax) $json(['ok' => true]);
        flash_set('success', 'وضعیتِ ارسال ثبت شد.');
        redirect($self . '#send-log');
    }
    if ($action === 'revoke_links' && $canApprove) {
        $pdo->prepare('UPDATE contract_links SET revoked = 1 WHERE contract_id = ?')->execute([$id]);
        flash_set('success', 'همه‌ی لینک‌های ارسال‌شده‌ی این قرارداد غیرفعال شدند؛ ارسالِ بعدی لینکِ تازه می‌سازد.');
        redirect($self . '#send-log');
    }
    if ($isAjax) $json(['ok' => false, 'message' => 'این عملیات مجاز نیست.']);
    flash_set('danger', 'این عملیات مجاز نیست.');
    redirect($self);
}

/* ---------------- داده‌های نمایش ---------------- */
$doc = ctr_document($pdo, $contract, true);
$fields = $doc['fields'];
$manual = json_decode((string) $contract['fields_json'], true) ?: [];
$kyc = function_exists('kyc_get') ? kyc_get($pdo, (int) $contract['customer_id']) : [];
$activeTpl = ctr_template_active($pdo);
$transferSources = $canEdit ? ctr_transfer_sources($pdo, $contract) : [];
$selTransfers = [];
foreach (ctr_transfers($manual) as $__t) {
    $selTransfers[$__t['order_id']] = $__t;
}
$tplOutdated = $status === 'draft' && (int) $activeTpl['version'] !== (int) $contract['template_version'];
$channels = ctr_customer_channels($pdo, (int) $contract['customer_id']);
$sends = ctr_sends_of($pdo, $id);
$sendStatuses = ctr_send_statuses();
$invoiceOrder = $doc['invoice'] ?? null;               // فاکتورِ مبنای قرارداد (null = هنوز بر اساسِ پیش‌فاکتور)
$liveInvoice = ctr_live_invoice_order($pdo, (int) $contract['quote_id']);
$docTypes = ctr_doc_types((bool) $invoiceOrder);
$finDocLabel = $invoiceOrder ? 'فاکتور' : 'پیش‌فاکتور';
$netNames = social_network_options();
$ticketReady = ctr_ticket_available($pdo);
$ticketLive = $ticketReady && function_exists('abt_connection_ready') && abt_connection_ready(abt_settings($pdo));
$st = ctr_statuses()[$status] ?? ['label' => $status, 'color' => 'secondary', 'icon' => 'fa-circle'];

$pageTitle = 'قرارداد ' . $contract['contract_number'];
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.ctv{ --g:#c9a24b; --line:rgba(201,162,75,.28); }
.ctv .hero{ background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 60%,var(--g) 140%); color:#f6efdd; border-radius:16px; padding:18px 20px; }
.ctv .hero h4{ margin:0; font-weight:800; }
.ctv .hero .meta{ font-size:13px; opacity:.85; line-height:2; }
.ctv .card{ border-radius:14px; border:1px solid var(--line); }
.ctv .kv th{ color:#78716c; font-weight:500; width:150px; white-space:nowrap; }
.ctv .paper{ background:#fff; border:1px solid #e7e0cf; border-radius:12px; padding:22px 24px; font-size:14px; line-height:2.1; text-align:justify; max-height:620px; overflow:auto; }
.ctv .paper h1.ctr-title{ text-align:center; font-size:18px; font-weight:800; }
.ctv .paper h3.ctr-art{ font-size:15px; font-weight:800; margin:14px 0 4px; }
.ctv .paper b.ph{ background:#fef9c3; border-radius:4px; padding:0 3px; }
.ctv .paper .ph-miss{ background:#fee2e2; color:#b91c1c; border-radius:4px; padding:0 4px; font-weight:700; }
.ctv .steps{ display:flex; gap:6px; flex-wrap:wrap; }
.ctv .steps span{ font-size:12px; padding:4px 10px; border-radius:20px; background:rgba(255,255,255,.12); }
.ctv .steps span.on{ background:var(--g); color:#1c1917; font-weight:700; }
.ctv .send-btn{ min-width:150px; }
.ctv .ch-chip input{ display:none; }
.ctv .ch-chip label{ border:1px solid #d6d3d1; border-radius:20px; padding:5px 12px; cursor:pointer; font-size:13px; }
.ctv .ch-chip input:checked + label{ background:#1c1917; color:#fff; border-color:#1c1917; }
@media (max-width:576px){ #send-log th:nth-child(4), #send-log td:nth-child(4){ display:none; } #send-log td, #send-log th{ white-space:normal; } .ctv .kv th{ width:110px; } .ctv .paper{ padding:14px; font-size:13.5px; } }
</style>

<div class="ctv">
  <div class="mb-2 d-flex flex-wrap gap-2">
    <a href="customer_view.php?id=<?= (int) $contract['customer_id'] ?>#customer-contracts" class="btn btn-sm btn-outline-secondary">→ برگشت به پرونده‌ی مشتری</a>
    <?php if ($linkedOrder && user_can_any(['finance_orders_view', 'finance_orders_decide'], $user)): ?>
      <a href="order_view.php?id=<?= (int) $linkedOrder['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-scale-balanced"></i> برگشت به بررسیِ مالیِ سفارش <?= e(to_persian_digits((string) $linkedOrder['order_number'])) ?></a>
    <?php endif; ?>
  </div>

  <div class="hero mb-3 d-flex justify-content-between flex-wrap gap-3 align-items-center">
    <div>
      <h4><i class="fa-solid fa-file-signature"></i> قرارداد <bdi dir="ltr"><?= e(to_persian_digits((string) $contract['contract_number'])) ?></bdi></h4>
      <div class="meta">
        مشتری: <b><?= e((string) $contract['customer_name']) ?></b> |
        <?php if ($invoiceOrder): ?>
          فاکتور: <?php if (user_can_any(['finance_orders_view', 'finance_orders_decide'], $user)): ?><a class="text-warning" href="order_view.php?id=<?= (int) $invoiceOrder['id'] ?>"><?php endif; ?><bdi dir="ltr"><?= e(to_persian_digits((string) $invoiceOrder['order_number'])) ?></bdi><?php if (user_can_any(['finance_orders_view', 'finance_orders_decide'], $user)): ?></a><?php endif; ?> |
        <?php else: ?>
          پیش‌فاکتور: <a class="text-warning" href="quote_edit.php?id=<?= (int) $contract['quote_id'] ?>"><bdi dir="ltr"><?= e(to_persian_digits((string) $contract['quote_number'])) ?></bdi></a> |
        <?php endif; ?>
        قالب: نسخه‌ی <?= to_persian_digits((string) $contract['template_version']) ?> |
        ساخته‌شده توسط <?= e((string) ($contract['creator_name'] ?? '')) ?> — <?= to_jalali(substr((string) $contract['created_at'], 0, 10)) ?>
      </div>
    </div>
    <div class="text-start">
      <span class="badge text-bg-<?= e($st['color']) ?> fs-6"><i class="fa-solid <?= e($st['icon']) ?>"></i> <?= e($st['label']) ?></span>
      <div class="steps mt-2">
        <?php foreach (['draft' => 'پیش‌نویس', 'approved' => 'تأیید', 'issued' => 'صدور'] as $k => $l): ?>
          <span class="<?= $k === $status ? 'on' : '' ?>"><?= $l ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($status === 'cancelled'): ?>
    <div class="alert alert-dark">این قرارداد باطل شده است<?= $contract['cancelled_at'] ? ' (' . to_jalali(substr((string) $contract['cancelled_at'], 0, 10)) . ')' : '' ?>. لینک‌های ارسالیِ آن برای مشتری دیگر باز نمی‌شوند.</div>
  <?php endif; ?>

  <?php if ($status !== 'cancelled'): ?>
    <?php if ($invoiceOrder): ?>
      <div class="alert alert-success py-2 small"><i class="fa-solid fa-file-invoice-dollar"></i>
        مبنای این قرارداد <b>فاکتور شماره <bdi dir="ltr"><?= e(to_persian_digits((string) $invoiceOrder['order_number'])) ?></bdi></b>
        (<?= e(to_jalali(ctr_invoice_date($invoiceOrder))) ?>) است؛ مبلغ، واریزی و اقساط از همین فاکتور خوانده می‌شود و در متن و پیوست‌ها «پیش‌فاکتور» قید نمی‌شود.</div>
    <?php elseif ($status === 'issued' && $liveInvoice): ?>
      <div class="alert alert-warning py-2 small"><i class="fa-solid fa-triangle-exclamation"></i>
        این قرارداد پیش از صدورِ فاکتور و <b>بر اساسِ پیش‌فاکتور</b> صادر شده، ولی اکنون فاکتور شماره <b><bdi dir="ltr"><?= e(to_persian_digits((string) $liveInvoice['order_number'])) ?></bdi></b> صادر شده است.
        <?php if ($canApprove): ?>برای این‌که قرارداد بر اساسِ فاکتور تنظیم شود، «بازگشایی برای ویرایش» و سپس دوباره «صدور قرارداد» را بزنید.<?php endif; ?></div>
    <?php else: ?>
      <div class="alert alert-light border py-2 small"><i class="fa-solid fa-circle-info"></i>
        هنوز برای این پیش‌فاکتور فاکتور صادر نشده؛ قرارداد فعلاً بر اساسِ <b>پیش‌فاکتور</b> تنظیم می‌شود. بعد از صدورِ فاکتور، همه‌چیز خودکار بر اساسِ فاکتور می‌شود.</div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <!-- اقدامات -->
      <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-print"></i> چاپ و PDF</h6>
        <div class="d-flex flex-wrap gap-2">
          <a href="contract_print.php?id=<?= $id ?>&doc=contract" class="btn btn-dark btn-sm"><i class="fa-solid fa-file-signature"></i> چاپ قرارداد</a>
          <a href="contract_print.php?id=<?= $id ?>&doc=quote" class="btn btn-outline-dark btn-sm"><i class="fa-solid fa-file-invoice"></i> پیوست: <?= e($finDocLabel) ?></a>
          <a href="contract_print.php?id=<?= $id ?>&doc=services" class="btn btn-outline-dark btn-sm"><i class="fa-solid fa-list-check"></i> پیوست: شرح خدمات</a>
        </div>
        <div class="small text-muted mt-2">در صفحه‌ی چاپ، «چاپ / ذخیره PDF» را بزنید و مقصد را «Save as PDF» انتخاب کنید.<?= $status !== 'issued' ? ' تا پیش از صدور، روی چاپ عبارتِ «پیش‌نویس» دیده می‌شود.' : '' ?></div>

        <?php if (($status !== 'cancelled' && ($canApprove || ($status === 'draft' && $canManage))) || $canDelete): ?>
        <hr>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($status === 'draft' && $canApprove): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="approve">
              <button class="btn btn-info btn-sm" <?= $doc['missing'] ? 'disabled' : '' ?>><i class="fa-solid fa-circle-check"></i> تأیید قرارداد</button></form>
          <?php endif; ?>
          <?php if (in_array($status, ['draft', 'approved'], true) && $canApprove): ?>
            <form method="post" onsubmit="return confirm('<?= $invoiceOrder ? 'قرارداد بر اساسِ فاکتور شماره ' . e(to_persian_digits((string) $invoiceOrder['order_number'])) . ' صادر می‌شود.' : 'هنوز فاکتوری صادر نشده؛ قرارداد بر اساسِ پیش‌فاکتور صادر می‌شود.' ?>\nبا صدور، متنِ قرارداد و نسخه‌ی <?= e($finDocLabel) ?>/شرح خدمات ثابت می‌شود و دیگر قابل ویرایش نیست. ادامه می‌دهید؟');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="issue">
              <button class="btn btn-success btn-sm" <?= $doc['missing'] ? 'disabled' : '' ?>><i class="fa-solid fa-stamp"></i> صدور قرارداد</button></form>
          <?php endif; ?>
          <?php if ($status === 'approved' && $canApprove): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="unapprove">
              <button class="btn btn-outline-secondary btn-sm">برگشت به پیش‌نویس</button></form>
          <?php endif; ?>
          <?php if ($status === 'issued' && $canApprove): ?>
            <form method="post" onsubmit="return confirm('قرارداد برای اصلاح باز شود؟ تا «صدورِ» دوباره، لینک‌های ارسال‌شده برای مشتری سند را نشان نمی‌دهند.');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reopen">
              <button class="btn btn-warning btn-sm"><i class="fa-solid fa-lock-open"></i> بازگشایی برای ویرایش</button></form>
          <?php endif; ?>
          <?php if ($canApprove && $status !== 'cancelled'): ?>
            <form method="post" onsubmit="return confirm('قرارداد باطل شود؟ لینک‌های ارسال‌شده هم غیرفعال می‌شوند.');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
              <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-ban"></i> ابطال</button></form>
          <?php endif; ?>
          <?php if ($canDelete): ?>
            <form method="post" onsubmit="return confirm('قرارداد <?= e((string) $contract['contract_number']) ?> برای همیشه حذف شود؟\nلینک‌ها و سابقه‌ی ارسالش هم پاک می‌شوند و این کار برگشت‌پذیر نیست.<?= $status === 'issued' ? '\n(اگر در صدورِ این قرارداد مبلغی از سفارشِ قبلی منتقل شده، آن انتقال سرِ جایش می‌ماند.)' : '' ?>');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete">
              <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash-can"></i> حذف قرارداد</button></form>
          <?php endif; ?>
        </div>
        <?php if ($status === 'draft' && !$canApprove): ?>
          <div class="small text-muted mt-2"><i class="fa-solid fa-circle-info"></i> تأیید و صدورِ قرارداد با واحدِ مالی / مدیر است.</div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($doc['missing'] && $status !== 'issued'): ?>
          <div class="alert alert-warning small mt-3 mb-0"><i class="fa-solid fa-triangle-exclamation"></i> برای تأیید و صدور، این موارد لازم است: <b><?= e(implode('، ', $doc['missing'])) ?></b>
            — <a href="customer_view.php?id=<?= (int) $contract['customer_id'] ?>&amp;kyc=1#customer-kyc" class="alert-link">تکمیل در «مدارک و اطلاعاتِ هویتیِ مشتری»</a></div>
        <?php endif; ?>
        <?php if ($status === 'issued'): ?>
          <div class="small text-success mt-3"><i class="fa-solid fa-lock"></i> صادرشده توسط <?= e((string) ($contract['issuer_name'] ?? '')) ?> — <?= to_jalali(substr((string) $contract['issued_at'], 0, 10)) ?>. متن و پیوست‌ها منجمد شده‌اند و تغییرِ قالب، قیمت یا شرحِ خدمات روی آن اثری ندارد.</div>
        <?php endif; ?>
      </div>

      <!-- اطلاعات -->
      <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-id-card"></i> اطلاعاتِ قرارداد</h6>
          <?php if (!$doc['frozen']): ?><span class="small text-muted">خودکار از پرونده و سفارش</span><?php endif; ?>
        </div>
        <table class="table table-sm kv mb-2">
          <?php
          $rows = [
              'نام و نام خانوادگی' => trim(($fields['عنوان'] ?? '') . ' ' . ($fields['نام_و_نام_خانوادگی'] ?? '')),
              'نام پدر' => $fields['نام_پدر'] ?? '', 'کد ملی' => $fields['کد_ملی'] ?? '', 'شماره همراه' => $fields['شماره_همراه'] ?? '',
              'آدرس' => $fields['آدرس'] ?? '', 'کد پستی' => $fields['کد_پستی'] ?? '',
              'تاریخ قرارداد' => $fields['تاریخ_قرارداد'] ?? '',
              'مبلغ قرارداد' => ($fields['مبلغ_قرارداد'] ?? '') !== '' ? $fields['مبلغ_قرارداد'] . ' تومان' : '',
              'منتقل‌شده از قبل' => ($fields['مبلغ_تبدیل'] ?? '') !== '' ? $fields['مبلغ_تبدیل'] . ' تومان' . (($fields['قراردادهای_قبلی'] ?? '') !== '' ? ' (از ' . $fields['قراردادهای_قبلی'] . ')' : '') : '—',
              'پرداخت‌شده' => ($fields['مبلغ_واریزی'] ?? '') !== '' ? $fields['مبلغ_واریزی'] . ' تومان' : '۰',
              'بدهی' => ($fields['مبلغ_بدهی'] ?? '') !== '' ? $fields['مبلغ_بدهی'] . ' تومان' : '۰',
              'اقساط' => ($fields['تعداد_قسط'] ?? '') !== '' ? $fields['تعداد_قسط'] . ' قسطِ ' . $fields['مبلغ_قسط_ماهیانه'] . ' تومانی — از ' . $fields['تاریخ_اولین_قسط'] : '—',
              'نحوه پرداخت' => $fields['نحوه_پرداخت'] ?? '',
          ];
          foreach ($rows as $label => $v): ?>
            <tr><th><?= e($label) ?></th><td><?= trim((string) $v) !== '' ? e((string) $v) : '<span class="text-danger small">ثبت نشده</span>' ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if (!$doc['frozen'] && (int) ($doc['fin']['overpaid'] ?? 0) > 0): ?>
          <div class="small text-info mb-1"><i class="fa-solid fa-circle-info"></i> واریزیِ مشتری <?= fa_money((int) $doc['fin']['paid']) ?> تومان است که از مبلغِ فاکتور بیشتر است؛ در متنِ قرارداد «پرداخت‌شده» برابرِ مبلغِ فاکتور (<?= e((string) ($fields['مبلغ_واریزی'] ?? '')) ?> تومان) درج شد.</div>
        <?php endif; ?>
        <?php if (!$doc['frozen'] && empty($doc['fin']['order'])): ?>
          <div class="small text-warning"><i class="fa-solid fa-circle-info"></i> برای این پیش‌فاکتور هنوز سفارش ثبت نشده؛ مبلغ از پیش‌فاکتور خوانده شد و پرداختی/اقساط خالی است.</div>
        <?php endif; ?>
      </div>

      <?php if ($canEdit): ?>
      <div class="card p-3 mb-3" id="edit-panel">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-pen"></i> تکمیل / ویرایش</h6>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save">
          <div class="row g-2">
            <div class="col-12"><label class="form-label small">تاریخ قرارداد</label><input name="contract_date" class="form-control jalali-date" dir="ltr" autocomplete="off" value="<?= e(to_jalali((string) $contract['contract_date'])) ?>"></div>
            <?php if (($fields['بدهی_یکجا'] ?? '') !== ''): ?>
              <div class="col-6"><label class="form-label small">تاریخ تسویه‌ی بدهی</label><input name="settle_date" class="form-control jalali-date" dir="ltr" autocomplete="off" value="<?= e(($doc['settle_raw'] ?? '') !== '' ? to_jalali((string) $doc['settle_raw']) : '') ?>"></div>
            <?php endif; ?>
            <div class="col-12">
              <div class="border rounded-3 p-2" style="background:#fdfbf5">
                <div class="fw-bold small mb-1"><i class="fa-solid fa-right-left"></i> انتقال از قرارداد / سفارشِ قبلی (بند «الف» ماده ۴)</div>
                <?php if (!empty($manual['transfers_applied'])): ?>
                  <?php foreach (ctr_transfers($manual) as $t): ?>
                    <div class="small py-1 border-bottom"><i class="fa-solid fa-lock text-muted"></i> <b><bdi dir="ltr"><?= e(to_persian_digits($t['label'])) ?></bdi></b> — <?= fa_money($t['amount']) ?> تومان</div>
                  <?php endforeach; ?>
                  <div class="small text-muted mt-1">این انتقال‌ها در صدورِ قبلی انجام شده‌اند و دیگر قابلِ تغییر نیستند.</div>
                <?php elseif ($transferSources): ?>
                  <?php foreach ($transferSources as $src): $sel = $selTransfers[$src['order_id']] ?? null; ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
                      <div class="form-check mb-0 flex-grow-1">
                        <input class="form-check-input" type="checkbox" name="tr_order[]" value="<?= (int) $src['order_id'] ?>" id="tr<?= (int) $src['order_id'] ?>" <?= $sel ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="tr<?= (int) $src['order_id'] ?>">
                          <b><bdi dir="ltr"><?= e(to_persian_digits($src['label'])) ?></bdi></b>
                          <span class="text-muted">— <?= to_jalali($src['date']) ?> | مبلغ <?= fa_money($src['total']) ?> | پرداخت‌شده <b><?= fa_money($src['paid']) ?></b> تومان</span>
                        </label>
                      </div>
                      <input name="tr_amount[<?= (int) $src['order_id'] ?>]" class="form-control form-control-sm" style="max-width:150px" dir="ltr" inputmode="numeric" value="<?= e(number_format((int) ($sel['amount'] ?? $src['paid']))) ?>" title="مبلغِ انتقال (حداکثر مبلغِ پرداخت‌شده)">
                    </div>
                  <?php endforeach; ?>
                  <div class="small text-muted mt-1">با <b>صدورِ</b> این قرارداد، سفارش/قراردادِ انتخاب‌شده <b>لغو</b> می‌شود و مبلغش به‌عنوانِ پرداختِ «انتقال از قرارداد قبلی» روی سفارشِ جدید ثبت و از بدهی کم می‌شود.</div>
                <?php else: ?>
                  <div class="small text-muted">این مشتری سفارش/قراردادِ قبلیِ پرداخت‌شده‌ای در سیستم ندارد.</div>
                <?php endif; ?>
                <div class="mt-2"><label class="form-label small mb-0">مبلغِ دستی (خریدهای قبلی که در سیستم ثبت نشده‌اند)</label><input name="transfer_amount" class="form-control form-control-sm" dir="ltr" inputmode="numeric" value="<?= e(!empty($manual['مبلغ_تبدیل']) ? number_format((int) $manual['مبلغ_تبدیل']) : '') ?>" placeholder="خالی = ندارد"></div>
              </div>
            </div>
            <div class="col-6"><label class="form-label small">سطح پروموشن</label><input name="promotion" class="form-control" value="<?= e((string) ($manual['سطح_پروموشن'] ?? '')) ?>" placeholder="خالی = ندارد"></div>
          </div>
          <div class="small text-muted mt-2">نام پدر، کد ملی، آدرس و کد پستی در پرونده‌ی مشتری ذخیره می‌شوند و دیگر پرسیده نمی‌شوند. مبالغ و اقساط از سفارش خوانده می‌شوند.</div>
          <button class="btn btn-primary btn-sm mt-2"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button>
        </form>
        <?php if ($tplOutdated): ?>
          <form method="post" class="mt-3 border-top pt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="refresh_template">
            <div class="small text-warning mb-1"><i class="fa-solid fa-code-compare"></i> قالبِ قرارداد به نسخه‌ی <?= to_persian_digits((string) $activeTpl['version']) ?> به‌روز شده (این پیش‌نویس: نسخه‌ی <?= to_persian_digits((string) $contract['template_version']) ?>).</div>
            <button class="btn btn-outline-warning btn-sm">به‌روزرسانیِ متن با آخرین قالب</button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($canEditBody): ?>
      <div class="card p-3 mb-3" id="body-editor">
        <div class="d-flex justify-content-between align-items-center">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-file-pen"></i> اصلاحِ متنِ همین قرارداد</h6>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#body-editor-box"><i class="fa-solid fa-pen-to-square"></i> ویرایش متن</button>
        </div>
        <div class="small text-muted mt-2">اگر بندی از این قرارداد اشکال دارد، متنش را همین‌جا اصلاح کنید. این تغییر فقط روی همین قرارداد اثر دارد و «قالب قرارداد» دست‌نخورده می‌ماند.</div>
        <div class="collapse <?= (($_GET['edit_body'] ?? '') === '1') ? 'show' : '' ?>" id="body-editor-box">
          <form method="post" class="mt-2">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save_body">
            <textarea name="template_body" class="form-control form-control-sm" rows="16" dir="rtl" style="font-size:13px;line-height:1.9"><?= e((string) $contract['template_body']) ?></textarea>
            <div class="small text-muted mt-1">
              متغیرها داخلِ «» خودکار پر می‌شوند (مثل «مبلغ_واریزی»)؛ <code>**متن**</code> = پررنگ، <code>## ماده</code> = عنوانِ ماده، <code>[[اگر متغیر]] … [[/اگر]]</code> = بندِ شرطی.
            </div>
            <button class="btn btn-primary btn-sm mt-2"><i class="fa-solid fa-floppy-disk"></i> ذخیره‌ی متن</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-7">
      <!-- ارسال -->
      <div class="card p-3 mb-3" id="send-panel">
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-paper-plane"></i> ارسال برای مشتری</h6>
        <?php if ($status !== 'issued'): ?>
          <div class="text-muted small">ارسال برای مشتری بعد از <b>صدورِ</b> قرارداد فعال می‌شود.</div>
        <?php elseif (!$canSend): ?>
          <div class="text-muted small">اجازه‌ی ارسالِ این قرارداد را ندارید.</div>
        <?php elseif (!$channels && !$ticketReady): ?>
          <div class="alert alert-warning small mb-0">
            برای این مشتری هیچ پیام‌رسانی (واتساپ، تلگرام، ایتا، بله، روبیکا) ثبت نشده است.
            <a href="customer_edit.php?id=<?= (int) $contract['customer_id'] ?>" class="alert-link">ثبتِ پیام‌رسان در پرونده‌ی مشتری</a>
          </div>
        <?php else: ?>
          <div class="mb-2 small text-muted">راهِ ارسال:</div>
          <div class="d-flex flex-wrap gap-2 mb-2">
            <?php foreach ($channels as $i => $c): ?>
              <span class="ch-chip"><input type="radio" name="ch" id="ch<?= $i ?>" value="<?= e($c['key']) ?>" <?= $i === 0 ? 'checked' : '' ?>><label for="ch<?= $i ?>"><?= e($c['label']) ?></label></span>
            <?php endforeach; ?>
            <?php if ($ticketReady): ?>
              <span class="ch-chip"><input type="radio" name="ch" id="chTicket" value="ticket" <?= !$channels ? 'checked' : '' ?>><label for="chTicket"><i class="fa-solid fa-ticket"></i> تیکت در آراد برندینگ</label></span>
            <?php endif; ?>
          </div>
          <?php if ($ticketReady): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="ticket-opts">
              <label class="form-label small mb-0">دپارتمانِ تیکت:</label>
              <input id="ticket-dept" class="form-control form-control-sm" style="max-width:220px" value="<?= e(ctr_ticket_default_department()) ?>">
              <span class="small text-muted">تیکت در سامانه‌ی aradbranding.me برای همین مشتری ثبت می‌شود و لینکِ اسناد داخلِ متنِ تیکت می‌رود.<?= $ticketLive ? '' : ' <b class="text-warning">اتصالِ API هنوز فعال نیست؛ تیکت «آماده‌ی ارسال» می‌ماند.</b>' ?></span>
            </div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-dark btn-sm send-btn" data-docs="contract"><i class="fa-solid fa-file-signature"></i> ارسال قرارداد</button>
            <button type="button" class="btn btn-outline-dark btn-sm send-btn" data-docs="quote"><i class="fa-solid fa-file-invoice"></i> ارسال <?= e($finDocLabel) ?></button>
            <button type="button" class="btn btn-outline-dark btn-sm send-btn" data-docs="services"><i class="fa-solid fa-list-check"></i> ارسال شرح خدمات</button>
            <button type="button" class="btn btn-success btn-sm send-btn" data-docs="contract,quote,services"><i class="fa-solid fa-layer-group"></i> ارسال همه</button>
            <?php if ($ticketReady): ?>
              <button type="button" class="btn btn-outline-primary btn-sm d-none" id="pdf-rebuild" title="فایل‌های PDFِ پیوستِ تیکت دوباره (مثلِ نسخه‌ی چاپی) ساخته می‌شوند و جای فایل‌های قبلی را می‌گیرند"><i class="fa-solid fa-file-pdf"></i> ساختِ دوباره‌ی PDFِ پیوست‌ها</button>
            <?php endif; ?>
          </div>
          <?php
          // آخرین PDFِ پیوستِ هر سند — برای بررسیِ مستقیمِ همان فایلی که به آراد برندینگ می‌رود
          $__pdfs = [];
          try {
              require_once __DIR__ . '/includes/contract_pdf.php';
              if (cpdf_ready($pdo)) {
                  $__q = $pdo->prepare('SELECT p.* FROM contract_pdfs p JOIN (SELECT doc_type, MAX(id) mid FROM contract_pdfs WHERE contract_id = ? GROUP BY doc_type) x ON x.mid = p.id ORDER BY p.doc_type');
                  $__q->execute([$id]);
                  $__pdfs = $__q->fetchAll(PDO::FETCH_ASSOC) ?: [];
              }
          } catch (Throwable $e) {}
          if ($__pdfs): ?>
            <div class="small mt-2 text-muted">
              <i class="fa-solid fa-paperclip"></i> PDFِ فعلیِ پیوستِ تیکت (همین فایل به آراد برندینگ می‌رود):
              <?php foreach ($__pdfs as $__p): ?>
                <a href="doc_pdf.php/<?= e((string) $__p['token']) ?>.pdf" target="_blank" class="ms-2"><?= e((string) $__p['file_name']) ?></a>
                <span class="text-muted">(<?= to_persian_digits(to_jalali(substr((string) $__p['created_at'], 0, 10)) . ' ' . substr((string) $__p['created_at'], 11, 5)) ?>)</span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div id="send-box" class="border rounded-3 p-3 mt-3 d-none" style="background:#fdfbf5">
            <div class="small mb-2" id="send-hint"></div>
            <textarea id="send-msg" class="form-control form-control-sm mb-2" rows="6" readonly dir="rtl"></textarea>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="send-copy"><i class="fa-regular fa-copy"></i> کپی پیام</button>
              <a id="send-open" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> بازکردنِ گفتگو</a>
              <span class="ms-auto"></span>
              <button type="button" class="btn btn-sm btn-success" data-mark="sent"><i class="fa-solid fa-check"></i> ارسال شد</button>
              <button type="button" class="btn btn-sm btn-outline-danger" data-mark="failed"><i class="fa-solid fa-xmark"></i> ارسال نشد</button>
            </div>
          </div>
          <div class="small text-muted mt-3">
            <i class="fa-solid fa-circle-info"></i>
            هر سند یک لینکِ امنِ جداگانه دارد (غیرقابل‌حدس، ۳۰ روز اعتبار، قابل ابطال). وقتی مشتری لینک را باز کند، وضعیت خودکار «مشاهده‌شده» می‌شود.
          </div>
        <?php endif; ?>
      </div>

      <!-- سابقه‌ی ارسال -->
      <div class="card p-3 mb-3" id="send-log">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-solid fa-clock-rotate-left"></i> سابقه‌ی ارسال</h6>
          <?php if ($sends && $canApprove): ?>
            <form method="post" onsubmit="return confirm('همه‌ی لینک‌های ارسال‌شده غیرفعال شوند؟');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="revoke_links">
              <button class="btn btn-sm btn-outline-danger py-0">ابطالِ لینک‌ها</button></form>
          <?php endif; ?>
        </div>
        <?php if (!$sends): ?>
          <div class="text-muted small">هنوز چیزی ارسال نشده. (وضعیت: ارسال‌نشده)</div>
        <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light"><tr><th>زمان</th><th>اسناد</th><th>راهِ ارسال</th><th>کاربر</th><th>وضعیت</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sends as $s):
                $viewed = array_filter($s['views'], static fn($v) => (int) $v['view_count'] > 0);
                $sCode = ($s['status'] === 'sent' && $viewed) ? 'viewed' : $s['status'];
                $ss = $sendStatuses[$sCode] ?? ['label' => $sCode, 'color' => 'secondary'];
                [$net] = explode(':', (string) $s['channel']) + [''];
            ?>
              <tr>
                <td class="text-nowrap"><?= to_jalali(substr((string) $s['created_at'], 0, 10)) ?> <span class="text-muted"><?= to_persian_digits(substr((string) $s['created_at'], 11, 5)) ?></span></td>
                <td><?= e(implode('، ', array_map(static fn($t) => $docTypes[$t]['label'] ?? $t, explode(',', (string) $s['doc_types'])))) ?></td>
                <td><?= $net === 'ticket' ? '<i class="fa-solid fa-ticket"></i> تیکت آراد برندینگ' : e($netNames[$net] ?? $net) ?><?php if ($net === 'ticket' && !empty($s['result_note'])): ?><div class="text-muted" style="font-size:11px"><?= e((string) $s['result_note']) ?></div><?php endif; ?></td>
                <td><?= e((string) ($s['sender_name'] ?? '')) ?></td>
                <td>
                  <span class="badge text-bg-<?= e($ss['color']) ?>"><?= e($ss['label']) ?></span>
                  <?php if ($viewed): ?><div class="text-muted" style="font-size:11px">
                    <?= e(implode('، ', array_map(static fn($v) => ($docTypes[$v['doc_type']]['label'] ?? $v['doc_type']) . ' ' . to_persian_digits((string) $v['view_count']) . ' بار', $viewed))) ?>
                  </div><?php endif; ?>
                  <?php if (!empty($s['result_note'])): ?><div class="text-muted" style="font-size:11px"><?= e((string) $s['result_note']) ?></div><?php endif; ?>
                </td>
                <td class="text-nowrap">
                  <?php if ($canSend && $s['status'] === 'sending'): ?>
                    <button type="button" class="btn btn-sm btn-success py-0 px-1 mark-row" data-id="<?= (int) $s['id'] ?>" data-status="sent" title="ارسال شد"><i class="fa-solid fa-check"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 mark-row" data-id="<?= (int) $s['id'] ?>" data-status="failed" title="ارسال نشد"><i class="fa-solid fa-xmark"></i></button>
                  <?php endif; ?>
                  <?php if ($canSend && in_array($s['status'], ['failed', 'sending'], true)): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 retry-btn" data-id="<?= (int) $s['id'] ?>" data-docs="<?= e((string) $s['doc_types']) ?>" data-channel="<?= e((string) $s['channel']) ?>"><i class="fa-solid fa-rotate-right"></i> ارسال مجدد</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- پیش‌نمایش -->
      <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0"><i class="fa-regular fa-file-lines"></i> متنِ قرارداد</h6>
          <?php if (!$doc['frozen']): ?><span class="small text-muted"><span class="ph-miss" style="background:#fee2e2;color:#b91c1c;border-radius:4px;padding:0 4px">قرمز</span> = اطلاعاتِ ناقص</span><?php endif; ?>
        </div>
        <div class="paper"><?= $doc['html'] ?></div>
      </div>
    </div>
  </div>
</div>

<?php if ($canSend && ($channels || $ticketReady)): ?>
<script>
(function () {
  const csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
  const id = <?= $id ?>;
  let current = null;
  const box = document.getElementById('send-box');
  const msgEl = document.getElementById('send-msg');
  const hint = document.getElementById('send-hint');
  const openA = document.getElementById('send-open');

  async function post(data) {
    const fd = new FormData();
    fd.append('csrf_token', csrf); fd.append('ajax', '1'); fd.append('id', id);
    for (const k in data) {
      if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k + '[]', v)); else fd.append(k, data[k]);
    }
    const r = await fetch('contract_view.php?id=' + id, { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); return true; } catch (e) {
      msgEl.select(); try { return document.execCommand('copy'); } catch (e2) { return false; }
    }
  }
  // ساختِ PDFِ هر سند در مرورگر (برای پیوستِ تیکت) — فقط اگر در «تنظیمات تیکت» فیلدِ پیوست‌ها تعریف شده باشد
  const pdfAttach = <?= json_encode($ticketReady && trim((string) (function_exists('abt_settings') ? (abt_settings($pdo)['field_attachments'] ?? '') : '')) !== '') ?>;
  // PDF دقیقاً مثلِ نسخه‌ی چاپی (سربرگ، تاریخ/شماره، ردیفِ امضا در هر صفحه): assets/js/contract-pdf.js
  function loadLib() {
    return new Promise((ok, fail) => {
      if (window.ctrMakePdf) return ok();
      const s = document.createElement('script');
      s.src = 'assets/js/contract-pdf.js?v=2';
      s.onload = () => ok(); s.onerror = () => fail(new Error('کتابخانه‌ی PDF بارگذاری نشد'));
      document.head.appendChild(s);
    });
  }
  function makePdf(doc) {
    return window.ctrMakePdf('contract_print.php?id=' + id + '&doc=' + encodeURIComponent(doc));
  }
  async function buildPdfs(docs) {
    const tokens = {};
    await loadLib();
    for (const doc of docs) {
      hint.innerHTML = 'در حالِ ساختِ PDFِ «' + doc + '» برای پیوستِ تیکت… (چند ثانیه)';
      box.classList.remove('d-none');
      const blob = await makePdf(doc);
      const fd = new FormData();
      fd.append('csrf_token', csrf); fd.append('ajax', '1'); fd.append('id', id); fd.append('action', 'pdf_upload'); fd.append('doc', doc);
      fd.append('pdf', blob, doc + '.pdf');
      const r = await (await fetch('contract_view.php?id=' + id, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
      if (!r.ok) throw new Error(r.message || 'آپلودِ PDF ناموفق بود');
      tokens[doc] = r.token;
    }
    return tokens;
  }
  async function start(payload) {
    const isTicket = payload.channel === 'ticket';
    if (isTicket && pdfAttach && payload.docs) {
      try {
        const tk = await buildPdfs(payload.docs);
        for (const k in tk) payload['pdf_tokens[' + k + ']'] = tk[k];
      } catch (e) {
        if (!confirm('ساختِ PDF ناموفق بود (' + e.message + ').\nتیکت بدونِ PDF (فقط با لینکِ اسناد) ارسال شود؟')) return;
      }
    }
    const win = isTicket ? null : window.open('', '_blank'); // پیش از درخواست باز می‌شود تا مرورگر آن را مسدود نکند
    const res = await post(Object.assign({ action: 'send_start' }, payload));
    if (!res.ok) { if (win) win.close(); alert(res.message || 'خطا'); return; }
    current = res.send_id;
    msgEl.value = res.message;
    openA.href = res.open_url || '#';
    box.classList.remove('d-none');
    const copied = await copy(res.message);
    if (res.open_url && win) { win.location = res.open_url; } else if (win) { win.close(); }
    if (res.ticket) {
      hint.innerHTML = (res.ticket_ok ? '<b class="text-success">تیکت ثبت شد</b> — ' : '<b class="text-danger">تیکت ثبت نشد</b> — ')
        + (res.ticket_message || '') + '<br>' + res.channel_label
        + '<br>' + (pdfAttach ? (res.pdf_count ? '📎 ' + res.pdf_count + ' فایلِ PDF ساخته و به‌عنوانِ پیوست فرستاده شد.' : '⚠️ فایلِ PDF ساخته نشد؛ فقط لینکِ اسناد در متن رفت.') : '⚠️ پیوست غیرفعال است («نامِ فیلدِ پیوست‌ها» در تنظیمات تیکت خالی است).');
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    hint.innerHTML = res.prefill
      ? 'گفتگوی <b>' + res.channel_label + '</b> با پیامِ آماده باز شد؛ فقط «ارسال» را بزنید. بعد نتیجه را این‌جا ثبت کنید.'
      : 'پیام ' + (copied ? '<b>کپی شد</b>' : 'آماده است') + ' و گفتگوی <b>' + res.channel_label + '</b> باز شد؛ پیام را در گفتگو بچسبانید و بفرستید. بعد نتیجه را این‌جا ثبت کنید.';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  document.querySelectorAll('.send-btn').forEach(b => b.addEventListener('click', () => {
    const ch = document.querySelector('input[name="ch"]:checked');
    if (!ch) { alert('راهِ ارسال را انتخاب کنید.'); return; }
    const deptEl = document.getElementById('ticket-dept');
    start({ docs: b.dataset.docs.split(','), channel: ch.value, department: deptEl ? deptEl.value : '' });
  }));
  // ارسالِ مجدد: PDFها هم از نو ساخته می‌شوند
  document.querySelectorAll('.retry-btn').forEach(b => b.addEventListener('click', () => start({ retry_of: b.dataset.id, docs: (b.dataset.docs || '').split(',').filter(Boolean), channel: b.dataset.channel || '' })));
  // ساختِ دوباره‌ی PDFِ پیوست‌ها (بدونِ ارسالِ تیکت): فایلِ پشتِ لینک‌های قبلی هم با نسخه‌ی تازه جایگزین می‌شود
  const rb = document.getElementById('pdf-rebuild');
  if (rb && pdfAttach) {
    rb.classList.remove('d-none');
    rb.addEventListener('click', async () => {
      rb.disabled = true;
      try {
        await buildPdfs(['contract', 'quote', 'services']);
        hint.innerHTML = '<b class="text-success">PDFِ پیوست‌ها دوباره ساخته شد</b> (مثلِ نسخه‌ی چاپی) و جای فایل‌های قبلی را گرفت.<br>'
          + 'برای این‌که مشتری در آراد برندینگ فایلِ درست را ببیند، در صفحه‌ی سفارش (یا «ارسال تیکت‌ها») تیکتِ قرارداد را از آراد برندینگ حذف و دوباره ارسال کنید.';
      } catch (e) {
        hint.innerHTML = '<b class="text-danger">ساختِ PDF ناموفق بود:</b> ' + e.message;
      }
      box.classList.remove('d-none');
      rb.disabled = false;
    });
  }
  document.getElementById('send-copy').addEventListener('click', async () => { if (await copy(msgEl.value)) hint.innerHTML = 'پیام کپی شد.'; });
  async function mark(sid, status) {
    let note = '';
    if (status === 'failed') note = prompt('علتِ ناموفق‌بودن (اختیاری):', '') || '';
    const res = await post({ action: 'send_mark', send_id: sid, status: status, note: note });
    if (res.ok) location.href = 'contract_view.php?id=' + id + '#send-log'; else alert(res.message || 'خطا');
  }
  box.querySelectorAll('[data-mark]').forEach(b => b.addEventListener('click', () => current && mark(current, b.dataset.mark)));
  document.querySelectorAll('.mark-row').forEach(b => b.addEventListener('click', () => mark(b.dataset.id, b.dataset.status)));
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
