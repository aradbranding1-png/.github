<?php
/**
 * اتصال به «سامانه‌ی آموزش آراد برندینگ» (edu.aradbranding.me):
 * پیش از ارسالِ تیکتِ خدماتِ آموزشی، خرید در سامانه‌ی آموزش اعمال می‌شود (ساختِ کاربر + شارژِ خدمات)
 * و نام کاربری/رمز/آدرسِ ورود در متنِ تیکت قرار می‌گیرد.
 *
 * API سامانه‌ی آموزش:
 *   GET  {base}/api/integrations/arad-contact/services
 *   POST {base}/api/integrations/arad-contact/provision   (Authorization: Bearer <token>)
 * یک درخواست برای هر سفارش (external_id = arad-contact-order-<id>) — تکرارِ آن شارژِ دوباره نمی‌کند.
 *
 * متغیرهای متنِ تیکت: «نام_کاربری» «رمز_عبور» «آدرس_ورود» «خدمات_اعمال_شده»
 */

const EDU_CODES = [
    'platform_account' => 'اکانت سامانه آموزش آراد برندینگ',
    'webinar'          => 'وبینار تجاری',
    'workshop'         => 'کارگاه تجاری آنلاین',
    'skill_files'      => 'فایل‌های تجاری مهارت‌محور',
    'online_meeting'   => 'میتینگ آنلاین',
];

function edu_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.edu_provisions_v1';
    if (is_file($flag)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS edu_provisions (
            order_id INT UNSIGNED NOT NULL,
            external_id VARCHAR(80) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            username VARCHAR(120) DEFAULT NULL,
            password VARCHAR(120) DEFAULT NULL,
            login_url VARCHAR(300) DEFAULT NULL,
            user_created TINYINT(1) NOT NULL DEFAULT 0,
            applied_json TEXT NULL,
            request_json TEXT NULL,
            response_raw TEXT NULL,
            last_error VARCHAR(500) DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('edu_ready: ' . $e->getMessage());
        return $ok = false;
    }
    if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
    @file_put_contents($flag, (string) time());
    return $ok = true;
}

function edu_settings(PDO $pdo): array
{
    $s = function_exists('abt_settings') ? abt_settings($pdo) : [];
    return [
        'enabled'  => (string) ($s['edu_enabled'] ?? '0') === '1',
        'base_url' => rtrim(trim((string) ($s['edu_base_url'] ?? '')) ?: 'https://edu.aradbranding.me', '/'),
        'token'    => (string) ($s['edu_token'] ?? ''),
        'map'      => json_decode((string) ($s['edu_map_json'] ?? ''), true) ?: [],
    ];
}

function edu_norm(string $s): string
{
    $s = str_replace(['ي', 'ك', "\u{200C}", 'ۀ'], ['ی', 'ک', ' ', 'ه'], $s);
    return (string) preg_replace('/\s+/u', ' ', trim($s));
}

/** کدِ خدمتِ سامانه‌ی آموزش برای یک خدمتِ آراد کانتکت: اول جدولِ تطبیقِ تنظیمات، بعد تطابقِ دقیقِ عنوان */
function edu_code_for(array $settings, ?int $serviceId, string $title): ?string
{
    if ($serviceId && isset($settings['map'][(string) $serviceId])) {
        $c = (string) $settings['map'][(string) $serviceId];
        return $c === '-' ? null : (isset(EDU_CODES[$c]) ? $c : null);
    }
    $t = edu_norm($title);
    $defaults = [
        edu_norm('اکانت سامانه آموزش آراد برندینگ') => 'platform_account',
        edu_norm('وبینار تجاری') => 'webinar',
        edu_norm('کارگاه تجاری آنلاین') => 'workshop',
        edu_norm('فایل های تجاری مهارت محور') => 'skill_files',
        edu_norm('فایل‌های تجاری مهارت‌محور') => 'skill_files',
        edu_norm('میتینگ های آنلاین') => 'online_meeting',
        edu_norm('میتینگ آنلاین') => 'online_meeting',
    ];
    return $defaults[$t] ?? null;
}

