<?php
/**
 * «پیامِ رضایتِ پرداخت» (اعلامِ واریز توسطِ مشتری به واحد قرارداد):
 *   ۱) کارشناس متنِ آماده (با نام، کد ملی و مبلغِ پرشده) را کپی و برای مشتری می‌فرستد
 *   ۲) مشتری پیام را به شماره‌ی واحد قرارداد می‌فرستد و اسکرین‌شاتش را به کارشناس می‌دهد
 *   ۳) کارشناس اسکرین‌شات را (هم‌زمان با ثبتِ فیش یا بعداً) برای همان سفارش بارگذاری می‌کند
 *   ۴) مالی بررسی و تأیید می‌کند (سبز) — تا انجام نشده برای کارشناس نارنجی می‌ماند
 * جدول: sales_order_consents (یک ردیف برای هر سفارش)
 */
require_once __DIR__ . '/orders_functions.php';

const CONSENT_CONTRACT_PHONE = '09916626389';

if (!function_exists('consent_ready')) {
    function consent_ready(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        $flag = __DIR__ . '/../storage/.order_consents_v1';
        if (is_file($flag)) return $ok = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sales_order_consents (
                order_id INT UNSIGNED NOT NULL,
                file_path VARCHAR(300) DEFAULT NULL, original_name VARCHAR(250) DEFAULT NULL, mime VARCHAR(80) DEFAULT NULL, size_bytes INT UNSIGNED DEFAULT NULL,
                uploaded_by INT UNSIGNED DEFAULT NULL, uploaded_at DATETIME DEFAULT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'missing',
                decided_by INT UNSIGNED DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decision_note VARCHAR(500) DEFAULT NULL,
                PRIMARY KEY (order_id), KEY idx_soc_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('consent_ready: ' . $e->getMessage());
            return $ok = false;
        }
        if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
        @file_put_contents($flag, (string) time());
        return $ok = true;
    }
}

if (!function_exists('consent_text')) {
    /** متنِ کاملِ قابلِ کپی؛ هر مقدارِ خالی با جای‌خالیِ [..] می‌ماند تا کارشناس متوجه شود */
    function consent_text(string $fullName, string $nationalId, int $amount, string $idLabel = 'کد ملی'): string
    {
        $name = trim($fullName) !== '' ? trim($fullName) : '[نام و نام خانوادگی]';
        $nid = trim($nationalId) !== '' ? trim($nationalId) : '[' . $idLabel . ']';
        $amt = $amount > 0 ? number_format($amount) : '[مبلغ]';
        return 'لطفاً پیام زیر را به شماره‌ی ' . CONSENT_CONTRACT_PHONE . ' (واحد قرارداد آراد برندینگ) ارسال کنید و پس از ارسال، یک اسکرین‌شات از آن برای من بفرستید.'
            . "\n\n"
            . 'اینجانب ' . $name . ' با ' . $idLabel . ' ' . $nid . ' اعلام می‌نمایم مبلغ ' . $amt . ' تومان بابت خرید خدمات شرکت آراد برندینگ با رضایت کامل و آگاهی از موضوع پرداخت، به حساب شرکت مدیریت فضای توسعه گستر صادرات آراد واریز نموده‌ام و متعهد به تکمیل و امضای مدارک و قراردادهای مربوطه هستم.';
    }
}

