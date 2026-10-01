<?php
/**
 * اتصال به «سامانه‌ی CRM آراد برندینگ» (crm.aradbranding.me):
 * پیش از ارسالِ تیکتِ خدمتِ «سامانه مدیریت ارتباط با مشتری CRM - …»، شرکت (tenant) با اشتراکِ خریداری‌شده ساخته/تمدید می‌شود
 * و نام کاربری/رمز/لینکِ ورود در متنِ تیکت قرار می‌گیرد.
 *
 * API سامانه‌ی CRM:
 *   GET  {base}/api/integrations/arad-contact/plans
 *   POST {base}/api/integrations/arad-contact/tenants   (Authorization: Bearer <token>)
 * یک درخواست برای هر قلمِ سفارش (external_id = arad-contact-crm-item-<id>) — تکرارش شرکت/شارژِ تکراری نمی‌سازد.
 *
 * متغیرهای متنِ تیکت: «نام_کاربری» «رمز_عبور» «آدرس_ورود» «نام_شرکت» «اشتراک» «تاریخ_انقضا»
 */

const CRM_PLANS = [
    'basic'        => 'پایه (Basic)',
    'professional' => 'حرفه‌ای (Professional)',
    'enterprise'   => 'سازمانی (Enterprise)',
];

function crm_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.crm_provisions_v1';
    if (is_file($flag)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_provisions (
            item_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            external_id VARCHAR(80) NOT NULL,
            plan VARCHAR(30) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            tenant_id VARCHAR(60) DEFAULT NULL,
            company_name VARCHAR(200) DEFAULT NULL,
            username VARCHAR(120) DEFAULT NULL,
            password VARCHAR(120) DEFAULT NULL,
            login_url VARCHAR(300) DEFAULT NULL,
            expires_at VARCHAR(40) DEFAULT NULL,
            tenant_created TINYINT(1) NOT NULL DEFAULT 0,
            request_json TEXT NULL,
            response_raw TEXT NULL,
            last_error VARCHAR(500) DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (item_id), KEY idx_crmp_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('crm_ready: ' . $e->getMessage());
        return $ok = false;
    }
    if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
    @file_put_contents($flag, (string) time());
    return $ok = true;
}

function crm_settings(PDO $pdo): array
{
    $s = function_exists('abt_settings') ? abt_settings($pdo) : [];
    $base = rtrim(trim((string) ($s['crm_base_url'] ?? '')) ?: 'https://crm.aradbranding.me', '/');
    return [
        'enabled'   => (string) ($s['crm_enabled'] ?? '0') === '1',
        'base_url'  => $base,
        'token'     => (string) ($s['crm_token'] ?? ''),
        'login_url' => trim((string) ($s['crm_login_url'] ?? '')) ?: $base . '/login.php',
        'map'       => json_decode((string) ($s['crm_map_json'] ?? ''), true) ?: [],
    ];
}

/** اشتراکِ CRM برای یک خدمت: جدولِ تطبیقِ تنظیمات، وگرنه از روی عنوان («CRM» یا «مدیریت ارتباط با مشتری»؛ پایه/حرفه‌ای/سازمانی — بدونِ نامِ بسته = حرفه‌ای) */
function crm_plan_for(array $settings, ?int $serviceId, string $title): ?string
{
    if ($serviceId && isset($settings['map'][(string) $serviceId])) {
        $c = (string) $settings['map'][(string) $serviceId];
        return $c === '-' ? null : (isset(CRM_PLANS[$c]) ? $c : null);
    }
    $t = mb_strtolower(str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $title));
    if (mb_strpos($t, 'crm') === false && mb_strpos($t, 'مدیریت ارتباط با مشتری') === false) return null;
    if (mb_strpos($t, 'enterprise') !== false || mb_strpos($t, 'سازمانی') !== false) return 'enterprise';
    if (mb_strpos($t, 'professional') !== false || mb_strpos($t, 'حرفه') !== false) return 'professional';
    if (mb_strpos($t, 'basic') !== false || mb_strpos($t, 'پایه') !== false) return 'basic';
    return 'professional'; // «سامانه مدیریت ارتباط با مشتری CRM» بدونِ نامِ بسته ← حرفه‌ای
}

/** قلمِ سفارشِ این تیکت اگر CRM باشد: [item row + plan] */
function crm_item_for_ticket(PDO $pdo, array $settings, ?int $itemId): ?array
{
    if (!$itemId) return null;
    $st = $pdo->prepare('SELECT * FROM sales_order_items WHERE id = ?');
    $st->execute([$itemId]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) return null;
    $plan = crm_plan_for($settings, $it['service_id'] ? (int) $it['service_id'] : null, (string) $it['title']);
    return $plan ? $it + ['plan' => $plan] : null;
}

function crm_http(array $settings, string $method, string $path, ?array $body = null): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'json' => null, 'raw' => '', 'error' => 'cURL فعال نیست.'];
    $ch = curl_init($settings['base_url'] . $path);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $settings['token']];
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

