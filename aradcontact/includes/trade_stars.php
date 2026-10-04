<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  شارژِ استارزِ «سامانه توسعه تجارت» (aradbranding.app) پس از تأییدِ مالی
 * ═══════════════════════════════════════════════════════════════════════
 *  وقتی خدمتِ «سامانه توسعه تجارت» فروخته می‌شود، با تأییدِ هر پول (پیش‌پرداخت یا قسط) سهمِ همین خدمت از آن پول
 *  به‌صورتِ استارز در کیف پولِ مشتری در aradbranding.app شارژ می‌شود.
 *
 *  مبلغِ خدمت    = مبلغِ قلم در سفارش، بعد از تخفیف و بدونِ مالیات (مثلاً ۴۰ میلیون)
 *  سهمِ هر پول   = پول × (مبلغِ خدمت ÷ جمعِ کلِ فاکتور با مالیات)
 *                 مثال: خدمتِ ۴۰ میلیونی، مالیات ۱۰٪، فاکتور ۴۴ میلیون؛ قسطِ ۲۲ میلیونی ← ۲۰ میلیون شارژ
 *                 در فاکتورِ چندخدمتی هر خدمت به نسبتِ مبلغِ خودش از هر پول سهم می‌برد.
 *  تجمعی        : هدف = min(مبلغِ خدمت، کلِ پولِ تأییدشده × سهم)؛ هر بار فقط «هدف − شارژشده‌ی قبلی» شارژ می‌شود.
 *                 با تسویه‌ی کامل، دقیقاً همان مبلغِ خدمت شارژ شده است (بدونِ خطای گرد کردن).
 *  استارز       : نرخ را خودِ aradbranding.app در لحظه‌ی شارژ حساب می‌کند (اگر گران شود، استارزِ کمتری می‌دهد).
 *  حساب         : همه‌ی موبایل‌های مشتری (پروفایلِ ۳۶۰) جست‌وجو می‌شود؛ اگر حساب نداشت ساخته می‌شود.
 *  تیکت         : حسابِ جدید ← اطلاعاتِ ورود + مقدارِ شارژ؛ حسابِ موجود ← فقط مقدارِ شارژ.
 *  تکرار        : هر شارژ external_id یکتا دارد؛ ارسالِ دوباره (قطعی/تلاشِ مجدد) شارژِ تکراری نمی‌سازد.
 *
 *  API در aradbranding.app (Authorization: Bearer <token>):
 *    POST {base}/api/integrations/arad-contact/users/lookup   {"phones":[...]}
 *    POST {base}/api/integrations/arad-contact/users          {"full_name","phone","external_id"}
 *    GET  {base}/api/integrations/arad-contact/stars/rate
 *    POST {base}/api/integrations/arad-contact/stars/credit   {"user_id","amount_toman","external_id","note"}
 */

