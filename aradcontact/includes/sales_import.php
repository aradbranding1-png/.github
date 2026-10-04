<?php
/**
 * ورودِ واریزی‌های قبل از سامانه از اکسل (گزارشِ فروشِ قدیمی) — admin/admin_sales_import.php
 *
 * الگوی اکسل (هر ردیف = یک سهم‌گیرِ یک واریزی؛ چند ردیف با «شماره کار»ِ یکسان = یک واریزی):
 *   شماره کار | تاجر | شماره همراه تاجر | کد ملی تاجر | شهر | مبلغ | تاریخ (۱۴۰۵۰۷۰۱) | دستی (درصدِ سهم) |
 *   مشتری جدید | طرح | کد پسنلی | نام و نام خانوادگی | حساب | پرداخت | پورسانت …
 *
 * هر واریزی ← یک سفارشِ تأییدشده (بدونِ پیش‌فاکتور) + پیش‌پرداختِ تأییدشده در «تاریخِ واریز»:
 *   - در گزارشِ فروش و گزارش‌های تیم/سرپرست (به تاریخِ واریز) حساب می‌شود.
 *   - سهم‌گیرندگان ← «فروشِ مشترک» با همان درصدها (اگر جمعِ درصدها ۱۰۰ نباشد، به همان نسبت تقسیم می‌شود).
 *   - تیکتِ آراد برندینگ و شارژِ استارز برای این سفارش‌ها ساخته/ارسال نمی‌شود (sales_orders.import_ref).
 *   - سهم عملکرد فقط برای واریزی‌هایی که هنگامِ ورود «محاسبه شود» خورده‌اند، طبقِ قانونِ فعلی حساب می‌شود
 *     (sales_orders.import_perf = 1)؛ بقیه هرگز محاسبه نمی‌شوند (حتی در محاسبه‌های دوباره‌ی بعدی).
 */

require_once __DIR__ . '/orders_functions.php';
require_once __DIR__ . '/finance_functions.php';
require_once __DIR__ . '/sales_credit.php';
require_once __DIR__ . '/spreadsheet_reader.php';

const SIMP_PREFIX = 'XL-';

function simp_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.sales_import_v1';
    if (is_file($flag)) return $ok = true;
    try {
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_orders'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('import_ref', $cols, true)) $pdo->exec('ALTER TABLE sales_orders ADD COLUMN import_ref VARCHAR(60) NULL DEFAULT NULL, ADD KEY idx_so_import (import_ref)');
        if (!in_array('import_perf', $cols, true)) $pdo->exec('ALTER TABLE sales_orders ADD COLUMN import_perf TINYINT(1) NULL DEFAULT NULL');
        @file_put_contents($flag, date('c'));
        return $ok = true;
    } catch (Throwable $e) {
        error_log('simp_ready: ' . $e->getMessage());
        return $ok = false;
    }
}

/** سفارشِ واردشده از اکسل است؟ (تیکت/استارز ندارد) */
function simp_is_imported(PDO $pdo, int $orderId): bool
{
    static $cache = [];
    if (isset($cache[$orderId])) return $cache[$orderId];
    if (!simp_ready($pdo)) return $cache[$orderId] = false;
    try {
        $st = $pdo->prepare('SELECT import_ref FROM sales_orders WHERE id = ?');
        $st->execute([$orderId]);
        return $cache[$orderId] = (string) $st->fetchColumn() !== '';
    } catch (Throwable $e) {
        return $cache[$orderId] = false;
    }
}