function crm_test(PDO $pdo): array
{
    $s = crm_settings($pdo);
    if ($s['token'] === '') return ['ok' => false, 'message' => 'توکن وارد نشده.'];
    $r = crm_http($s, 'GET', '/api/integrations/arad-contact/plans');
    if (!$r['ok']) return ['ok' => false, 'message' => 'اتصال ناموفق — ' . $r['error']];
    $list = [];
    foreach ((array) ($r['json']['plans'] ?? []) as $p) $list[] = ($p['code'] ?? '?') . ' = ' . ($p['title'] ?? '');
    return ['ok' => true, 'message' => 'اتصال برقرار است — ' . count($list) . ' اشتراک: ' . implode('، ', $list)];
}

function crm_get(PDO $pdo, int $itemId): ?array
{
    if (!crm_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM crm_provisions WHERE item_id = ?');
    $st->execute([$itemId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * دوره‌ی اشتراکِ CRM از قلمِ فاکتور. سامانه‌ی CRM فقط «سالانه» یا «ماهانه» می‌پذیرد، ولی واحدِ قلم در فاکتور
 * معمولاً «عدد»، «سال»، «۱ ساله»، «سه ماهه»، «اشتراک» … است. ترتیب: واحد ← عنوانِ خدمت ← پیش‌فرض «سالانه».
 * «۳ ماهه» × ۲ = ۶ ماهانه؛ «عدد» × ۲ = ۲ سالانه.
 * @return array{0:int|float,1:string} [تعداد، «سالانه»|«ماهانه»]
 */
function crm_period(array $item): array
{
    $q = (float) ($item['quantity'] ?? 1);
    if ($q <= 0) $q = 1;
    $norm = static function (string $t): string {
        $t = normalize_digits(str_replace(['ي', 'ك', "\u{200C}", '‌'], ['ی', 'ک', ' ', ' '], mb_strtolower(trim($t))));
        return preg_replace('/\s+/u', ' ', $t);
    };
    $words = ['یک' => 1, 'دو' => 2, 'سه' => 3, 'چهار' => 4, 'پنج' => 5, 'شش' => 6, 'شیش' => 6, 'نه' => 9, 'دوازده' => 12];
    $detect = static function (string $t) use ($words): ?array {
        $mult = 1;
        if (preg_match('/(\d+)\s*(?:ماه|سال|month|year)/u', $t, $m)) $mult = max(1, (int) $m[1]);
        else foreach ($words as $w => $n) if (preg_match('/(?:^|\s)' . $w . '\s*(?:ماه|سال)/u', $t)) { $mult = $n; break; }
        if (preg_match('/ماه|month/u', $t)) return [$mult, 'ماهانه'];
        if (preg_match('/سال|year|annual/u', $t)) return [$mult, 'سالانه'];
        return null;
    };
    $hit = $detect($norm((string) ($item['unit'] ?? ''))) ?? $detect($norm((string) ($item['title'] ?? '')));
    [$mult, $unit] = $hit ?? [1, 'سالانه'];
    $total = $q * $mult;
    return [$total == (int) $total ? (int) $total : $total, $unit];
}

/** ساخت/تمدیدِ شرکت در CRM برای یک قلمِ سفارش (فقط یک‌بار) */
function crm_ensure(PDO $pdo, array $order, array $item, int $userId): array
{
    $s = crm_settings($pdo);
    if (!crm_ready($pdo)) return ['ok' => false, 'message' => 'جدولِ CRM ساخته نشد.'];
    $itemId = (int) $item['id'];
    $row = crm_get($pdo, $itemId);
    if ($row && $row['status'] === 'ok') return ['ok' => true, 'row' => $row];
    if ($s['token'] === '') return ['ok' => false, 'message' => 'توکنِ سامانه‌ی CRM در «تنظیمات تیکت» وارد نشده.'];

    $c = $pdo->prepare('SELECT full_name, mobile FROM customers WHERE id = ?');
    $c->execute([(int) $order['customer_id']]);
    $cust = $c->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => '', 'mobile' => ''];
    $owner = trim(preg_replace('/\s+/u', ' ', (string) $cust['full_name'])) ?: 'مالک';
    $mobile = normalize_digits((string) $cust['mobile']);
    if ($mobile === '') return ['ok' => false, 'message' => 'موبایلِ مشتری ثبت نشده؛ ساختِ شرکت در CRM ممکن نیست.'];
    [$pq, $pu] = crm_period($item);
    $req = [
        'external_id' => 'arad-contact-crm-item-' . $itemId,
        'owner_name' => $owner, 'mobile' => $mobile, 'company_name' => $owner,
        'plan' => $item['plan'], 'plan_title' => CRM_PLANS[$item['plan']],
        'period_quantity' => $pq, 'period_unit' => $pu,
    ];
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO crm_provisions (item_id, order_id, external_id, plan, status, request_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE plan = VALUES(plan), request_json = VALUES(request_json), updated_at = VALUES(updated_at)')
        ->execute([$itemId, (int) $order['id'], $req['external_id'], $item['plan'], 'pending', json_encode($req, JSON_UNESCAPED_UNICODE), $now, $now]);

    $r = crm_http($s, 'POST', '/api/integrations/arad-contact/tenants', $req);
    if (!$r['ok']) {
        $pdo->prepare("UPDATE crm_provisions SET status = 'failed', last_error = ?, response_raw = ?, attempts = attempts + 1, updated_at = ? WHERE item_id = ?")
            ->execute([mb_substr($r['error'], 0, 500), $r['raw'], date('Y-m-d H:i:s'), $itemId]);
        try { orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null, 'سامانه‌ی CRM: ساختِ شرکت ناموفق — ' . $r['error']); } catch (Throwable $e) {}
        return ['ok' => false, 'message' => 'ساختِ شرکت در سامانه‌ی CRM ناموفق بود: ' . $r['error']];
    }
    $j = $r['json'];
    $pdo->prepare("UPDATE crm_provisions SET status = 'ok', tenant_id = ?, company_name = ?, username = ?, password = ?, login_url = ?, expires_at = ?,
            tenant_created = ?, response_raw = ?, last_error = NULL, attempts = attempts + 1, updated_at = ? WHERE item_id = ?")
        ->execute([(string) ($j['tenant_id'] ?? ''), (string) ($j['company_name'] ?? $owner), (string) ($j['username'] ?? $mobile),
            isset($j['password']) && $j['password'] !== '' ? (string) $j['password'] : null,
            (string) ($j['login_url'] ?? $s['login_url']), isset($j['expires_at']) ? (string) $j['expires_at'] : null,
            !empty($j['tenant_created']) ? 1 : 0, $r['raw'], date('Y-m-d H:i:s'), $itemId]);
    try {
        orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null, 'سامانه‌ی CRM: '
            . (!empty($j['tenant_created']) ? 'شرکتِ جدید ساخته شد' : 'اشتراکِ شرکتِ موجود تمدید/به‌روز شد') . ' — ' . CRM_PLANS[$item['plan']]
            . (!empty($j['expires_at']) ? ' تا ' . $j['expires_at'] : '') . (!empty($j['duplicate']) ? ' [قبلاً انجام شده بود]' : ''));
    } catch (Throwable $e) {}
    return ['ok' => true, 'row' => crm_get($pdo, $itemId)];
}

/** پیش از ارسالِ تیکت: اگر قلمِ این تیکت CRM است، شرکت را بساز و اطلاعاتِ ورود را در متن بگذار */
function crm_prepare_ticket(PDO $pdo, array $order, array $ticket, int $userId): array
{
    $s = crm_settings($pdo);
    if (!$s['enabled']) return ['ok' => true, 'message' => '', 'ticket' => $ticket];
    $item = crm_item_for_ticket($pdo, $s, $ticket['item_id'] ? (int) $ticket['item_id'] : null);
    if (!$item) return ['ok' => true, 'message' => '', 'ticket' => $ticket];
    $r = crm_ensure($pdo, $order, $item, $userId);
    if (!$r['ok']) return ['ok' => false, 'message' => $r['message'], 'ticket' => $ticket];
    $row = $r['row'];
    $vars = [
        'نام_کاربری' => (string) $row['username'],
        'رمز_عبور' => $row['password'] !== null && $row['password'] !== '' ? (string) $row['password'] : 'همان رمزِ قبلیِ شما (اگر به خاطر ندارید، در صفحه‌ی ورود «فراموشی رمز» را بزنید)',
        'آدرس_ورود' => (string) ($row['login_url'] ?: $s['login_url']),
        'نام_شرکت' => (string) $row['company_name'],
        'اشتراک' => CRM_PLANS[$row['plan']] ?? $row['plan'],
        'تاریخ_انقضا' => (string) ($row['expires_at'] ?? ''),
    ];
    $msg = (string) $ticket['message'];
    if (mb_strpos($msg, '«نام_کاربری»') === false) {
        $msg = rtrim($msg) . "\n\n✅ سامانه‌ی CRM برای شما راه‌اندازی شد و از همین حالا دسترسی دارید.\n"
            . "اشتراک: «اشتراک»\nآدرس ورود به پنل: «آدرس_ورود»\nنام کاربری: «نام_کاربری»\nرمز عبور: «رمز_عبور»";
    }
    foreach ($vars as $k => $v) {
        $msg = str_replace('«' . $k . '»', $v, $msg);
        $ticket['subject'] = str_replace('«' . $k . '»', $v, (string) $ticket['subject']);
    }
    $ticket['message'] = $msg;
    try { $pdo->prepare('UPDATE aradbranding_tickets SET subject = ?, message = ? WHERE id = ?')->execute([$ticket['subject'], $ticket['message'], (int) $ticket['id']]); } catch (Throwable $e) {}
    return ['ok' => true, 'message' => '', 'ticket' => $ticket];
}