/** اقلامِ آموزشیِ یک سفارش: [[item_id, code, quantity, unit, title], …] */
function edu_order_items(PDO $pdo, array $settings, int $orderId): array
{
    $out = [];
    foreach (function_exists('orders_items') ? orders_items($pdo, $orderId) : [] as $it) {
        $code = edu_code_for($settings, $it['service_id'] ? (int) $it['service_id'] : null, (string) $it['title']);
        if ($code === null) continue;
        $q = (float) $it['quantity'];
        $out[] = ['item_id' => (int) $it['id'], 'code' => $code, 'quantity' => $q == (int) $q ? (int) $q : $q,
            'unit' => (string) ($it['unit'] ?? ''), 'title' => (string) $it['title']];
    }
    return $out;
}

function edu_item_is_edu(PDO $pdo, array $settings, ?int $itemId): bool
{
    if (!$itemId) return false;
    $st = $pdo->prepare('SELECT service_id, title FROM sales_order_items WHERE id = ?');
    $st->execute([$itemId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? edu_code_for($settings, $r['service_id'] ? (int) $r['service_id'] : null, (string) $r['title']) !== null : false;
}

/** درخواستِ HTTP به سامانه‌ی آموزش */
function edu_http(array $settings, string $method, string $path, ?array $body = null): array
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

function edu_test(PDO $pdo): array
{
    $s = edu_settings($pdo);
    if ($s['token'] === '') return ['ok' => false, 'message' => 'توکن وارد نشده.'];
    $r = edu_http($s, 'GET', '/api/integrations/arad-contact/services');
    if (!$r['ok']) return ['ok' => false, 'message' => 'اتصال ناموفق — ' . $r['error'], 'raw' => $r['raw']];
    $list = [];
    foreach ((array) ($r['json']['services'] ?? []) as $sv) $list[] = ($sv['code'] ?? '?') . ' = ' . ($sv['title'] ?? '') . ' (' . implode('/', (array) ($sv['units'] ?? [])) . ')';
    return ['ok' => true, 'message' => 'اتصال برقرار است — ' . count($list) . ' خدمت: ' . implode('، ', $list)];
}

function edu_get(PDO $pdo, int $orderId): ?array
{
    if (!edu_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM edu_provisions WHERE order_id = ?');
    $st->execute([$orderId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * اعمالِ خریدِ آموزشیِ سفارش در سامانه‌ی آموزش (فقط یک‌بار؛ اگر قبلاً موفق بوده همان نتیجه برمی‌گردد).
 * @return array{ok:bool, message:string, row?:array, skipped?:bool}
 */
function edu_ensure(PDO $pdo, array $order, int $userId): array
{
    $s = edu_settings($pdo);
    if (!$s['enabled']) return ['ok' => true, 'skipped' => true, 'message' => ''];
    if (!edu_ready($pdo)) return ['ok' => false, 'message' => 'جدولِ سامانه‌ی آموزش ساخته نشد.'];
    $orderId = (int) $order['id'];
    $row = edu_get($pdo, $orderId);
    if ($row && $row['status'] === 'ok') return ['ok' => true, 'row' => $row, 'message' => ''];
    $items = edu_order_items($pdo, $s, $orderId);
    if (!$items) return ['ok' => true, 'skipped' => true, 'message' => ''];
    if ($s['token'] === '') return ['ok' => false, 'message' => 'توکنِ سامانه‌ی آموزش در «تنظیمات تیکت» وارد نشده.'];

    $c = $pdo->prepare('SELECT full_name, mobile FROM customers WHERE id = ?');
    $c->execute([(int) $order['customer_id']]);
    $cust = $c->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => '', 'mobile' => ''];
    $name = trim(preg_replace('/\s+/u', ' ', (string) $cust['full_name']));
    $parts = explode(' ', $name, 2);
    $first = $parts[0] !== '' ? $parts[0] : 'تاجر';
    $last = trim($parts[1] ?? '') !== '' ? trim($parts[1]) : $first;
    $mobile = normalize_digits((string) $cust['mobile']);
    if ($mobile === '') return ['ok' => false, 'message' => 'موبایلِ مشتری ثبت نشده؛ اعمال در سامانه‌ی آموزش ممکن نیست.'];

    $externalId = 'arad-contact-order-' . $orderId;
    $req = ['external_id' => $externalId, 'mobile' => $mobile, 'first_name' => $first, 'last_name' => $last,
        'items' => array_map(static fn($i) => ['service_code' => $i['code'], 'quantity' => $i['quantity'], 'unit' => $i['unit']], $items)];
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO edu_provisions (order_id, external_id, status, request_json, attempts, created_at, updated_at) VALUES (?,?,?,?,0,?,?)
        ON DUPLICATE KEY UPDATE request_json = VALUES(request_json), updated_at = VALUES(updated_at)')
        ->execute([$orderId, $externalId, 'pending', json_encode($req, JSON_UNESCAPED_UNICODE), $now, $now]);

    $r = edu_http($s, 'POST', '/api/integrations/arad-contact/provision', $req);
    if (!$r['ok']) {
        $pdo->prepare("UPDATE edu_provisions SET status = 'failed', last_error = ?, response_raw = ?, attempts = attempts + 1, updated_at = ? WHERE order_id = ?")
            ->execute([mb_substr($r['error'], 0, 500), $r['raw'], date('Y-m-d H:i:s'), $orderId]);
        if (function_exists('orders_add_history')) {
            orders_add_history($pdo, $orderId, $userId, 'note', null, null, 'سامانه‌ی آموزش: اعمالِ خرید ناموفق — ' . $r['error']);
        }
        return ['ok' => false, 'message' => 'اعمال در سامانه‌ی آموزش ناموفق بود: ' . $r['error']];
    }
    $j = $r['json'];
    $pdo->prepare("UPDATE edu_provisions SET status = 'ok', username = ?, password = ?, login_url = ?, user_created = ?, applied_json = ?, response_raw = ?,
            last_error = NULL, attempts = attempts + 1, updated_at = ? WHERE order_id = ?")
        ->execute([(string) ($j['username'] ?? $mobile), isset($j['password']) && $j['password'] !== '' ? (string) $j['password'] : null,
            (string) ($j['login_url'] ?? ($s['base_url'] . '/login')), !empty($j['user_created']) ? 1 : 0,
            json_encode($j['applied'] ?? [], JSON_UNESCAPED_UNICODE), $r['raw'], date('Y-m-d H:i:s'), $orderId]);
    $row = edu_get($pdo, $orderId);
    if (function_exists('orders_add_history')) {
        $applied = implode('، ', array_map(static fn($a) => (EDU_CODES[$a['service_code'] ?? ''] ?? ($a['service_code'] ?? '')) . ': ' . ($a['quantity'] ?? '') . ' ' . ($a['unit'] ?? ''), (array) ($j['applied'] ?? [])));
        orders_add_history($pdo, $orderId, $userId, 'note', null, null, 'سامانه‌ی آموزش: خرید اعمال شد'
            . (!empty($j['user_created']) ? ' (کاربرِ جدید ساخته شد)' : ' (کاربرِ موجود)') . ($applied !== '' ? ' — ' . $applied : '') . (!empty($j['duplicate']) ? ' [قبلاً اعمال شده بود]' : ''));
    }
    return ['ok' => true, 'row' => $row, 'message' => ''];
}

/** مقادیرِ متغیرهای متنِ تیکت از نتیجه‌ی اعمال */
function edu_vars(array $row): array
{
    $applied = json_decode((string) ($row['applied_json'] ?? ''), true) ?: [];
    $lines = [];
    foreach ($applied as $a) {
        $lines[] = '• ' . (EDU_CODES[$a['service_code'] ?? ''] ?? ($a['service_code'] ?? '')) . ': ' . to_persian_digits((string) ($a['quantity'] ?? '')) . ' ' . ($a['unit'] ?? '');
    }
    return [
        'نام_کاربری' => (string) $row['username'],
        'رمز_عبور' => $row['password'] !== null && $row['password'] !== '' ? (string) $row['password'] : 'همان رمزِ قبلیِ شما (اگر به خاطر ندارید، در صفحه‌ی ورود «فراموشی رمز» را بزنید)',
        'آدرس_ورود' => (string) $row['login_url'],
        'خدمات_اعمال_شده' => implode("\n", $lines),
    ];
}

/**
 * پیش از ارسالِ تیکت: اگر تیکت مربوط به یک خدمتِ آموزشی است، خرید را اعمال و متغیرها را در موضوع/متن جایگزین می‌کند.
 * اگر متنِ تیکت متغیرِ «نام_کاربری» نداشته باشد، بلوکِ اطلاعاتِ ورود به انتهای متن اضافه می‌شود.
 * @return array{ok:bool, message:string, ticket:array}
 */
function edu_prepare_ticket(PDO $pdo, array $order, array $ticket, int $userId): array
{
    $s = edu_settings($pdo);
    if (!$s['enabled'] || !edu_item_is_edu($pdo, $s, $ticket['item_id'] ? (int) $ticket['item_id'] : null)) {
        return ['ok' => true, 'message' => '', 'ticket' => $ticket];
    }
    $r = edu_ensure($pdo, $order, $userId);
    if (!$r['ok']) return ['ok' => false, 'message' => $r['message'], 'ticket' => $ticket];
    if (empty($r['row'])) return ['ok' => true, 'message' => '', 'ticket' => $ticket];
    $vars = edu_vars($r['row']);
    $apply = static function (string $t) use ($vars): string {
        foreach ($vars as $k => $v) $t = str_replace('«' . $k . '»', $v, $t);
        return $t;
    };
    $msg = (string) $ticket['message'];
    if (mb_strpos($msg, '«نام_کاربری»') === false && mb_strpos($msg, (string) $r['row']['username']) === false) {
        $msg = rtrim($msg) . "\n\n✅ این خدمت در سامانه‌ی آموزش آراد برندینگ برای شما فعال شد و از همین حالا دسترسی دارید.\n"
            . "آدرس ورود: «آدرس_ورود»\nنام کاربری: «نام_کاربری»\nرمز عبور: «رمز_عبور»";
    }
    // سایرِ خدماتِ آموزشیِ همین سفارش در همین تیکت اعلام می‌شوند؛ «انجام شد» خوردنِ آن‌ها فقط بعد از ارسالِ «موفقِ» همین تیکت است
    // (abt_send_unlocked ← edu_bundle_order). قبلاً همین‌جا، پیش از ارسال، سبز می‌شدند و اگر ارسال شکست می‌خورد اشتباه می‌ماندند.
    $ticket['subject'] = $apply((string) $ticket['subject']);
    $ticket['message'] = $apply($msg);
    try {
        $pdo->prepare('UPDATE aradbranding_tickets SET subject = ?, message = ? WHERE id = ?')->execute([$ticket['subject'], $ticket['message'], (int) $ticket['id']]);
    } catch (Throwable $e) {}
    return ['ok' => true, 'message' => '', 'ticket' => $ticket];
}

/**
 * تیکت‌های «در صف/ناموفقِ» خدماتِ آموزشیِ دیگرِ همین سفارش ← «انجام شد» (bundled)،
 * چون همه در یک درخواست شارژ شده‌اند و در تیکتِ ارسال‌شده اعلام شده‌اند. («ارسال نشود»ها دست نمی‌خورند.)
 */
function edu_bundle_order(PDO $pdo, int $orderId, int $senderTicketId, string $senderTitle = ''): int
{
    $s = edu_settings($pdo);
    $st = $pdo->prepare("SELECT id, item_id FROM aradbranding_tickets WHERE order_id = ? AND id <> ? AND status IN ('queued','failed')");
    $st->execute([$orderId, $senderTicketId]);
    $n = 0;
    $upd = $pdo->prepare("UPDATE aradbranding_tickets SET status = 'bundled', last_error = NULL, external_id = ?, updated_at = ? WHERE id = ? AND status IN ('queued','failed')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
        if (!edu_item_is_edu($pdo, $s, $t['item_id'] ? (int) $t['item_id'] : null)) continue;
        $upd->execute([mb_substr('همراهِ تیکتِ «' . ($senderTitle ?: ('#' . $senderTicketId)) . '»', 0, 100), date('Y-m-d H:i:s'), (int) $t['id']]);
        $n += $upd->rowCount();
    }
    if ($n > 0 && function_exists('orders_add_history')) {
        try { orders_add_history($pdo, $orderId, 0, 'note', null, null, 'سامانه‌ی آموزش: ' . to_persian_digits((string) $n) . ' تیکتِ خدماتِ آموزشیِ دیگر «انجام شد» خورد (در تیکتِ «' . $senderTitle . '» اعلام شده‌اند).'); } catch (Throwable $e) {}
    }
    return $n;
}

/** اصلاحِ سفارش‌هایی که خریدِ آموزشی‌شان اعمال شده ولی تیکت‌های دیگرشان هنوز در صف مانده‌اند */
function edu_bundle_fix_all(PDO $pdo): void
{
    static $done = false;
    if ($done || !edu_ready($pdo)) return;
    $done = true;
    // «انجام شد»هایی که تیکتِ اصلی‌شان هیچ‌وقت ارسال نشده (ارسال شکست خورده) ← دوباره «آماده‌ی ارسال»
    try {
        $wrong = $pdo->query("SELECT t.id, t.order_id FROM aradbranding_tickets t WHERE t.status = 'bundled'
            AND NOT EXISTS (SELECT 1 FROM aradbranding_tickets s WHERE s.order_id = t.order_id AND s.id <> t.id AND s.status IN ('sent','manual'))")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fix = $pdo->prepare("UPDATE aradbranding_tickets SET status = 'queued', external_id = NULL, last_error = ?, updated_at = ? WHERE id = ? AND status = 'bundled'");
        $orders = [];
        foreach ($wrong as $w) {
            $fix->execute(['تیکتِ اصلیِ سامانه‌ی آموزشِ این سفارش ارسال نشده بود؛ دوباره آماده‌ی ارسال شد.', date('Y-m-d H:i:s'), (int) $w['id']]);
            $orders[(int) $w['order_id']] = ($orders[(int) $w['order_id']] ?? 0) + 1;
        }
        foreach ($orders as $oid => $n) {
            if (function_exists('orders_add_history')) { try { orders_add_history($pdo, $oid, 0, 'note', null, null, 'سامانه‌ی آموزش: ' . to_persian_digits((string) $n) . ' تیکت که اشتباهاً «انجام شد» خورده بود (تیکتِ اصلی ارسال نشده بود) دوباره آماده‌ی ارسال شد.'); } catch (Throwable $e) {} }
        }
    } catch (Throwable $e) {
        error_log('edu_bundle_fix_all revert: ' . $e->getMessage());
    }
    try {
        $rows = $pdo->query("SELECT e.order_id, t.id, t.service_title FROM edu_provisions e
            JOIN aradbranding_tickets t ON t.order_id = e.order_id AND t.status IN ('sent','manual')
            WHERE e.status = 'ok' AND EXISTS (SELECT 1 FROM aradbranding_tickets q WHERE q.order_id = e.order_id AND q.status IN ('queued','failed'))
            ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[(int) $r['order_id']])) continue;
            // فقط اگر تیکتِ ارسال‌شده خودش آموزشی بوده (اطلاعاتِ ورود در آن رفته)
            $it = $pdo->prepare('SELECT item_id FROM aradbranding_tickets WHERE id = ?');
            $it->execute([(int) $r['id']]);
            if (!edu_item_is_edu($pdo, edu_settings($pdo), (int) $it->fetchColumn() ?: null)) continue;
            $seen[(int) $r['order_id']] = true;
            edu_bundle_order($pdo, (int) $r['order_id'], (int) $r['id'], (string) $r['service_title']);
        }
    } catch (Throwable $e) {
        error_log('edu_bundle_fix_all: ' . $e->getMessage());
    }
}