function ts_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.trade_stars_v1';
    if (is_file($flag)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ts_accounts (
            person_key INT UNSIGNED NOT NULL PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            remote_user_id VARCHAR(60) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            created_by_us TINYINT(1) NOT NULL DEFAULT 0,
            username VARCHAR(120) DEFAULT NULL,
            password VARCHAR(120) DEFAULT NULL,
            login_url VARCHAR(300) DEFAULT NULL,
            welcome_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ts_credits (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            external_id VARCHAR(100) NOT NULL,
            target_cumulative BIGINT UNSIGNED NOT NULL,
            amount_toman BIGINT UNSIGNED NOT NULL,
            paid_basis BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            stars DECIMAL(16,2) DEFAULT NULL,
            toman_per_star DECIMAL(16,2) DEFAULT NULL,
            balance DECIMAL(16,2) DEFAULT NULL,
            remote_user_id VARCHAR(60) DEFAULT NULL,
            remote_tx VARCHAR(100) DEFAULT NULL,
            new_account TINYINT(1) NOT NULL DEFAULT 0,
            ticket_id INT UNSIGNED DEFAULT NULL,
            last_error VARCHAR(500) DEFAULT NULL,
            response_raw TEXT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_tsc_ext (external_id), KEY idx_tsc_order (order_id), KEY idx_tsc_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ts_ready: ' . $e->getMessage());
        return $ok = false;
    }
    if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
    @file_put_contents($flag, (string) time());
    return $ok = true;
}

function ts_default_new_body(): string
{
    return "«نام_مشتری» عزیز، سلام\n\n"
        . "بابتِ خریدِ «سامانه توسعه تجارت» (فاکتور «شماره_فاکتور») برای شما در سامانه‌ی توسعه تجارتِ آراد برندینگ حسابِ کاربری ساخته شد و کیف پولِ استارزِ شما شارژ شد.\n\n"
        . "مبلغِ شارژ: «مبلغ_شارژ» تومان\n"
        . "تعدادِ استارز: «تعداد_استارز» ستاره\n"
        . "موجودیِ فعلیِ کیف پول: «موجودی» ستاره\n\n"
        . "اطلاعاتِ ورود:\n"
        . "آدرس: «آدرس_ورود»\n"
        . "نام کاربری: «نام_کاربری»\n"
        . "رمز عبور: «رمز_عبور»\n\n"
        . "لطفاً پس از اولین ورود رمز عبور را تغییر دهید. از این پس می‌توانید از خدماتِ سامانه‌ی توسعه تجارت بهره‌مند شوید.";
}

function ts_default_topup_body(): string
{
    return "«نام_مشتری» عزیز، سلام\n\n"
        . "بابتِ «سامانه توسعه تجارت» (فاکتور «شماره_فاکتور») کیف پولِ استارزِ حسابِ شما در سامانه‌ی توسعه تجارتِ آراد برندینگ شارژ شد.\n\n"
        . "مبلغِ شارژ: «مبلغ_شارژ» تومان\n"
        . "تعدادِ استارز: «تعداد_استارز» ستاره\n"
        . "موجودیِ فعلیِ کیف پول: «موجودی» ستاره\n\n"
        . "آدرس: «آدرس_ورود»\n"
        . "می‌توانید با همان حسابِ قبلیِ خود از خدماتِ سامانه بهره‌مند شوید.";
}

/** تنظیمات (در جدولِ تنظیماتِ تیکتِ آراد برندینگ نگه‌داری می‌شود) */
function ts_settings(PDO $pdo): array
{
    $s = function_exists('abt_settings') ? abt_settings($pdo) : [];
    $base = rtrim(trim((string) ($s['ts_base_url'] ?? '')) ?: 'https://aradbranding.app', '/');
    return [
        'enabled'      => (string) ($s['ts_enabled'] ?? '0') === '1',
        'base_url'     => $base,
        'token'        => (string) ($s['ts_token'] ?? ''),
        'login_url'    => trim((string) ($s['ts_login_url'] ?? '')) ?: $base,
        'service_ids'  => array_values(array_filter(array_map('intval', json_decode((string) ($s['ts_service_ids'] ?? ''), true) ?: []))),
        'department'   => trim((string) ($s['ts_department'] ?? '')),
        'subject_new'  => trim((string) ($s['ts_subject_new'] ?? '')) ?: 'حسابِ سامانه‌ی توسعه تجارت و شارژِ استارز',
        'body_new'     => trim((string) ($s['ts_body_new'] ?? '')) ?: ts_default_new_body(),
        'subject_topup' => trim((string) ($s['ts_subject_topup'] ?? '')) ?: 'شارژِ کیف پولِ استارز — سامانه‌ی توسعه تجارت',
        'body_topup'   => trim((string) ($s['ts_body_topup'] ?? '')) ?: ts_default_topup_body(),
    ];
}

function ts_active(array $s): bool
{
    return $s['enabled'] && $s['token'] !== '' && preg_match('#^https?://#i', $s['base_url']);
}

/** این قلمِ سفارش «سامانه توسعه تجارت» است؟ (خدماتِ انتخاب‌شده در تنظیمات، وگرنه از روی عنوان) */
function ts_is_trade_item(array $s, array $item): bool
{
    if ($s['service_ids']) return in_array((int) ($item['service_id'] ?? 0), $s['service_ids'], true);
    $t = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], (string) ($item['title'] ?? ''));
    return mb_strpos($t, 'توسعه تجارت') !== false;
}