if (!function_exists('consent_get')) {
    function consent_get(PDO $pdo, int $orderId): array
    {
        $row = null;
        if (consent_ready($pdo)) {
            $st = $pdo->prepare('SELECT c.*, u.full_name AS uploader_name, d.full_name AS decider_name FROM sales_order_consents c
                LEFT JOIN users u ON u.id = c.uploaded_by LEFT JOIN users d ON d.id = c.decided_by WHERE c.order_id = ?');
            $st->execute([$orderId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $row = $row ?: ['order_id' => $orderId, 'status' => 'missing', 'file_path' => null];
        if (empty($row['file_path'])) $row['status'] = 'missing';
        return $row;
    }
}

if (!function_exists('consent_status_meta')) {
    /** [برچسب، رنگِ bootstrap، آیکن] — نارنجی = کارشناس باید اقدام کند، سبز = تأییدِ مالی */
    function consent_status_meta(string $status): array
    {
        return [
            'missing'  => ['اسکرین‌شاتِ پیامِ رضایت بارگذاری نشده — باید ارسال کنید', 'warning', 'fa-triangle-exclamation'],
            'pending'  => ['اسکرین‌شات ارسال شد — در انتظارِ بررسیِ مالی', 'info', 'fa-hourglass-half'],
            'approved' => ['پیامِ رضایت تأیید شد', 'success', 'fa-circle-check'],
            'rejected' => ['اسکرین‌شات رد شد — دوباره بارگذاری کنید', 'danger', 'fa-circle-xmark'],
        ][$status] ?? [$status, 'secondary', 'fa-circle'];
    }
}

if (!function_exists('consent_can_upload')) {
    function consent_can_upload(PDO $pdo, array $user, array $order): bool
    {
        if (is_super_admin($user) || user_can('finance_orders_decide', $user)) return true;
        $uid = (int) $user['id'];
        if ((int) $order['seller_user_id'] === $uid || (int) ($order['owner_user_id'] ?? 0) === $uid) return true;
        return function_exists('leader_supervises_owner') && leader_supervises_owner($pdo, $user, (int) $order['seller_user_id']);
    }
}

if (!function_exists('consent_store')) {
    /** بارگذاری/جایگزینیِ اسکرین‌شات (تصویر یا PDF، حداکثر ۸ مگابایت — همان قواعدِ فیش) → وضعیت: در انتظارِ بررسیِ مالی */
    function consent_store(PDO $pdo, array $order, array $file, int $userId): array
    {
        if (!consent_ready($pdo)) return ['ok' => false, 'message' => 'ماژول آماده نیست.'];
        $files = orders_normalize_files($file);
        if (!$files) return ['ok' => false, 'message' => 'تصویرِ اسکرین‌شات را انتخاب کنید.'];
        $files = [$files[0]];
        if ($err = orders_validate_files($files)) return ['ok' => false, 'message' => implode(' ', $err)];
        $f = $files[0];
        $dir = orders_upload_dir();
        $sub = 'consents/' . date('Y/m');
        if (!is_dir($dir . '/' . $sub)) @mkdir($dir . '/' . $sub, 0755, true);
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        $rel = $sub . '/c' . (int) $order['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $rel)) return ['ok' => false, 'message' => 'ذخیره‌ی فایل انجام نشد.'];
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
        $old = consent_get($pdo, (int) $order['id']);
        $pdo->prepare("INSERT INTO sales_order_consents (order_id, file_path, original_name, mime, size_bytes, uploaded_by, uploaded_at, status, decided_by, decided_at, decision_note)
            VALUES (?,?,?,?,?,?,NOW(),'pending',NULL,NULL,NULL)
            ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), original_name = VALUES(original_name), mime = VALUES(mime), size_bytes = VALUES(size_bytes),
              uploaded_by = VALUES(uploaded_by), uploaded_at = VALUES(uploaded_at), status = 'pending', decided_by = NULL, decided_at = NULL, decision_note = NULL")
            ->execute([(int) $order['id'], $rel, mb_substr((string) $f['name'], 0, 250), $mime, (int) $f['size'], $userId]);
        if (!empty($old['file_path'])) @unlink($dir . '/' . $old['file_path']);
        orders_add_history($pdo, (int) $order['id'], $userId, 'consent_uploaded', null, null, 'اسکرین‌شاتِ پیامِ رضایتِ پرداختِ مشتری بارگذاری شد (در انتظارِ بررسیِ مالی).');
        return ['ok' => true, 'message' => 'اسکرین‌شات ثبت شد و برای بررسی به مالی رفت.'];
    }
}

if (!function_exists('consent_decide')) {
    function consent_decide(PDO $pdo, array $order, bool $approve, int $userId, string $note = ''): array
    {
        $c = consent_get($pdo, (int) $order['id']);
        if ($c['status'] === 'missing') return ['ok' => false, 'message' => 'هنوز اسکرین‌شاتی بارگذاری نشده.'];
        if (!$approve && mb_strlen(trim($note)) < 3) return ['ok' => false, 'message' => 'دلیلِ رد را بنویسید.'];
        $pdo->prepare('UPDATE sales_order_consents SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE order_id = ?')
            ->execute([$approve ? 'approved' : 'rejected', $userId, $note !== '' ? mb_substr($note, 0, 500) : null, (int) $order['id']]);
        orders_add_history($pdo, (int) $order['id'], $userId, $approve ? 'consent_approved' : 'consent_rejected', null, null,
            $approve ? 'پیامِ رضایتِ پرداختِ مشتری توسطِ مالی تأیید شد.' : 'اسکرین‌شاتِ پیامِ رضایت رد شد: ' . $note);
        if (!$approve && function_exists('orders_notify')) {
            try { orders_notify($pdo, $userId, (int) $order['seller_user_id'], 'اسکرین‌شاتِ پیامِ رضایتِ مشتری «' . ($order['customer_name'] ?? '') . '» رد شد: ' . $note . ' — لطفاً دوباره بارگذاری کنید.'); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'message' => $approve ? 'پیامِ رضایت تأیید شد.' : 'اسکرین‌شات رد شد و به کارشناس اطلاع داده شد.'];
    }
}

if (!function_exists('consent_customer_identity')) {
    /** نام و کد ملیِ مشتری برای متن (از مدارکِ هویتی) */
    function consent_customer_identity(PDO $pdo, int $customerId, string $fallbackName = ''): array
    {
        $name = $fallbackName;
        $nid = '';
        $label = 'کد ملی';
        try {
            $st = $pdo->prepare('SELECT full_name FROM customers WHERE id = ?');
            $st->execute([$customerId]);
            $name = (string) ($st->fetchColumn() ?: $fallbackName);
            if (function_exists('kyc_get')) {
                $k = kyc_get($pdo, $customerId);
                $nid = !empty($k['has_national_id']) ? (string) $k['national_id'] : '';
                $label = (string) ($k['id_label'] ?? 'کد ملی');
            }
        } catch (Throwable $e) {}
        return ['name' => $name, 'national_id' => $nid, 'id_label' => $label];
    }
}

if (!function_exists('consent_copy_button')) {
    /**
     * دکمه‌ی «کپی متنِ پیامِ رضایت». اگر $amountInputId داده شود، مبلغ هنگامِ کپی از همان فیلد خوانده می‌شود
     * (مثلاً فیلدِ مبلغِ پرداختی در فرمِ ثبتِ فیش)؛ نام و کد ملی هم اگر فیلدشان در صفحه باشد از فیلد خوانده می‌شود.
     */
    function consent_copy_button(string $name, string $nationalId, int $amount, string $amountInputId = '', string $nidInputId = '', string $btnClass = 'btn btn-sm btn-outline-success', string $idLabel = 'کد ملی'): string
    {
        static $js = false;
        $id = 'cc' . bin2hex(random_bytes(4));
        $h = '<button type="button" class="' . e($btnClass) . '" id="' . $id . '" data-consent-copy'
            . ' data-name="' . e($name) . '" data-nid="' . e($nationalId) . '" data-idlabel="' . e($idLabel) . '" data-amount="' . (int) $amount . '"'
            . ' data-amount-input="' . e($amountInputId) . '" data-nid-input="' . e($nidInputId) . '"'
            . ' data-phone="' . e(CONSENT_CONTRACT_PHONE) . '">'
            . '<i class="fa-regular fa-copy"></i> کپیِ متنِ پیامِ رضایتِ پرداخت برای مشتری</button>';
        if (!$js) {
            $js = true;
            $h .= <<<'HTML'
<script>
(function () {
  function fa2en(s) { return String(s || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }); }
  function money(v) { var n = parseInt(fa2en(v).replace(/[^0-9]/g, ''), 10); return n > 0 ? n.toLocaleString('en-US') : ''; }
  function build(b) {
    var name = (b.dataset.name || '').trim() || '[نام و نام خانوادگی]';
    var nid = (b.dataset.nid || '').trim();
    if (b.dataset.nidInput) { var ni = document.getElementById(b.dataset.nidInput) || document.querySelector('[name="' + b.dataset.nidInput + '"]'); if (ni && ni.value.trim()) nid = fa2en(ni.value.trim()); }
    // نوعِ مدرک (اتباع: کد فراگیر / پاسپورت) — از برچسبِ پرونده، یا از انتخابِ همان فرم
    var idl = b.dataset.idlabel || 'کد ملی';
    if (b.dataset.nidInput) { var ts = document.querySelector('select[name="id_type"]'); if (ts) idl = ({national: 'کد ملی', fida: 'کد فراگیر اتباع', passport: 'شماره پاسپورت'})[ts.value] || idl; }
    nid = nid || '[' + idl + ']';
    var amt = parseInt(b.dataset.amount || '0', 10) > 0 ? parseInt(b.dataset.amount, 10).toLocaleString('en-US') : '';
    if (b.dataset.amountInput) { var ai = document.getElementById(b.dataset.amountInput) || document.querySelector('[name="' + b.dataset.amountInput + '"]'); if (ai && money(ai.value)) amt = money(ai.value); }
    amt = amt || '[مبلغ]';
    return 'لطفاً پیام زیر را به شماره‌ی ' + b.dataset.phone + ' (واحد قرارداد آراد برندینگ) ارسال کنید و پس از ارسال، یک اسکرین‌شات از آن برای من بفرستید.\n\n'
      + 'اینجانب ' + name + ' با ' + idl + ' ' + nid + ' اعلام می‌نمایم مبلغ ' + amt + ' تومان بابت خرید خدمات شرکت آراد برندینگ با رضایت کامل و آگاهی از موضوع پرداخت، به حساب شرکت مدیریت فضای توسعه گستر صادرات آراد واریز نموده‌ام و متعهد به تکمیل و امضای مدارک و قراردادهای مربوطه هستم.';
  }
  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (ok, fail) {
      var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
      try { document.execCommand('copy') ? ok() : fail(); } catch (e) { fail(e); } document.body.removeChild(t);
    });
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-consent-copy]'); if (!b) return;
    var text = build(b);
    if (text.indexOf('[') !== -1 && !confirm('بعضی اطلاعات (نام، کد ملی یا مبلغ) هنوز خالی است و در متن با [ ] مانده. همین‌طور کپی شود؟')) return;
    var old = b.innerHTML;
    copy(text).then(function () { b.innerHTML = '<i class="fa-solid fa-check"></i> کپی شد — برای مشتری بفرستید'; setTimeout(function () { b.innerHTML = old; }, 2500); })
      .catch(function () { prompt('متن را کپی کنید:', text); });
  });
})();
</script>
HTML;
        }
        return $h;
    }
}

if (!function_exists('consent_render_card')) {
    /** کارتِ «پیامِ رضایتِ پرداخت» در صفحه‌ی سفارش */
    function consent_render_card(PDO $pdo, array $order, array $user): void
    {
        if (!consent_ready($pdo)) return;
        $c = consent_get($pdo, (int) $order['id']);
        [$lbl, $color, $icon] = consent_status_meta($c['status']);
        $idn = consent_customer_identity($pdo, (int) $order['customer_id'], (string) ($order['customer_name'] ?? ''));
        $amount = (int) ($order['paid_amount'] ?? 0);
        $canUp = consent_can_upload($pdo, $user, $order);
        $canDecide = is_super_admin($user) || user_can('finance_orders_decide', $user);
        $isImg = strpos((string) ($c['mime'] ?? ''), 'image/') === 0;
        $border = ['warning' => '#f59e0b', 'info' => '#0ea5e9', 'success' => '#16a34a', 'danger' => '#dc2626'][$color] ?? '#94a3b8';
        ?>
        <div class="card p-3 mb-3" id="order-consent" style="border:2px solid <?= $border ?>;border-radius:16px">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h6 class="fw-bold mb-0"><i class="fa-solid fa-file-signature"></i> پیامِ رضایتِ پرداختِ مشتری</h6>
            <span class="badge text-bg-<?= $color ?>"><i class="fa-solid <?= $icon ?>"></i> <?= e($lbl) ?></span>
          </div>
          <div class="small text-muted mb-2">متن را کپی و برای مشتری بفرستید؛ مشتری آن را به <span dir="ltr"><?= e(CONSENT_CONTRACT_PHONE) ?></span> می‌فرستد و اسکرین‌شاتش را به شما می‌دهد. اسکرین‌شات را این‌جا بارگذاری کنید تا مالی تأیید کند و سهمِ عملکردِ شما پرداخت شود.</div>
          <pre class="small p-2 rounded-3 mb-2" style="white-space:pre-wrap;background:#f8fafc;border:1px dashed #cbd5e1;font-family:inherit"><?= e(consent_text($idn['name'], $idn['national_id'], $amount, $idn['id_label'] ?? 'کد ملی')) ?></pre>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= consent_copy_button($idn['name'], $idn['national_id'], $amount, '', '', 'btn btn-sm btn-outline-success', $idn['id_label'] ?? 'کد ملی') ?>
            <?php if ($idn['national_id'] === ''): ?><span class="small text-warning"><i class="fa-solid fa-triangle-exclamation"></i> کد ملیِ مشتری در مدارکِ هویتی ثبت نشده.</span><?php endif; ?>
          </div>
          <?php if (!empty($c['file_path'])): ?>
            <div class="d-flex gap-3 align-items-start mt-3 flex-wrap">
              <a href="order_consent.php?order_id=<?= (int) $order['id'] ?>" target="_blank" class="border rounded-3 overflow-hidden d-block bg-light text-center" style="width:120px;height:120px">
                <?php if ($isImg): ?><img src="order_consent.php?order_id=<?= (int) $order['id'] ?>" alt="اسکرین‌شات" style="width:100%;height:100%;object-fit:cover"><?php else: ?><div class="pt-4"><i class="fa-solid fa-file-pdf fs-2 text-danger"></i><div class="small">مشاهده</div></div><?php endif; ?>
              </a>
              <div class="small">
                <div>بارگذاری: <?= e((string) ($c['uploader_name'] ?? '—')) ?> — <?= to_jalali(substr((string) $c['uploaded_at'], 0, 10)) ?></div>
                <?php if (!empty($c['decided_at'])): ?><div>بررسیِ مالی: <?= e((string) ($c['decider_name'] ?? '—')) ?> — <?= to_jalali(substr((string) $c['decided_at'], 0, 10)) ?></div><?php endif; ?>
                <?php if (!empty($c['decision_note'])): ?><div class="text-danger">دلیل: <?= e((string) $c['decision_note']) ?></div><?php endif; ?>
                <?php if ($canDecide && $c['status'] !== 'approved'): ?>
                  <div class="d-flex gap-2 mt-2 flex-wrap">
                    <form method="post" action="order_consent.php"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="approve">
                      <button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i> تأییدِ پیامِ رضایت</button></form>
                    <form method="post" action="order_consent.php" onsubmit="var r=prompt('دلیلِ رد:'); if(!r) return false; this.note.value=r; return true;"><?= csrf_field() ?>
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="note">
                      <button class="btn btn-sm btn-outline-danger">رد</button></form>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($canUp && $c['status'] !== 'approved'): ?>
            <form method="post" action="order_consent.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mt-3"><?= csrf_field() ?>
              <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="upload">
              <input type="file" name="consent" class="form-control form-control-sm" style="max-width:280px" accept="image/jpeg,image/png,image/webp,application/pdf" required>
              <button class="btn btn-sm btn-<?= $color === 'warning' || $color === 'danger' ? 'warning' : 'outline-primary' ?>"><i class="fa-solid fa-upload"></i> <?= empty($c['file_path']) ? 'ارسالِ اسکرین‌شات به مالی' : 'جایگزینیِ اسکرین‌شات' ?></button>
            </form>
          <?php endif; ?>
        </div>
        <?php
    }
}