/** سفارشِ واردشده که سهم عملکردش نباید محاسبه شود */
function simp_perf_blocked(PDO $pdo, int $orderId): bool
{
    if (!simp_ready($pdo)) return false;
    try {
        $st = $pdo->prepare('SELECT import_ref, import_perf FROM sales_orders WHERE id = ?');
        $st->execute([$orderId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r && (string) $r['import_ref'] !== '' && (int) $r['import_perf'] !== 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** ستون‌های لازم از سطرِ عنوان */
function simp_columns(array $header): array
{
    $norm = static fn($h) => trim(preg_replace('/\s+/u', ' ', str_replace(["\u{200C}", 'ي', 'ك'], [' ', 'ی', 'ک'], (string) $h)));
    $want = [
        'ref' => ['شماره کار'], 'name' => ['تاجر'], 'mobile' => ['شماره همراه تاجر', 'موبایل تاجر'], 'nid' => ['کد ملی تاجر'],
        'city' => ['شهر'], 'amount' => ['مبلغ'], 'date' => ['تاریخ'], 'pct' => ['دستی'], 'kind' => ['مشتری جدید'], 'plan' => ['طرح'],
        'code' => ['کد پسنلی', 'کد پرسنلی'], 'agent' => ['نام و نام خانوادگی'], 'account' => ['حساب'], 'payref' => ['پرداخت'],
        'commission' => ['پورسانت'], 'barter' => ['ارزش تهاتر'],
    ];
    $idx = [];
    foreach ($header as $i => $h) {
        $h = $norm($h);
        foreach ($want as $k => $names) if (!isset($idx[$k]) && in_array($h, $names, true)) $idx[$k] = $i;
    }
    return $idx;
}

/** تاریخِ ۱۴۰۵۰۷۰۱ / ۱۴۰۵/۰۷/۰۱ ← میلادی */
function simp_date(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', normalize_digits($raw));
    if (strlen($d) !== 8) return null;
    $j = substr($d, 0, 4) . '/' . substr($d, 4, 2) . '/' . substr($d, 6, 2);
    return to_gregorian($j) ?: null;
}

/**
 * کدام ردیف‌ها سهم دارند؟ در الگوی اکسل، اگر جمعِ درصدها ۱۰۰ نباشد، ردیفی که پورسانتش «بررسی» است
 * فقط ثبت‌کننده‌ی واریزی است (مثلاً ردیفِ اول با ۱۰۰٪ و بعد سهم‌گیرندگانِ واقعی با جمعِ ۱۰۰٪) و سهمی نمی‌برد.
 * @param array<int,array{pct:float,commission:string}> $agents
 * @return array<int,bool>
 */
function simp_share_flags(array $agents): array
{
    $all = array_fill_keys(array_keys($agents), true);
    $sum = array_sum(array_column($agents, 'pct'));
    if (abs($sum - 100) <= 0.01 || count($agents) < 2) return $all;
    $isCheck = static fn($c) => trim(str_replace(['ي', 'ك'], ['ی', 'ک'], (string) $c)) === 'بررسی';
    $flags = [];
    $rest = 0.0;
    foreach ($agents as $k => $a) {
        $flags[$k] = !$isCheck($a['commission'] ?? '');
        if ($flags[$k]) $rest += (float) $a['pct'];
    }
    return in_array(true, $flags, true) && abs($rest - 100) <= 0.01 ? $flags : $all;
}

/**
 * خواندن و گروه‌بندیِ اکسل + بررسی (بدونِ نوشتن در پایگاه‌داده).
 * @return array{ok:bool, message:string, groups:array}
 */
function simp_parse(PDO $pdo, array $rows): array
{
    if (!$rows) return ['ok' => false, 'message' => 'فایل خالی است.', 'groups' => []];
    $header = array_shift($rows);
    $c = simp_columns($header);
    foreach (['ref', 'name', 'mobile', 'amount', 'date', 'pct', 'agent'] as $need) {
        if (!isset($c[$need])) return ['ok' => false, 'message' => 'ستونِ لازم در سطرِ اول پیدا نشد: «' . ['ref' => 'شماره کار', 'name' => 'تاجر', 'mobile' => 'شماره همراه تاجر', 'amount' => 'مبلغ', 'date' => 'تاریخ', 'pct' => 'دستی', 'agent' => 'نام و نام خانوادگی'][$need] . '». الگوی اکسل باید همان الگوی قبلی باشد.', 'groups' => []];
    }
    $v = static fn(array $r, string $k): string => isset($c[$k]) ? trim((string) ($r[$c[$k]] ?? '')) : '';
    $num = static function (string $s): float {
        $s = str_replace([',', '٬', ' '], '', normalize_digits($s));
        return is_numeric($s) ? (float) $s : 0.0;
    };
    // کاربران: با کدِ پرسنلی (arad_code)، وگرنه با نامِ کامل
    $byCode = [];
    $byName = [];
    try {
        $hasCode = (bool) $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'arad_code'")->fetchColumn();
        foreach ($pdo->query('SELECT id, full_name, role' . ($hasCode ? ', arad_code' : ', NULL AS arad_code') . ' FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            if ((string) $u['arad_code'] !== '') $byCode[preg_replace('/\D+/', '', normalize_digits((string) $u['arad_code']))] = $u;
            $byName[preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك'], ['ی', 'ک'], trim((string) $u['full_name'])))][] = $u;
        }
    } catch (Throwable $e) {}

    $groups = [];
    foreach ($rows as $i => $r) {
        $ref = preg_replace('/\D+/', '', normalize_digits($v($r, 'ref')));
        if ($ref === '' && $v($r, 'agent') === '') continue; // سطرِ خالی
        $line = $i + 2;
        if ($ref === '') { $groups['?' . $line] = ['ref' => '', 'line' => $line, 'errors' => ['شماره کار خالی است (سطر ' . $line . ').'], 'warnings' => [], 'agents' => []]; continue; }
        if (!isset($groups[$ref])) {
            // یک یا چند شماره در یک خانه (مثلِ «9179906696 - 9941999535»): اولی موبایل، دومی موبایلِ دوم
            $mobs = [];
            foreach (preg_split('/\D+/', normalize_digits($v($r, 'mobile'))) ?: [] as $p) {
                if ($p === '') continue;
                if (strlen($p) === 10 && $p[0] === '9') $p = '0' . $p;
                if (strlen($p) === 12 && substr($p, 0, 3) === '989') $p = '0' . substr($p, 2);
                $mobs[] = $p;
            }
            $mobile = $mobs[0] ?? '';
            $mobile2 = isset($mobs[1]) && preg_match('/^09\d{9}$/', $mobs[1]) ? $mobs[1] : '';
            $groups[$ref] = [
                'ref' => $ref, 'line' => $line, 'name' => $v($r, 'name'), 'mobile' => $mobile, 'mobile2' => $mobile2, 'nid' => preg_replace('/\D+/', '', normalize_digits($v($r, 'nid'))),
                'city' => $v($r, 'city'), 'amount' => (int) round($num($v($r, 'amount'))), 'date_raw' => $v($r, 'date'), 'date' => simp_date($v($r, 'date')),
                'kind' => $v($r, 'kind'), 'plan' => $v($r, 'plan'), 'account' => $v($r, 'account'), 'payref' => $v($r, 'payref'),
                'agents' => [], 'errors' => [], 'warnings' => [],
            ];
        }
        $g = &$groups[$ref];
        $code = preg_replace('/\D+/', '', normalize_digits($v($r, 'code')));
        $nm = preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك'], ['ی', 'ک'], $v($r, 'agent')));
        $u = $code !== '' && isset($byCode[$code]) ? $byCode[$code] : null;
        if (!$u && $nm !== '' && count($byName[$nm] ?? []) === 1) $u = $byName[$nm][0];
        $pct = $num($v($r, 'pct'));
        if (!$u) $g['errors'][] = 'کارشناس پیدا نشد: «' . $nm . '»' . ($code !== '' ? ' (کد پرسنلی ' . $code . ')' : '') . ' — کدِ پرسنلی (کد آراد) را در «مدیریت کاربران» ثبت کنید.';
        if ($pct <= 0) $g['errors'][] = 'درصدِ سهمِ «' . $nm . '» خالی یا صفر است.';
        $g['agents'][] = ['user_id' => $u ? (int) $u['id'] : 0, 'name' => $u ? (string) $u['full_name'] : $nm, 'code' => $code, 'pct' => $pct,
            'commission' => $v($r, 'commission')];
        unset($g);
    }
    // بررسیِ هر واریزی
    $refs = array_values(array_filter(array_keys($groups), static fn($k) => $k !== '' && $k[0] !== '?'));
    $exists = [];
    if ($refs && simp_ready($pdo)) {
        $st = $pdo->prepare('SELECT import_ref, id FROM sales_orders WHERE import_ref IN (' . implode(',', array_fill(0, count($refs), '?')) . ')');
        $st->execute(array_map('strval', $refs));
        $exists = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
    $findCust = $pdo->prepare('SELECT id, full_name, owner_user_id FROM customers WHERE mobile_normalized = ? OR mobile2_normalized = ? OR mobile = ? ORDER BY id LIMIT 1');
    foreach ($groups as &$g) {
        if ($g['ref'] === '') continue;
        if (isset($exists[$g['ref']])) $g['exists'] = (int) $exists[$g['ref']];
        if ($g['amount'] <= 0) $g['errors'][] = 'مبلغ خالی یا صفر است.';
        if (!$g['date']) $g['errors'][] = 'تاریخ نامعتبر است: «' . $g['date_raw'] . '» (باید مثلِ ۱۴۰۵۰۷۰۱ باشد).';
        elseif ($g['date'] > date('Y-m-d')) $g['errors'][] = 'تاریخِ واریز در آینده است.';
        if (!preg_match('/^09\d{9}$/', $g['mobile'])) $g['errors'][] = 'شماره همراهِ تاجر نامعتبر است: «' . $g['mobile'] . '».';
        $ids = array_column($g['agents'], 'user_id');
        if (count(array_filter($ids)) !== count(array_unique(array_filter($ids)))) $g['errors'][] = 'یک کارشناس دوبار در همین واریزی آمده است.';
        // سهم‌گیرندگانِ واقعی (ردیفِ «بررسی» وقتی جمع ۱۰۰ نیست = فقط ثبت‌کننده، بدونِ سهم)
        $share = simp_share_flags($g['agents']);
        $sum = 0.0;
        foreach ($g['agents'] as $k => $a) if ($share[$k]) $sum += $a['pct'];
        $g['pct_sum'] = $sum;
        if ($sum > 0 && abs($sum - 100) > 0.01) {
            $g['warnings'][] = 'جمعِ درصدها ' . to_persian_digits((string) round($sum, 2)) . ' است (نه ۱۰۰)؛ مبلغ به همان نسبت بینِ سهم‌گیرندگان تقسیم می‌شود.';
        }
        // تقسیمِ مبلغ بینِ سهم‌گیرندگان (جمع = مبلغِ واریزی)
        $left = $g['amount'];
        $last = max(array_keys(array_filter($share)) ?: [0]);
        foreach ($g['agents'] as $k => &$a) {
            $a['share'] = $share[$k];
            if (!$share[$k]) { $a['amount'] = 0; continue; }
            $a['amount'] = $k === $last ? $left : (int) round($g['amount'] * $a['pct'] / max(0.0001, $sum));
            $left -= $a['amount'];
        }
        unset($a);
        $g['customer'] = null;
        if (preg_match('/^09\d{9}$/', $g['mobile'])) {
            $norm = function_exists('normalize_phone_for_match') ? (normalize_phone_for_match($g['mobile']) ?? $g['mobile']) : $g['mobile'];
            $findCust->execute([$norm, $norm, $g['mobile']]);
            $g['customer'] = $findCust->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    unset($g);
    return ['ok' => true, 'message' => '', 'groups' => array_values($groups)];
}

/**
 * ورودِ یک واریزی. $perf = سهم عملکرد طبقِ قانونِ فعلی محاسبه شود؟
 * @return array{ok:bool, message:string, order_id?:int}
 */
function simp_import_group(PDO $pdo, array $g, bool $perf, int $userId): array
{
    if (!simp_ready($pdo) || !scr_ready($pdo)) return ['ok' => false, 'message' => 'جدول‌ها آماده نیستند.'];
    if (!empty($g['errors'])) return ['ok' => false, 'message' => implode(' ', $g['errors'])];
    $chk = $pdo->prepare('SELECT id FROM sales_orders WHERE import_ref = ? LIMIT 1');
    $chk->execute([$g['ref']]);
    if ($id = (int) $chk->fetchColumn()) return ['ok' => false, 'message' => 'قبلاً وارد شده (سفارش #' . $id . ').', 'order_id' => $id];
    $seller = (int) $g['agents'][0]['user_id'];
    $amount = (int) $g['amount'];
    $net = (int) round($amount / 1.1);           // مبلغ با ۱۰٪ مالیات (همان «خالص فیش» در اکسل)
    $tax = $amount - $net;
    $at = $g['date'] . ' 12:00:00';
    $now = date('Y-m-d H:i:s');
    $note = 'واردشده از اکسلِ واریزی‌های قبل از سامانه — شماره کار ' . $g['ref']
        . ($g['plan'] !== '' ? ' — طرح: ' . $g['plan'] : '') . ($g['kind'] !== '' ? ' — ' . $g['kind'] : '')
        . ($g['account'] !== '' ? ' — حساب: ' . $g['account'] : '');
    $pdo->beginTransaction();
    try {
        // مشتری: با موبایل پیدا می‌شود؛ وگرنه ساخته می‌شود (مالک = سهم‌گیرِ اول)
        $cust = $g['customer'] ?? null;
        if (!$cust) {
            $jd = to_jalali($g['date']);
            $pdo->prepare("INSERT INTO customers (owner_user_id, full_name, mobile, mobile_2, initial_contact_date, status, contact_type, city, description, created_at)
                VALUES (?,?,?,?,?,'خرید کرده','customer',?,?,?)")
                ->execute([$seller, mb_substr($g['name'] ?: 'مشتری ' . $g['mobile'], 0, 150), $g['mobile'], ($g['mobile2'] ?? '') !== '' ? $g['mobile2'] : null, $g['date'], $g['city'] !== '' ? mb_substr($g['city'], 0, 100) : null,
                    'از اکسلِ واریزی‌های قبل از سامانه (' . $jd . ')' . ($g['nid'] !== '' ? ' — کد ملی: ' . $g['nid'] : ''), $at]);
            $cid = (int) $pdo->lastInsertId();
            if (function_exists('sync_customer_phone_normalized')) sync_customer_phone_normalized($pdo, $cid, $g['mobile'], ($g['mobile2'] ?? '') !== '' ? $g['mobile2'] : null);
            $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)')
                ->execute([$cid, $userId, 'create', 'از اکسلِ واریزی‌های قبل از سامانه ثبت شد (شماره کار ' . $g['ref'] . ').']);
            $cust = ['id' => $cid, 'owner_user_id' => $seller];
        }
        $cid = (int) $cust['id'];
        $pdo->prepare("INSERT INTO sales_orders (order_number, quote_id, customer_id, seller_user_id, customer_owner_id, subtotal, tax_percent, tax_amount, total_amount, paid_amount,
                payment_method, payment_date, payment_ref, payer_name, seller_note, status, finance_user_id, finance_note, confirmed_amount, decided_at, submitted_at, created_at,
                is_legacy, legacy_note, settle_type, import_ref, import_perf)
            VALUES (?,0,?,?,?,?,10,?,?,?, 'bank_deposit', ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?, 0, ?, 'full', ?, ?)")
            ->execute([SIMP_PREFIX . $g['ref'], $cid, $seller, (int) $cust['owner_user_id'], $net, $tax, $amount, $amount,
                $g['date'], $g['payref'] !== '' ? mb_substr($g['payref'], 0, 100) : null, mb_substr($g['name'], 0, 150) ?: null, $note,
                $userId, 'ورود از اکسل (قبل از سامانه)', $amount, $at, $at, $at, mb_substr($note, 0, 500), $g['ref'], $perf ? 1 : 0]);
        $oid = (int) $pdo->lastInsertId();
        $title = $g['plan'] !== '' ? mb_substr('واریزیِ قبل از سامانه — ' . $g['plan'], 0, 255) : 'واریزیِ قبل از سامانه';
        $pdo->prepare('INSERT INTO sales_order_items (order_id, service_id, title, unit, unit_price, quantity, amount) VALUES (?,NULL,?,NULL,?,1,?)')
            ->execute([$oid, $title, $net, $net]);
        $pdo->prepare("INSERT INTO sales_order_payments (order_id, customer_id, kind, amount, paid_at, method, ref, note, status, recorded_by, decided_by, decided_at, created_at)
            VALUES (?,?,'initial',?,?,'bank_deposit',?,?,'confirmed',?,?,?,?)")
            ->execute([$oid, $cid, $amount, $g['date'], $g['payref'] !== '' ? mb_substr($g['payref'], 0, 100) : null, 'ورود از اکسل', $userId, $userId, $at, $at]);
        // سهم‌گیرندگان ← فروشِ مشترک (فقط اگر بیش از یک نفر یا کسی غیر از ثبت‌کننده)
        $sharers = array_values(array_filter($g['agents'], static fn($a) => ($a['share'] ?? true) && $a['amount'] > 0));
        if (count($sharers) > 1 || ($sharers && (int) $sharers[0]['user_id'] !== $seller)) {
            $ins = scr_has_payment_col($pdo)
                ? $pdo->prepare('INSERT INTO sales_order_credit_splits (order_id, payment_id, user_id, amount, created_by, created_at) VALUES (?,NULL,?,?,?,?)')
                : $pdo->prepare('INSERT INTO sales_order_credit_splits (order_id, user_id, amount, created_by, created_at) VALUES (?,?,?,?,?)');
            foreach ($sharers as $a) {
                if (scr_has_payment_col($pdo)) $ins->execute([$oid, $a['user_id'], $a['amount'], $userId, $now]);
                else $ins->execute([$oid, $a['user_id'], $a['amount'], $userId, $now]);
            }
        }
        $shares = implode(' | ', array_map(static fn($a) => $a['name'] . ' ' . round($a['pct'], 2) . '٪' . ($a['commission'] !== '' ? ' (پورسانتِ اکسل: ' . $a['commission'] . ')' : '')
            . (($a['share'] ?? true) ? '' : ' [فقط ثبت‌کننده، بدونِ سهم]'), $g['agents']));
        orders_add_history($pdo, $oid, $userId, 'note', null, null, $note . ' — سهم‌ها: ' . $shares
            . ' — سهم عملکرد: ' . ($perf ? 'طبقِ قانونِ فعلی محاسبه شد' : 'محاسبه نمی‌شود') . ' — تیکت/استارز: ندارد.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('simp_import_group ' . $g['ref'] . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => 'خطا در ثبت: ' . $e->getMessage()];
    }
    if ($perf) {
        try {
            require_once __DIR__ . '/performance_functions.php';
            if (function_exists('perf_ready') && perf_ready($pdo)) ps_sync_order($pdo, $oid, $userId);
        } catch (Throwable $e) {
            error_log('simp perf ' . $oid . ': ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'message' => 'ثبت شد.', 'order_id' => $oid];
}

/**
 * یک‌بار: اصلاحِ «فروشِ مشترک»ِ واریزی‌هایی که قبل از قاعده‌ی «ردیفِ بررسی = فقط ثبت‌کننده» وارد شده بودند.
 * سهم‌ها از یادداشتِ ثبتِ همان سفارش (فهرستِ ردیف‌های اکسل با درصد و پورسانت) دوباره خوانده می‌شوند؛
 * فقط سفارش‌هایی تغییر می‌کنند که در آن‌ها کسی به‌اشتباه سهم گرفته بود. سهم عملکرد دست نمی‌خورد.
 * @return int تعدادِ سفارش‌های اصلاح‌شده
 */
function simp_fix_shares_v1(PDO $pdo): int
{
    $flag = __DIR__ . '/../storage/.sales_import_shares_v1';
    if (is_file($flag) || !simp_ready($pdo) || !scr_ready($pdo)) return 0;
    $fixed = 0;
    try {
        $orders = $pdo->query('SELECT id, seller_user_id, total_amount, import_ref FROM sales_orders WHERE import_ref IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $note = $pdo->prepare("SELECT note FROM sales_order_history WHERE order_id = ? AND note LIKE '%سهم‌ها: %' ORDER BY id LIMIT 1");
        $cur = $pdo->prepare('SELECT user_id, amount FROM sales_order_credit_splits WHERE order_id = ?');
        $byName = $pdo->prepare('SELECT id FROM users WHERE full_name = ?');
        $hasPay = scr_has_payment_col($pdo);
        foreach ($orders as $o) {
            $oid = (int) $o['id'];
            $note->execute([$oid]);
            $txt = (string) $note->fetchColumn();
            if (!preg_match('/سهم‌ها: (.+?)(?: — سهم عملکرد:|$)/u', $txt, $m)) continue;
            $agents = [];
            foreach (explode(' | ', $m[1]) as $part) {
                if (!preg_match('/^(.+?) ([\d.]+)٪(?: \(پورسانتِ اکسل: (.*?)\))?(?: \[.*\])?$/u', trim($part), $p)) { $agents = []; break; }
                $agents[] = ['name' => $p[1], 'pct' => (float) $p[2], 'commission' => (string) ($p[3] ?? '')];
            }
            if (count($agents) < 2) continue;
            $flags = simp_share_flags($agents);
            if (!in_array(false, $flags, true)) continue; // همه سهم داشتند ← قبلاً درست ثبت شده
            // نام ← کاربر (اول از میانِ سهم‌گیرندگانِ فعلیِ همین سفارش و فروشنده)
            $cur->execute([$oid]);
            $known = array_map('intval', array_column($cur->fetchAll(PDO::FETCH_ASSOC) ?: [], 'user_id'));
            $known[] = (int) $o['seller_user_id'];
            $ok = true;
            foreach ($agents as &$a) {
                $byName->execute([$a['name']]);
                $ids = array_map('intval', $byName->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $pick = array_values(array_intersect($ids, $known));
                $a['user_id'] = $pick[0] ?? (count($ids) === 1 ? $ids[0] : 0);
                if (!$a['user_id']) $ok = false;
            }
            unset($a);
            if (!$ok) { error_log('simp_fix_shares_v1: order ' . $oid . ' — agent not resolved'); continue; }
            $total = (int) $o['total_amount'];
            $sum = 0.0;
            foreach ($agents as $k => $a) if ($flags[$k]) $sum += $a['pct'];
            $sharers = [];
            $left = $total;
            $keys = array_keys(array_filter($flags));
            foreach ($keys as $i => $k) {
                $amt = $i === count($keys) - 1 ? $left : (int) round($total * $agents[$k]['pct'] / max(0.0001, $sum));
                $left -= $amt;
                if ($amt > 0) $sharers[] = ['user_id' => $agents[$k]['user_id'], 'amount' => $amt, 'name' => $agents[$k]['name']];
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM sales_order_credit_splits WHERE order_id = ?' . ($hasPay ? ' AND payment_id IS NULL' : ''))->execute([$oid]);
                if (count($sharers) > 1 || ($sharers && $sharers[0]['user_id'] !== (int) $o['seller_user_id'])) {
                    $ins = $hasPay
                        ? $pdo->prepare('INSERT INTO sales_order_credit_splits (order_id, payment_id, user_id, amount, created_by, created_at) VALUES (?,NULL,?,?,NULL,NOW())')
                        : $pdo->prepare('INSERT INTO sales_order_credit_splits (order_id, user_id, amount, created_by, created_at) VALUES (?,?,?,NULL,NOW())');
                    foreach ($sharers as $s) $ins->execute([$oid, $s['user_id'], $s['amount']]);
                }
                $out = array_values(array_map(static fn($a) => $a['name'], array_filter($agents, static fn($a, $k) => !$flags[$k], ARRAY_FILTER_USE_BOTH)));
                orders_add_history($pdo, $oid, null, 'note', null, null, 'اصلاحِ سهمِ فروش (ورود از اکسل): ' . implode('، ', $out)
                    . ' فقط ثبت‌کننده بود (پورسانت «بررسی») و سهم از او برداشته شد. سهم‌گیرندگان: '
                    . implode('، ', array_map(static fn($s) => $s['name'] . ' ' . number_format($s['amount']), $sharers)) . '.');
                $pdo->commit();
                $fixed++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('simp_fix_shares_v1 ' . $oid . ': ' . $e->getMessage());
            }
        }
        @file_put_contents($flag, date('c') . ' fixed=' . $fixed);
    } catch (Throwable $e) {
        error_log('simp_fix_shares_v1: ' . $e->getMessage());
    }
    return $fixed;
}