function ts_http(array $s, string $method, string $path, ?array $body = null): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'json' => null, 'raw' => '', 'error' => 'cURL فعال نیست.'];
    $ch = curl_init($s['base_url'] . $path);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $s['token']];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $json = json_decode($raw, true);
    $ok = $err === '' && $code >= 200 && $code < 300 && is_array($json) && ($json['success'] ?? true) !== false;
    $msg = $err !== '' ? 'خطای اتصال: ' . $err
        : (is_array($json) && !empty($json['message']) ? (string) $json['message'] : ($ok ? '' : 'پاسخِ نامعتبر (کد ' . $code . ')'));
    return ['ok' => $ok, 'code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => mb_substr($raw, 0, 4000), 'error' => $msg];
}

/** تستِ اتصال: خواندنِ نرخِ استارز */
function ts_test(PDO $pdo): array
{
    $s = ts_settings($pdo);
    if ($s['token'] === '') return ['ok' => false, 'message' => 'توکن وارد نشده.'];
    $r = ts_http($s, 'GET', '/api/integrations/arad-contact/stars/rate');
    if (!$r['ok']) return ['ok' => false, 'message' => 'اتصال ناموفق — ' . $r['error']];
    $rate = (float) ($r['json']['toman_per_star'] ?? 0);
    return ['ok' => $rate > 0, 'message' => $rate > 0 ? 'اتصال برقرار است — نرخِ فعلی: هر ' . to_persian_digits(number_format($rate)) . ' تومان = ۱ استارز' : 'پاسخ نرخِ استارز ندارد.'];
}

/**
 * سهمِ هر قلمِ «سامانه توسعه تجارت» از پول‌های تأییدشده‌ی سفارش:
 * [item_id => ['item' => row, 'net' => مبلغِ خدمت بعد از تخفیف بدونِ مالیات, 'target' => باید تا الان شارژ شده باشد, 'paid' => پولِ تأییدشده]]
 */
function ts_order_targets(PDO $pdo, array $s, array $order): array
{
    $st = $pdo->prepare('SELECT * FROM sales_order_items WHERE order_id = ? ORDER BY id');
    $st->execute([(int) $order['id']]);
    $items = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC) ?: [], static fn($it) => ts_is_trade_item($s, $it)));
    if (!$items) return [];
    $total = (int) $order['total_amount'];                                   // جمعِ کلِ فاکتور (با مالیات)
    $subtotal = (int) $order['subtotal'];                                    // جمعِ قلم‌ها (قبل از تخفیف)
    $netAll = max(0, $total - (int) $order['tax_amount']);                   // بعد از تخفیف، بدونِ مالیات
    $p = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM sales_order_payments WHERE order_id = ? AND status = 'confirmed'");
    $p->execute([(int) $order['id']]);
    $paid = (int) $p->fetchColumn();
    $out = [];
    foreach ($items as $it) {
        // مبلغِ این خدمت بعد از تخفیفِ فاکتور (تخفیف به نسبتِ مبلغِ هر قلم پخش می‌شود)، بدونِ مالیات
        $net = $subtotal > 0 ? (int) floor((int) $it['amount'] * $netAll / $subtotal) : (int) $it['amount'];
        $target = $total > 0 ? ($paid >= $total ? $net : (int) floor($paid * $net / $total)) : 0;
        $out[(int) $it['id']] = ['item' => $it, 'net' => $net, 'target' => min($net, max(0, $target)), 'paid' => $paid];
    }
    return $out;
}

/** حسابِ این شخص در aradbranding.app: پیدا (با همه‌ی موبایل‌ها) یا ساختن */
function ts_ensure_account(PDO $pdo, array $s, array $order): array
{
    $cid = (int) $order['customer_id'];
    $pk = function_exists('abt_person_key') ? abt_person_key($pdo, $cid) : $cid;
    $st = $pdo->prepare('SELECT * FROM ts_accounts WHERE person_key = ?');
    $st->execute([$pk]);
    if ($acc = $st->fetch(PDO::FETCH_ASSOC)) return ['ok' => true, 'account' => $acc];
    $phones = function_exists('abt_person_mobiles') ? abt_person_mobiles($pdo, $order) : [];
    if (!$phones) return ['ok' => false, 'message' => 'مشتری موبایلِ معتبری (۰۹…) ندارد.'];
    $now = date('Y-m-d H:i:s');
    $r = ts_http($s, 'POST', '/api/integrations/arad-contact/users/lookup', ['phones' => $phones]);
    if (!$r['ok']) return ['ok' => false, 'message' => 'جست‌وجوی حساب ناموفق — ' . $r['error']];
    if (!empty($r['json']['found']) && !empty($r['json']['user_id'])) {
        $pdo->prepare('INSERT INTO ts_accounts (person_key, customer_id, remote_user_id, phone, created_by_us, created_at, updated_at) VALUES (?,?,?,?,0,?,?)')
            ->execute([$pk, $cid, (string) $r['json']['user_id'], (string) ($r['json']['phone'] ?? $phones[0]), $now, $now]);
    } else {
        $c = ts_http($s, 'POST', '/api/integrations/arad-contact/users', [
            'full_name' => (string) ($order['customer_name'] ?? ''), 'phone' => $phones[0], 'external_id' => 'arad-contact-customer-' . $pk,
        ]);
        $j = $c['json'] ?? [];
        if (!$c['ok'] || empty($j['user_id'])) return ['ok' => false, 'message' => 'ساختِ حساب ناموفق — ' . ($c['error'] ?: 'پاسخ شناسه‌ی کاربر ندارد')];
        $pdo->prepare('INSERT INTO ts_accounts (person_key, customer_id, remote_user_id, phone, created_by_us, username, password, login_url, created_at, updated_at) VALUES (?,?,?,?,1,?,?,?,?,?)')
            ->execute([$pk, $cid, (string) $j['user_id'], $phones[0], (string) ($j['username'] ?? $phones[0]), (string) ($j['password'] ?? ''),
                (string) ($j['login_url'] ?? $s['login_url']), $now, $now]);
    }
    $st->execute([$pk]);
    return ['ok' => true, 'account' => $st->fetch(PDO::FETCH_ASSOC)];
}

function ts_render(string $tpl, array $vars): string
{
    return strtr($tpl, $vars);
}

/** تیکتِ شارژ (و اطلاعاتِ حسابِ جدید) برای مشتری — از همان مسیرِ تیکت‌های آراد برندینگ */
function ts_ticket(PDO $pdo, array $s, array $order, array $credit, array $acc, int $userId): ?int
{
    if (!function_exists('abt_ready') || !abt_ready($pdo)) return null;
    $isNew = (int) $acc['created_by_us'] === 1 && (int) $acc['welcome_sent'] === 0;
    $vars = [
        '«نام_مشتری»' => (string) ($order['customer_name'] ?? ''),
        '«شماره_فاکتور»' => to_persian_digits((string) ($order['order_number'] ?? $order['id'])),
        '«مبلغ_شارژ»' => to_persian_digits(number_format((int) $credit['amount_toman'])),
        '«تعداد_استارز»' => to_persian_digits(rtrim(rtrim(number_format((float) $credit['stars'], 2, '.', ','), '0'), '.')),
        '«نرخ_استارز»' => to_persian_digits(number_format((float) $credit['toman_per_star'])),
        '«موجودی»' => $credit['balance'] !== null ? to_persian_digits(rtrim(rtrim(number_format((float) $credit['balance'], 2, '.', ','), '0'), '.')) : '—',
        '«آدرس_ورود»' => (string) ($acc['login_url'] ?: $s['login_url']),
        '«نام_کاربری»' => (string) ($acc['username'] ?? ''),
        '«رمز_عبور»' => (string) ($acc['password'] ?? ''),
    ];
    $subject = mb_substr(ts_render($isNew ? $s['subject_new'] : $s['subject_topup'], $vars), 0, 250);
    $message = ts_render($isNew ? $s['body_new'] : $s['body_topup'], $vars);
    $now = date('Y-m-d H:i:s');
    try {
        $pdo->prepare('INSERT INTO aradbranding_tickets (order_id, item_id, service_title, department, customer_id, subject, message, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int) $order['id'], null, 'شارژِ استارز — سامانه توسعه تجارت', $s['department'] !== '' ? $s['department'] : null, (int) $order['customer_id'], $subject, $message, 'queued', $userId ?: null, $now, $now]);
        $tid = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('ts_ticket: ' . $e->getMessage());
        return null;
    }
    if ($isNew) $pdo->prepare('UPDATE ts_accounts SET welcome_sent = 1, updated_at = ? WHERE person_key = ?')->execute([$now, (int) $acc['person_key']]);
    // ارسالِ خودکار (اگر اتصالِ تیکت فعال است)؛ وگرنه در «ارسال تیکت‌ها» آماده‌ی ارسال می‌ماند
    try {
        $abt = abt_settings($pdo);
        if (abt_connection_ready($abt) && ($abt['auto_send'] ?? '1') === '1' && ($t = abt_get_ticket($pdo, $tid))) abt_send($pdo, $order, $t, $userId);
    } catch (Throwable $e) {
        error_log('ts_ticket send: ' . $e->getMessage());
    }
    return $tid;
}

/** اجرای یک ردیفِ شارژ (ساخت/پیدا کردنِ حساب ← شارژ ← تیکت) */
function ts_run_credit(PDO $pdo, array $s, array $order, array $row, int $userId): array
{
    $now = date('Y-m-d H:i:s');
    $fail = static function (string $msg, string $raw = '') use ($pdo, $row, $now): array {
        $pdo->prepare("UPDATE ts_credits SET status = 'failed', last_error = ?, response_raw = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?")
            ->execute([mb_substr($msg, 0, 500), $raw !== '' ? $raw : null, $now, (int) $row['id']]);
        return ['ok' => false, 'message' => $msg];
    };
    $a = ts_ensure_account($pdo, $s, $order);
    if (!$a['ok']) return $fail($a['message']);
    $acc = $a['account'];
    $r = ts_http($s, 'POST', '/api/integrations/arad-contact/stars/credit', [
        'user_id' => is_numeric($acc['remote_user_id']) ? (int) $acc['remote_user_id'] : (string) $acc['remote_user_id'],
        'amount_toman' => (int) $row['amount_toman'],
        'external_id' => (string) $row['external_id'],
        'note' => 'سامانه توسعه تجارت — فاکتور ' . ($order['order_number'] ?? $order['id']),
    ]);
    $j = $r['json'] ?? [];
    if (!$r['ok'] || !isset($j['stars'])) return $fail('شارژ ناموفق — ' . ($r['error'] ?: 'پاسخ تعدادِ استارز ندارد'), $r['raw']);
    $isNewAcc = (int) $acc['created_by_us'] === 1 && (int) $acc['welcome_sent'] === 0;
    $pdo->prepare("UPDATE ts_credits SET status = 'done', stars = ?, toman_per_star = ?, balance = ?, remote_user_id = ?, remote_tx = ?, new_account = ?,
            last_error = NULL, response_raw = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?")
        ->execute([(float) $j['stars'], isset($j['toman_per_star']) ? (float) $j['toman_per_star'] : null, isset($j['balance']) ? (float) $j['balance'] : null,
            (string) $acc['remote_user_id'], isset($j['transaction_id']) ? (string) $j['transaction_id'] : null, $isNewAcc ? 1 : 0, $r['raw'], $now, (int) $row['id']]);
    $st = $pdo->prepare('SELECT * FROM ts_credits WHERE id = ?');
    $st->execute([(int) $row['id']]);
    $done = $st->fetch(PDO::FETCH_ASSOC);
    $tid = ts_ticket($pdo, $s, $order, $done, $acc, $userId);
    if ($tid) $pdo->prepare('UPDATE ts_credits SET ticket_id = ? WHERE id = ?')->execute([$tid, (int) $row['id']]);
    if (function_exists('orders_add_history')) {
        try {
            orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null, 'شارژِ استارزِ سامانه توسعه تجارت: ' . number_format((int) $row['amount_toman']) . ' تومان = '
                . rtrim(rtrim(number_format((float) $j['stars'], 2, '.', ','), '0'), '.') . ' استارز' . ($isNewAcc ? ' (حسابِ جدید ساخته شد)' : ''));
        } catch (Throwable $e) {}
    }
    return ['ok' => true, 'message' => number_format((int) $row['amount_toman']) . ' تومان شارژ شد'];
}

/**
 * پس از هر تأییدِ پول (یا دستی از صفحه‌ی سفارش): شارژِ سهمِ تأییدشده‌ی جدیدِ «سامانه توسعه تجارت».
 * ردیفِ ناموفقِ قبلی اول دوباره امتحان می‌شود (با همان external_id)، بعد اختلافِ هدف با شارژشده‌ها شارژ می‌شود.
 */
function ts_sync_order(PDO $pdo, int $orderId, int $userId = 0): array
{
    if (!ts_ready($pdo)) return ['ok' => null, 'message' => ''];
    $s = ts_settings($pdo);
    if (!ts_active($s) || !function_exists('orders_get')) return ['ok' => null, 'message' => ''];
    $lock = 'ts_order_' . $orderId;
    $locked = false;
    try { $locked = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 30)')->fetchColumn() === 1; } catch (Throwable $e) {}
    try {
        $order = orders_get($pdo, $orderId);
        if (!$order || $order['status'] !== 'approved' || (string) ($order['import_ref'] ?? '') !== '') return ['ok' => null, 'message' => ''];
        $msgs = [];
        $okAll = true;
        $any = false;
        foreach (ts_order_targets($pdo, $s, $order) as $iid => $t) {
            $rows = $pdo->prepare('SELECT * FROM ts_credits WHERE item_id = ? ORDER BY id');
            $rows->execute([$iid]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $covered = 0;
            foreach ($rows as $r) $covered = max($covered, (int) $r['target_cumulative']);
            // ناموفق‌های قبلی (همان external_id، بدونِ شارژِ تکراری)
            foreach ($rows as $r) {
                if ($r['status'] === 'done') continue;
                $any = true;
                $res = ts_run_credit($pdo, $s, $order, $r, $userId);
                $okAll = $okAll && $res['ok'];
                $msgs[] = $res['message'];
                if (!$res['ok']) continue 2; // تا ناموفقِ قبلی درست نشده، شارژِ جدید ساخته نمی‌شود (ترتیب حفظ شود)
            }
            if ($t['target'] <= $covered) continue;
            $now = date('Y-m-d H:i:s');
            $ext = 'arad-contact-stars-item-' . $iid . '-to-' . $t['target'];
            $pdo->prepare('INSERT IGNORE INTO ts_credits (order_id, item_id, customer_id, external_id, target_cumulative, amount_toman, paid_basis, status, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $iid, (int) $order['customer_id'], $ext, $t['target'], $t['target'] - $covered, $t['paid'], 'pending', $userId ?: null, $now, $now]);
            $st = $pdo->prepare('SELECT * FROM ts_credits WHERE external_id = ?');
            $st->execute([$ext]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] === 'done') continue;
            $any = true;
            $res = ts_run_credit($pdo, $s, $order, $row, $userId);
            $okAll = $okAll && $res['ok'];
            $msgs[] = $res['message'];
        }
        if (!$any) return ['ok' => null, 'message' => ''];
        return ['ok' => $okAll, 'message' => 'شارژِ استارزِ سامانه توسعه تجارت: ' . implode('؛ ', $msgs)];
    } catch (Throwable $e) {
        error_log('ts_sync_order: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'شارژِ استارز: خطای داخلی'];
    } finally {
        if ($locked) { try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')'); } catch (Throwable $e) {} }
    }
}

/** فراخوانی از نقاطِ تأییدِ پول — هرگز خطا به بیرون نمی‌دهد */
function ts_on_money(PDO $pdo, int $orderId, int $userId): void
{
    try {
        if (!function_exists('abt_ready')) require_once __DIR__ . '/aradbranding_ticket.php';
        $r = ts_sync_order($pdo, $orderId, $userId);
        if ($r['ok'] === false && function_exists('flash_set')) flash_set('warning', $r['message'] . ' — از صفحه‌ی سفارش «تلاشِ دوباره» را بزنید.');
    } catch (Throwable $e) {
        error_log('ts_on_money: ' . $e->getMessage());
    }
}

/** شارژهای یک سفارش (برای نمایش در صفحه‌ی سفارش) */
function ts_credits_for_order(PDO $pdo, int $orderId): array
{
    if (!ts_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT * FROM ts_credits WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
