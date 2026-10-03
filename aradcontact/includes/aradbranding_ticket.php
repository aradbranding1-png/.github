<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  ارسالِ سفارشِ تأییدشده به «آراد برندینگ» (aradbranding.me) به‌صورتِ تیکت
 * ═══════════════════════════════════════════════════════════════════════
 *  جریان کار:
 *   ۱) واحد مالی سفارشِ ثبت‌شده توسطِ کارشناس را «تأیید و ثبت» می‌کند.
 *   ۲) یک تیکت با متنِ آماده (قالب‌دار؛ از پنل مدیریت قابلِ ویرایش) برای همان سفارش ساخته می‌شود:
 *      چه خدماتی، شماره‌ی فاکتور/قرارداد، مبلغ و … برای مشتری.
 *   ۳) اگر اتصالِ API آراد برندینگ تنظیم و فعال باشد، تیکت خودکار ارسال می‌شود؛ وگرنه در صفحه‌ی
 *      سفارش «آماده‌ی ارسال» می‌ماند تا بعد از تنظیمِ اتصال با یک کلیک ارسال شود (یا دستی ثبت و
 *      به‌عنوانِ «ارسال‌شده» علامت بخورد).
 *   ۴) مشتری در aradbranding.me به تیکت پاسخ می‌دهد.
 *
 *  هر سفارش حداکثر یک تیکت دارد (با تأییدِ دوباره‌ی سفارش، تیکتِ ارسال‌شده دوباره فرستاده نمی‌شود).
 *  شکلِ درخواستِ API (نام فیلدها، احراز هویت، فیلدهای ثابت مثل دپارتمان) از پنل تنظیم می‌شود.
 */

if (!defined('ABT_SCHEMA_FLAG')) {
    define('ABT_SCHEMA_FLAG', __DIR__ . '/../storage/.aradbranding_ticket_schema_v1');
}

function abt_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (is_file(ABT_SCHEMA_FLAG)) {
        abt_schema_v2($pdo);
        abt_schema_v3($pdo);
        abt_schema_v4($pdo);
        abt_schema_v5($pdo);
        return $ready = true;
    }
    $ddl = [
        "CREATE TABLE IF NOT EXISTS `aradbranding_tickets` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `order_id` INT UNSIGNED NOT NULL,
          `customer_id` INT UNSIGNED NOT NULL,
          `subject` VARCHAR(255) NOT NULL,
          `message` MEDIUMTEXT NOT NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
          `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
          `external_id` VARCHAR(100) DEFAULT NULL,
          `external_url` VARCHAR(500) DEFAULT NULL,
          `http_code` INT DEFAULT NULL,
          `response_body` TEXT,
          `last_error` VARCHAR(500) DEFAULT NULL,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `sent_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          `sent_at` DATETIME DEFAULT NULL,
          `updated_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_abt_order` (`order_id`),
          KEY `idx_abt_customer` (`customer_id`),
          KEY `idx_abt_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `aradbranding_ticket_settings` (
          `k` VARCHAR(60) NOT NULL,
          `v` MEDIUMTEXT,
          `updated_by` INT UNSIGNED DEFAULT NULL,
          `updated_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('abt_ready: ' . $e->getMessage());
        }
    }
    try {
        $pdo->query('SELECT 1 FROM aradbranding_tickets LIMIT 1');
        $pdo->query('SELECT 1 FROM aradbranding_ticket_settings LIMIT 1');
    } catch (Throwable $e) {
        return $ready = false;
    }
    if (!is_dir(dirname(ABT_SCHEMA_FLAG))) {
        @mkdir(dirname(ABT_SCHEMA_FLAG), 0755, true);
    }
    @file_put_contents(ABT_SCHEMA_FLAG, (string) time());
    abt_schema_v2($pdo);
    abt_schema_v3($pdo);
    abt_schema_v4($pdo);
    abt_schema_v5($pdo);
    return $ready = true;
}

/**
 * نسخه‌ی ۵: حسابِ مشتری در آراد برندینگ (به ازای هر شخص در پروفایل ۳۶۰).
 *   status = found   ← یکی از موبایل‌های پروفایل ۳۶۰ در آراد برندینگ حساب داشت (تیکت‌های بعدی هم با همین شماره)
 *   status = created ← هیچ شماره‌ای حساب نداشت؛ حسابِ جدید ساخته شد (نام کاربری/رمز باید برای مشتری پیامک شود)
 * + ستونِ phone_used روی هر تیکت (شماره‌ای که تیکت با آن ثبت شد)
 */
function abt_schema_v5(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.aradbranding_ticket_schema_v5';
    if (is_file($flag)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `aradbranding_accounts` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `person_key` INT UNSIGNED NOT NULL,
          `customer_id` INT UNSIGNED NOT NULL,
          `mobile` VARCHAR(20) NOT NULL,
          `status` VARCHAR(12) NOT NULL,
          `username` VARCHAR(120) DEFAULT NULL,
          `password` VARCHAR(120) DEFAULT NULL,
          `external_user_id` VARCHAR(100) DEFAULT NULL,
          `welcome_ticket_id` VARCHAR(100) DEFAULT NULL,
          `welcome_error` VARCHAR(500) DEFAULT NULL,
          `order_id` INT UNSIGNED DEFAULT NULL,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          `sms_done_by` INT UNSIGNED DEFAULT NULL,
          `sms_done_at` DATETIME DEFAULT NULL,
          `response_body` TEXT,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_aba_person` (`person_key`),
          KEY `idx_aba_customer` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->query('SELECT phone_used FROM aradbranding_tickets LIMIT 1'); }
        catch (Throwable $e) { $pdo->exec('ALTER TABLE aradbranding_tickets ADD COLUMN phone_used VARCHAR(20) DEFAULT NULL'); }
    } catch (Throwable $e) {
        error_log('abt_schema_v5: ' . $e->getMessage());
        return;
    }
    @file_put_contents($flag, (string) time());
}

/** نسخه‌ی ۲: یک تیکت به ازای هر خدمتِ سفارش (item_id) + واحد/دپارتمان */
function abt_schema_v2(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.aradbranding_ticket_schema_v2';
    if (is_file($flag)) return;
    foreach ([
        'item_id'       => 'ALTER TABLE aradbranding_tickets ADD COLUMN item_id INT UNSIGNED DEFAULT NULL AFTER order_id',
        'service_title' => 'ALTER TABLE aradbranding_tickets ADD COLUMN service_title VARCHAR(255) DEFAULT NULL AFTER item_id',
        'department'    => 'ALTER TABLE aradbranding_tickets ADD COLUMN department VARCHAR(100) DEFAULT NULL AFTER service_title',
    ] as $col => $sql) {
        try {
            $pdo->query("SELECT `$col` FROM aradbranding_tickets LIMIT 1");
        } catch (Throwable $e) {
            try { $pdo->exec($sql); } catch (Throwable $e2) { error_log('abt_schema_v2: ' . $e2->getMessage()); return; }
        }
    }
    try { $pdo->exec('ALTER TABLE aradbranding_tickets DROP INDEX uq_abt_order'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE aradbranding_tickets ADD UNIQUE KEY uq_abt_order_item (order_id, item_id)'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE aradbranding_tickets ADD KEY idx_abt_order (order_id)'); } catch (Throwable $e) {}
    @file_put_contents($flag, (string) time());
}

/** نسخه‌ی ۴: فهرستِ اسنادِ پیوستِ تیکت (مثلاً لینک‌های قرارداد) */
function abt_schema_v4(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.aradbranding_ticket_schema_v4';
    if (is_file($flag)) return;
    try { $pdo->query('SELECT attachments_json FROM aradbranding_tickets LIMIT 1'); }
    catch (Throwable $e) {
        try { $pdo->exec('ALTER TABLE aradbranding_tickets ADD COLUMN attachments_json TEXT NULL'); }
        catch (Throwable $e2) { error_log('abt_schema_v4: ' . $e2->getMessage()); return; }
    }
    @file_put_contents($flag, (string) time());
}

/** نسخه‌ی ۳: شمارنده‌ی «ارسالِ مجدد» (برای شناسه‌ی یکتای جدید وقتی تیکت در سایتِ اصلی حذف شده) */
function abt_schema_v3(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.aradbranding_ticket_schema_v3';
    if (is_file($flag)) return;
    try { $pdo->query('SELECT resend_count FROM aradbranding_tickets LIMIT 1'); }
    catch (Throwable $e) {
        try { $pdo->exec('ALTER TABLE aradbranding_tickets ADD COLUMN resend_count INT UNSIGNED NOT NULL DEFAULT 0'); }
        catch (Throwable $e2) { error_log('abt_schema_v3: ' . $e2->getMessage()); return; }
    }
    @file_put_contents($flag, (string) time());
}

/* =========================================================================
   وضعیت‌ها و دسترسی
   ========================================================================= */

function abt_statuses(): array
{
    return [
        'queued' => ['label' => 'آماده‌ی ارسال', 'color' => 'warning',   'icon' => 'fa-hourglass-half'],
        'sent'   => ['label' => 'ارسال شد',      'color' => 'success',   'icon' => 'fa-circle-check'],
        'manual' => ['label' => 'ثبتِ دستی',     'color' => 'info',      'icon' => 'fa-hand'],
        'failed' => ['label' => 'ارسال ناموفق',  'color' => 'danger',    'icon' => 'fa-triangle-exclamation'],
        'skipped' => ['label' => 'ارسال نشود',   'color' => 'secondary', 'icon' => 'fa-ban'],
        'bundled' => ['label' => 'انجام شد (در تیکتِ دیگرِ همین سفارش اعلام شد)', 'color' => 'success', 'icon' => 'fa-check-double'],
        'deleted' => ['label' => 'حذف‌شده از آراد برندینگ', 'color' => 'dark', 'icon' => 'fa-trash-can'],
    ];
}

/** ارسال/ویرایشِ تیکت: همان کسانی که سفارش را تأیید می‌کنند */
function abt_can_manage(array $user): bool
{
    return (function_exists('is_super_admin') && is_super_admin($user)) || user_can('finance_orders_decide', $user);
}

/* =========================================================================
   تنظیمات
   ========================================================================= */

function abt_default_subject(): string
{
    return '«نام_خدمت» — سفارش «شماره_سفارش»';
}

function abt_default_body(): string
{
    return <<<'TPL'
«عنوان» «نام_مشتری» گرامی، سلام و وقت بخیر

با سپاس از اعتماد شما به آراد برندینگ؛ سفارشِ شما به شماره‌ی «شماره_سفارش» در تاریخ «تاریخ_تایید» توسطِ واحد مالی تأیید و برای اجرا به واحدهای مربوطه ارسال شد.

خدمت: «نام_خدمت»
مقدار: «مقدار_و_واحد»

شماره قرارداد: «شماره_قرارداد»
کارشناسِ شما: «کارشناس»

لطفاً برای آغازِ کار، به همین تیکت پاسخ دهید و اطلاعاتِ لازم (از جمله نام محصول، کمیت و کیفیتِ آن) را اعلام نمایید. طبقِ قرارداد، کلیه‌ی درخواست‌ها و هماهنگی‌ها فقط از طریقِ تیکت در همین سامانه رسمیت دارد و پاسخ به تیکت‌ها حداکثر ظرف ۲۴ ساعت لازم است.

با سپاس
آراد برندینگ
TPL;
}

/** متنِ پیش‌فرضِ تیکتِ اسنادِ قرارداد */
function abt_default_contract_body(): string
{
    return <<<'TPL'
«عنوان» «نام_مشتری» گرامی، سلام و وقت بخیر

اسنادِ قرارداد شماره‌ی «شماره_قرارداد» آراد برندینگ برای شما آماده است:

«لینک_اسناد»

هر لینک یک سندِ جداست و تا «تاریخ_اعتبار» معتبر است. برای ذخیره، در صفحه‌ی بازشده «دانلود / ذخیره PDF» را بزنید.
لطفاً پس از مطالعه، قرارداد را امضا و از طریقِ همین تیکت ارسال نمایید.

با سپاس
آراد برندینگ
TPL;
}

function abt_settings_defaults(): array
{
    return [
        'enabled'         => '0',   // اتصالِ API فعال است
        'auto_send'       => '1',   // با تأییدِ مالی خودکار ارسال شود
        'api_url'         => '',
        'body_format'     => 'json', // json | form
        'auth_style'      => 'header', // none | header | bearer | query
        'auth_name'       => 'X-Api-Key',
        'auth_key'        => '',
        'field_phone'     => 'mobile',
        'field_name'      => 'name',
        'field_national'  => 'national_id',
        'field_subject'   => 'subject',
        'field_message'   => 'message',
        'field_ref'       => 'reference',
        'field_external'  => 'external_id',
        'departments_json' => '',
        // ─── تیکتِ «اسنادِ قرارداد» (از صفحه‌ی قرارداد ← ارسال ← تیکت در آراد برندینگ) ───
        'ctr_department'   => 'قرارداد',   // شناسه (مثلاً ۳۸) یا نامِ دقیقِ واحد
        'ctr_subject_tpl'  => 'اسناد قرارداد «شماره_قرارداد» — آراد برندینگ',
        'ctr_body_tpl'     => '',          // خالی = متنِ پیش‌فرض (abt_default_contract_body)
        'ctr_link_days'    => '30',        // اعتبارِ لینکِ هر سند (روز)
        'field_attachments' => '',
        // ─── سامانه‌ی آموزش (edu.aradbranding.me): اعمالِ خرید پیش از ارسالِ تیکتِ خدماتِ آموزشی ───
        'edu_enabled'      => '0',
        'edu_base_url'     => 'https://edu.aradbranding.me',
        'edu_token'        => '',
        'edu_map_json'     => '',   // {service_id: code | "-"}
        // ─── سامانه‌ی CRM (crm.aradbranding.me): ساختِ شرکت پیش از ارسالِ تیکتِ خدمتِ CRM ───
        'ticket_view_url'  => 'https://my.aradbranding.me/tickets/{id}', // لینکِ مشاهده‌ی هر تیکت در آراد برندینگ
        'crm_enabled'      => '0',
        'crm_base_url'     => 'https://crm.aradbranding.me',
        'crm_token'        => '',
        'crm_login_url'    => 'https://crm.aradbranding.me/login.php',
        'crm_map_json'     => '',   // {service_id: basic|professional|enterprise | "-"}         // نامِ فیلدِ API برای فهرستِ اسناد [{title,url}] — خالی = ارسال نشود // آخرین فهرستِ واحدهای دریافت‌شده از آراد برندینگ: {"48":"آموزش و اطلاعات", …} // شناسه‌ی یکتای هر تیکت (arad-contact-<id>) — جلوی ثبتِ تکراری در ارسالِ دوباره را می‌گیرد
        // ─── سامانه توسعه تجارت (aradbranding.app): شارژِ استارز با تأییدِ هر پول (includes/trade_stars.php) ───
        'ts_enabled'       => '0',
        'ts_base_url'      => 'https://aradbranding.app',
        'ts_token'         => '',
        'ts_login_url'     => 'https://aradbranding.app',
        'ts_service_ids'   => '',   // [service_id, …] — خالی = از روی عنوان («توسعه تجارت»)
        'ts_department'    => '',
        'ts_subject_new'   => '',
        'ts_body_new'      => '',
        'ts_subject_topup' => '',
        'ts_body_topup'    => '',
        'field_department' => 'department',
        'default_department' => '',
        'extra_json'      => '{"department": "sales"}',
        'verify_ssl'      => '1',
        'subject_tpl'     => abt_default_subject(),
        'body_tpl'        => abt_default_body(),
        // ─── ساختِ حساب در آراد برندینگ وقتی هیچ موبایلِ مشتری (پروفایل ۳۶۰) تاجرِ ثبت‌شده نیست ───
        'acc_enabled'        => '0',
        'acc_api_url'        => '',     // POST — آدرسِ API ساختِ حسابِ تاجر (همان احرازِ هویتِ API تیکت)
        'acc_field_mobile'   => 'mobile',
        'acc_field_name'     => '',     // نامِ کامل در یک فیلد (API فعلی: first_name / last_name جدا)
        'acc_field_first_name' => 'first_name',
        'acc_field_last_name'  => 'last_name',
        'acc_field_father'     => 'father_name',
        'acc_field_password' => 'password',
        'acc_field_national' => 'national_id',
        'acc_extra_json'     => '',
        'acc_login_url'      => 'https://my.aradbranding.me',
        'acc_department'     => '',     // واحدِ تیکتِ «اطلاعاتِ حساب» — خالی = واحدِ پیش‌فرض
        'acc_subject_tpl'    => 'اطلاعات حساب کاربری شما در آراد برندینگ',
        'acc_body_tpl'       => abt_default_account_body(),
    ];
}

/** متنِ پیش‌فرضِ تیکتِ «حسابِ جدید» (اطلاعاتِ ورود + همه‌ی خدماتِ خریداری‌شده) */
function abt_default_account_body(): string
{
    return <<<TPL
«عنوان» «نام_مشتری» گرامی، سلام

به آراد برندینگ خوش آمدید. برای شما حسابِ کاربری ساخته شد تا پیگیریِ خدمات و تیکت‌هایتان را از همین‌جا انجام دهید.

اطلاعاتِ ورود به حساب:
آدرس ورود: «آدرس_ورود»
نام کاربری: «نام_کاربری»
رمز عبور: «رمز_عبور»
(لطفاً بعد از اولین ورود، رمز عبور را تغییر دهید.)

خدماتی که از آراد برندینگ خریداری کرده‌اید:
«فهرست_همه_خدمات»

برای هر خدمت یک تیکتِ جداگانه در همین حساب برایتان ثبت می‌شود.

با سپاس
آراد برندینگ
TPL;
}

function abt_settings(PDO $pdo): array
{
    $s = abt_settings_defaults();
    if (!abt_ready($pdo)) {
        return $s;
    }
    try {
        foreach ($pdo->query('SELECT k, v FROM aradbranding_ticket_settings') as $r) {
            if (array_key_exists((string) $r['k'], $s)) {
                $s[(string) $r['k']] = (string) $r['v'];
            }
        }
    } catch (Throwable $e) {
    }
    return $s;
}

function abt_settings_save(PDO $pdo, array $data, int $userId): void
{
    $defaults = abt_settings_defaults();
    $st = $pdo->prepare('INSERT INTO aradbranding_ticket_settings (k, v, updated_by, updated_at) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    $now = date('Y-m-d H:i:s');
    foreach ($data as $k => $v) {
        if (array_key_exists($k, $defaults)) {
            $st->execute([$k, (string) $v, $userId, $now]);
        }
    }
}

/** آیا اتصال آماده‌ی ارسالِ واقعی است؟ */
function abt_connection_ready(array $s): bool
{
    return $s['enabled'] === '1' && preg_match('#^https?://#i', trim($s['api_url']));
}

/* =========================================================================
   متنِ تیکت
   ========================================================================= */

function abt_placeholders(): array
{
    return [
        'عنوان'          => 'آقای / خانم',
        'نام_مشتری'      => 'نام و نام خانوادگی مشتری',
        'موبایل'          => 'موبایل مشتری',
        'شماره_سفارش'     => 'شماره فاکتور / سفارش',
        'شماره_پیش_فاکتور' => 'شماره پیش‌فاکتورِ مبنا',
        'شماره_قرارداد'   => 'شماره قرارداد (اگر ساخته شده)',
        'تاریخ_تایید'     => 'تاریخِ تأییدِ مالی',
        'فهرست_خدمات'    => 'فهرستِ خدمات (هر خدمت در یک خط، با تعداد)',
        'تعداد_خدمات'    => 'تعداد ردیف‌های خدمات',
        'مبلغ_فاکتور'     => 'مبلغ فاکتور (تومان)',
        'مبلغ_پرداختی'    => 'مبلغ پرداختِ تأییدشده (تومان)',
        'مانده_بدهی'      => 'مانده‌ی بدهی (تومان)',
        'کارشناس'        => 'نام کارشناسِ ثبت‌کننده‌ی سفارش',
        'نام_خدمت'       => 'نام خدمتِ همین تیکت',
        'مقدار'          => 'مقدار (عدد)',
        'واحد'           => 'واحدِ خدمت (سالانه، صفحه، عدد…)',
        'مقدار_و_واحد'   => 'مثلاً «۵ صفحه» یا «۱ سالانه»',
        'شرح_خدمت'       => 'شرحِ خدمت (از لیست خدمات)',
    ];
}

/** مقادیرِ متغیرها برای یک سفارش */
function abt_vars(PDO $pdo, array $order, ?array $item = null): array
{
    $kyc = function_exists('kyc_get') ? kyc_get($pdo, (int) $order['customer_id']) : [];
    $items = function_exists('orders_items') ? orders_items($pdo, (int) $order['id']) : [];
    $lines = [];
    foreach ($items as $i => $it) {
        $qty = (float) ($it['quantity'] ?? 1);
        $qtyTxt = ($qty != 1.0 || trim((string) ($it['unit'] ?? '')) !== '')
            ? ' — ' . to_persian_digits(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')) . ' ' . trim((string) ($it['unit'] ?? ''))
            : '';
        $lines[] = to_persian_digits((string) ($i + 1)) . '. ' . trim((string) $it['title']) . rtrim($qtyTxt);
    }
    $total = (int) $order['total_amount'];
    $paid = (int) ($order['confirmed_amount'] ?? $order['paid_amount'] ?? 0);
    $balance = $total - $paid;
    if (function_exists('finance_schema_ready') && finance_schema_ready($pdo) && function_exists('fin_compute')) {
        try {
            $f = fin_compute($order, fin_payments($pdo, (int) $order['id']), fin_installments($pdo, (int) $order['id']));
            $paid = (int) $f['paid'];
            $balance = (int) $f['balance'];
        } catch (Throwable $e) {
        }
    }
    $contractNo = '';
    if (function_exists('ctr_for_quote')) {
        $c = ctr_for_quote($pdo, (int) $order['quote_id']);
        if ($c) {
            $contractNo = "\u{2066}" . to_persian_digits((string) $c['contract_number']) . "\u{2069}";
        }
    }
    $money = static fn(int $n): string => to_persian_digits(number_format(max(0, $n)));
    return [
        'عنوان'          => trim((string) ($kyc['title'] ?? '')),
        'نام_مشتری'      => trim((string) ($order['customer_name'] ?? '')),
        'موبایل'          => to_persian_digits((string) ($order['customer_mobile'] ?? '')),
        'شماره_سفارش'     => "\u{2066}" . to_persian_digits((string) $order['order_number']) . "\u{2069}",
        'شماره_پیش_فاکتور' => "\u{2066}" . to_persian_digits((string) ($order['quote_number'] ?? '')) . "\u{2069}",
        'شماره_قرارداد'   => $contractNo !== '' ? $contractNo : '—',
        'تاریخ_تایید'     => to_jalali(substr((string) ($order['decided_at'] ?? date('Y-m-d')), 0, 10) ?: date('Y-m-d')),
        'فهرست_خدمات'    => $lines ? implode("\n", $lines) : '—',
        'تعداد_خدمات'    => to_persian_digits((string) count($items)),
        'مبلغ_فاکتور'     => $money($total),
        'مبلغ_پرداختی'    => $money($paid),
        'مانده_بدهی'      => $money($balance),
        'کارشناس'        => trim((string) ($order['seller_name'] ?? '')) ?: '—',
    ] + abt_item_vars($item);
}

/** متغیرهای خدمتِ همین تیکت */
function abt_item_vars(?array $item): array
{
    if (!$item) {
        return ['نام_خدمت' => '—', 'مقدار' => '', 'واحد' => '', 'مقدار_و_واحد' => '', 'شرح_خدمت' => ''];
    }
    $qty = (float) ($item['quantity'] ?? 1);
    $qtyTxt = to_persian_digits(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'));
    $unit = trim((string) ($item['unit'] ?? ''));
    return [
        'نام_خدمت'     => trim((string) ($item['title'] ?? '')),
        'مقدار'        => $qtyTxt,
        'واحد'         => $unit,
        'مقدار_و_واحد' => trim($qtyTxt . ' ' . $unit),
        'شرح_خدمت'     => trim((string) ($item['service_description'] ?? '')),
    ];
}

function abt_render(string $tpl, array $vars): string
{
    $tpl = str_replace(["\r\n", "\r"], "\n", $tpl);
    return preg_replace_callback('/«([^«»\n]{1,40})»/u', static function ($m) use ($vars) {
        $k = str_replace(' ', '_', trim($m[1]));
        return array_key_exists($k, $vars) ? (string) $vars[$k] : $m[0];
    }, $tpl);
}

/* =========================================================================
   رکوردِ تیکت
   ========================================================================= */

/** همه‌ی تیکت‌های یک سفارش (یکی به ازای هر خدمت) */
function abt_tickets_for_order(PDO $pdo, int $orderId): array
{
    if (!abt_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT t.*, u.full_name AS sender_name FROM aradbranding_tickets t LEFT JOIN users u ON u.id = t.sent_by WHERE t.order_id = ? ORDER BY t.item_id IS NULL, t.item_id, t.id');
    $st->execute([$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function abt_get_ticket(PDO $pdo, int $ticketId): ?array
{
    if (!abt_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT t.*, u.full_name AS sender_name FROM aradbranding_tickets t LEFT JOIN users u ON u.id = t.sent_by WHERE t.id = ? LIMIT 1');
    $st->execute([$ticketId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** سازگاری با نسخه‌ی قبل: اولین تیکتِ سفارش */
function abt_get_for_order(PDO $pdo, int $orderId): ?array
{
    $all = abt_tickets_for_order($pdo, $orderId);
    return $all[0] ?? null;
}

/** خدمت‌های سفارش + تنظیماتِ تیکتِ هر خدمت (از لیست خدمات) */
function abt_order_items(PDO $pdo, int $orderId): array
{
    $items = function_exists('orders_items') ? orders_items($pdo, $orderId) : [];
    $ready = function_exists('services_ticket_ready') && services_ticket_ready($pdo);
    $desc = function_exists('services_desc_ready') && services_desc_ready($pdo);
    $svc = $pdo->prepare('SELECT ' . ($ready ? 'ticket_subject, ticket_body, ticket_department' : "'' AS ticket_subject, '' AS ticket_body, '' AS ticket_department")
        . ($desc ? ', description' : ", '' AS description") . ' FROM services WHERE id = ? LIMIT 1');
    foreach ($items as &$it) {
        $cfg = [];
        if (!empty($it['service_id'])) {
            $svc->execute([(int) $it['service_id']]);
            $cfg = $svc->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $it['ticket_subject'] = trim((string) ($cfg['ticket_subject'] ?? ''));
        $it['ticket_body'] = trim((string) ($cfg['ticket_body'] ?? ''));
        $it['ticket_department'] = trim((string) ($cfg['ticket_department'] ?? ''));
        $it['service_description'] = trim((string) ($cfg['description'] ?? ''));
    }
    unset($it);
    return $items;
}

/**
 * ساخت یا تازه‌کردنِ تیکت‌های یک سفارش: یک تیکت به ازای هر خدمت، با موضوع/متن/واحدِ همان خدمت
 * (اگر خدمت تنظیمِ اختصاصی نداشت، متنِ عمومی). تیکتِ ارسال‌شده دست نمی‌خورد.
 * $force = true → متن از قالب دوباره ساخته می‌شود (فقط برای $onlyTicketId اگر داده شود).
 */
function abt_prepare_items(PDO $pdo, array $order, int $userId, bool $force = false, ?int $onlyTicketId = null): array
{
    if (!abt_ready($pdo)) return [];
    $s = abt_settings($pdo);
    $existing = [];
    foreach (abt_tickets_for_order($pdo, (int) $order['id']) as $t) {
        $existing[(int) ($t['item_id'] ?? 0)] = $t;
    }
    $now = date('Y-m-d H:i:s');
    foreach (abt_order_items($pdo, (int) $order['id']) as $it) {
        $iid = (int) $it['id'];
        $ex = $existing[$iid] ?? null;
        if ($ex && (in_array($ex['status'], ['sent', 'manual', 'bundled'], true) || !$force || ($onlyTicketId && (int) $ex['id'] !== $onlyTicketId))) {
            continue;
        }
        $vars = abt_vars($pdo, $order, $it);
        $subTpl = $it['ticket_subject'] !== '' ? $it['ticket_subject'] : $s['subject_tpl'];
        $bodyTpl = $it['ticket_body'] !== '' ? $it['ticket_body'] : $s['body_tpl'];
        $dept = $it['ticket_department'] !== '' ? $it['ticket_department'] : trim((string) $s['default_department']);
        $subject = mb_substr(trim(preg_replace('/\s+/u', ' ', abt_render($subTpl, $vars))), 0, 250);
        $message = trim(preg_replace('/^[ \t]+/mu', '', abt_render($bodyTpl, $vars)));
        if ($ex) {
            $pdo->prepare("UPDATE aradbranding_tickets SET subject = ?, message = ?, department = ?, service_title = ?, status = 'queued', last_error = NULL, updated_at = ? WHERE id = ?")
                ->execute([$subject, $message, $dept ?: null, mb_substr((string) $it['title'], 0, 250), $now, (int) $ex['id']]);
        } else {
            try {
                $pdo->prepare('INSERT INTO aradbranding_tickets (order_id, item_id, service_title, department, customer_id, subject, message, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([(int) $order['id'], $iid, mb_substr((string) $it['title'], 0, 250), $dept ?: null, (int) $order['customer_id'], $subject, $message, 'queued', $userId, $now, $now]);
            } catch (Throwable $e) {
                error_log('abt_prepare_items: ' . $e->getMessage());
            }
        }
    }
    return abt_tickets_for_order($pdo, (int) $order['id']);
}

/** سازگاری با نسخه‌ی قبل */
function abt_prepare(PDO $pdo, array $order, int $userId, bool $force = false): ?array
{
    $all = abt_prepare_items($pdo, $order, $userId, $force);
    return $all[0] ?? null;
}

/** ویرایشِ دستیِ عنوان/متن پیش از ارسال */
function abt_update_text(PDO $pdo, array $ticket, string $subject, string $message, ?string $department = null): bool
{
    if (in_array($ticket['status'], ['sent', 'manual', 'bundled'], true)) {
        return false;
    }
    $subject = mb_substr(trim(preg_replace('/\s+/u', ' ', $subject)), 0, 250);
    $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
    if ($subject === '' || $message === '') {
        return false;
    }
    $pdo->prepare('UPDATE aradbranding_tickets SET subject = ?, message = ?, updated_at = ? WHERE id = ?')
        ->execute([$subject, $message, date('Y-m-d H:i:s'), (int) $ticket['id']]);
    if ($department !== null) {
        $pdo->prepare('UPDATE aradbranding_tickets SET department = ? WHERE id = ?')
            ->execute([trim($department) !== '' ? mb_substr(trim($department), 0, 100) : null, (int) $ticket['id']]);
    }
    return true;
}

/* =========================================================================
   ارسال به API
   ========================================================================= */

/** شماره‌ی موبایل به قالبِ ۰۹xxxxxxxxx */
function abt_mobile(string $raw): string
{
    $d = preg_replace('/\D+/', '', normalize_digits($raw));
    if (str_starts_with($d, '0098')) $d = substr($d, 4);
    elseif (str_starts_with($d, '98') && strlen($d) === 12) $d = substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '9') $d = '0' . $d;
    return $d;
}

/** بدنه‌ی درخواست بر اساسِ نام‌فیلدهای تنظیم‌شده */
function abt_payload(PDO $pdo, array $s, array $order, array $ticket): array
{
    $kyc = function_exists('kyc_get') ? kyc_get($pdo, (int) $order['customer_id']) : [];
    $payload = [];
    $extra = json_decode((string) $s['extra_json'], true);
    if (is_array($extra)) {
        $payload = $extra;
    }
    $map = [
        'field_phone'    => abt_mobile((string) ($order['customer_mobile'] ?? '')),
        'field_name'     => (string) ($order['customer_name'] ?? ''),
        // اتباع (پاسپورت/کد فراگیر) در فیلدِ «کد ملی» فرستاده نمی‌شود (سامانه‌ی مقصد کد ملیِ ایرانی را اعتبارسنجی می‌کند)
        'field_national' => ($kyc['id_type'] ?? 'national') === 'national' ? (string) ($kyc['national_id'] ?? '') : '',
        'field_subject'  => (string) $ticket['subject'],
        'field_message'  => (string) $ticket['message'],
        'field_ref'      => (string) $order['order_number'],
        // یکتا برای «هر تیکت» (هر خدمت یک تیکت)؛ در ارسالِ دوباره همان مقدار ← سامانه‌ی مقصد تکراری نمی‌سازد
        // ارسالِ مجدد (تیکت در سایتِ اصلی حذف شده) ← شناسه‌ی جدید، تا سایتِ مقصد آن را «تکراری» حساب نکند
        'field_external' => 'arad-contact-' . (int) $ticket['id'] . ((int) ($ticket['resend_count'] ?? 0) > 0 ? '-r' . (int) $ticket['resend_count'] : ''),
    ];
    // واحد (دپارتمان): تیکت ← واحدِ فعلیِ همان خدمت ← واحدِ پیش‌فرض
    $dept = abt_ticket_department($pdo, $s, $ticket);
    if ($dept !== '') {
        // شناسه‌ی عددی (۴۸ / 48) یا نامِ واحد («آموزش و اطلاعات») ← شناسه‌ی عددی
        $resolved = abt_resolve_department($s, $dept);
        $map['field_department'] = $resolved ?? $dept;
    }
    // اسنادِ پیوست (مثلاً لینک‌های قرارداد): فقط اگر API فیلدش را پشتیبانی کند و نامش در تنظیمات آمده باشد
    $att = json_decode((string) ($ticket['attachments_json'] ?? ''), true);
    if (is_array($att) && $att) {
        $map['field_attachments'] = array_values(array_map(static fn($a) => ['title' => (string) ($a['title'] ?? ''), 'url' => (string) ($a['url'] ?? '')], $att));
    }
    foreach ($map as $cfg => $val) {
        $name = trim((string) ($s[$cfg] ?? ''));
        if ($name !== '') {
            $payload[$name] = $val;
        }
    }
    return $payload;
}

/** شناسه/لینکِ تیکت از پاسخِ API (چند شکلِ رایج) */
function abt_parse_response(string $body): array
{
    $out = ['ok' => null, 'id' => null, 'url' => null, 'error' => null];
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return $out;
    }
    $pools = [$j];
    foreach (['data', 'ticket', 'result'] as $k) {
        if (isset($j[$k]) && is_array($j[$k])) $pools[] = $j[$k];
    }
    foreach ([['success', 'ok'], ['status']] as $keys) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $j)) {
                $v = $j[$k];
                if ($v === false || $v === 0 || in_array(strtolower((string) $v), ['error', 'failed', 'fail', 'false'], true)) {
                    $out['ok'] = false;
                } elseif ($v === true || $v === 1 || in_array(strtolower((string) $v), ['success', 'ok', 'true', 'created'], true)) {
                    $out['ok'] = true;
                }
            }
        }
    }
    if (isset($j['result']) && is_string($j['result'])) {
        $out['ok'] = strtolower($j['result']) === 'success' ? true : (strtolower($j['result']) === 'error' ? false : $out['ok']);
    }
    foreach ($pools as $p) {
        foreach (['ticket_id', 'ticketid', 'tid', 'id', 'ticket_number', 'number'] as $k) {
            if ($out['id'] === null && isset($p[$k]) && is_scalar($p[$k]) && (string) $p[$k] !== '') $out['id'] = mb_substr((string) $p[$k], 0, 100);
        }
        foreach (['ticket_url', 'url', 'link'] as $k) {
            if ($out['url'] === null && isset($p[$k]) && is_string($p[$k]) && preg_match('#^https?://#i', $p[$k])) $out['url'] = mb_substr($p[$k], 0, 500);
        }
        foreach (['message', 'error', 'msg'] as $k) {
            if ($out['error'] === null && isset($p[$k]) && is_string($p[$k])) $out['error'] = mb_substr($p[$k], 0, 300);
        }
    }
    // جزئیاتِ خطا (مثلاً errors: {mobile: ["…"]} یا detail/reason) — تا علتِ واقعی دیده شود، نه فقط پیغامِ کلی
    $details = [];
    foreach (['errors', 'error_details', 'details'] as $k) {
        if (isset($j[$k]) && is_array($j[$k])) {
            array_walk_recursive($j[$k], static function ($v, $field) use (&$details) {
                if (is_scalar($v) && (string) $v !== '') $details[] = (is_string($field) ? $field . ': ' : '') . (string) $v;
            });
        }
    }
    foreach (['detail', 'reason', 'error_code', 'code'] as $k) {
        if (isset($j[$k]) && is_scalar($j[$k]) && (string) $j[$k] !== '' && (string) $j[$k] !== (string) $out['error']) $details[] = $k . ': ' . (string) $j[$k];
    }
    if ($details) $out['error'] = mb_substr(trim(($out['error'] ?? '') . ' (' . implode('؛ ', array_unique($details)) . ')'), 0, 500);
    return $out;
}

/**
 * واحدِ مؤثرِ یک تیکت: ۱) واحدِ ذخیره‌شده روی خودِ تیکت  ۲) واحدِ «فعلیِ» همان خدمت در لیست خدمات
 * (برای تیکت‌هایی که قبل از تعیینِ واحدِ خدمت ساخته شده‌اند — روی تیکت هم ذخیره می‌شود)  ۳) واحدِ پیش‌فرض
 */
function abt_ticket_department(PDO $pdo, array $s, array &$ticket): string
{
    $d = trim((string) ($ticket['department'] ?? ''));
    if ($d !== '') return $d;
    if (!empty($ticket['item_id'])) {
        try {
            $st = $pdo->prepare('SELECT sv.ticket_department FROM sales_order_items i JOIN services sv ON sv.id = i.service_id WHERE i.id = ? LIMIT 1');
            $st->execute([(int) $ticket['item_id']]);
            $d = trim((string) $st->fetchColumn());
        } catch (Throwable $e) {
            $d = '';
        }
        if ($d !== '') {
            try { $pdo->prepare('UPDATE aradbranding_tickets SET department = ? WHERE id = ?')->execute([mb_substr($d, 0, 100), (int) $ticket['id']]); } catch (Throwable $e) {}
            $ticket['department'] = $d;
            return $d;
        }
    }
    return trim((string) ($s['default_department'] ?? ''));
}

/** لینکِ مشاهده‌ی تیکت در آراد برندینگ: لینکی که API برگردانده، وگرنه از روی شماره‌ی تیکت و الگوی تنظیمات */
function abt_ticket_link(array $ticket, ?array $s = null): ?string
{
    $u = trim((string) ($ticket['external_url'] ?? ''));
    if ($u !== '' && preg_match('#^https?://#i', $u)) return $u;
    $id = trim((string) ($ticket['external_id'] ?? ''));
    if ($id === '' || !preg_match('/^[0-9]+$/', normalize_digits($id))) return null;
    static $tpl = null;
    if ($tpl === null) {
        $tpl = (string) (($s ?? (function_exists('db') ? abt_settings(db()) : []))['ticket_view_url'] ?? 'https://my.aradbranding.me/tickets/{id}');
    }
    return $tpl !== '' ? str_replace('{id}', rawurlencode(normalize_digits($id)), $tpl) : null;
}

/** نرمال‌سازیِ نامِ واحد برای مقایسه (ی/ک عربی، نیم‌فاصله، فاصله‌ها) */
function abt_norm_name(string $s): string
{
    $s = str_replace(['ي', 'ى', 'ك', "\u{200C}", "\u{200F}", "\u{200E}", 'ۀ', 'ة'], ['ی', 'ی', 'ک', '', '', '', 'ه', 'ه'], $s);
    return mb_strtolower((string) preg_replace('/\s+/u', '', $s));
}

/** فهرستِ ذخیره‌شده‌ی واحدها: [id => name] */
function abt_departments(array $s): array
{
    $j = json_decode((string) ($s['departments_json'] ?? ''), true);
    return is_array($j) ? $j : [];
}

/** شناسه‌ی عددیِ واحد از عدد یا نامِ واحد؛ null = پیدا نشد */
function abt_resolve_department(array $s, string $dept): ?int
{
    $dept = trim($dept);
    if ($dept === '') return null;
    $num = normalize_digits($dept);
    if (ctype_digit($num)) return (int) $num;
    $want = abt_norm_name($dept);
    foreach (abt_departments($s) as $id => $name) {
        if (abt_norm_name((string) $name) === $want) return (int) $id;
    }
    return null;
}

/**
 * فهرستِ واحدها (دپارتمان‌ها) از سامانه‌ی آراد برندینگ — GET با همان احرازِ هویت.
 * آدرس: اگر «آدرسِ API» به ‎/tickets ختم شود، همان مسیر با ‎/departments.
 * @return array{ok:bool, message:string, items:array<int,array{id:string,name:string}>, raw:string}
 */
function abt_fetch_departments(PDO $pdo, array $s, int $userId = 0): array
{
    $url = trim((string) $s['api_url']);
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'message' => 'اول «آدرسِ API» را وارد و ذخیره کنید.', 'items' => [], 'raw' => ''];
    $url = preg_replace('#/tickets/?(\?.*)?$#i', '/departments', $url, 1, $n);
    if (!$n) return ['ok' => false, 'message' => 'آدرسِ API به ‎/tickets ختم نمی‌شود؛ آدرسِ واحدها را نمی‌توان حدس زد.', 'items' => [], 'raw' => ''];
    if (!function_exists('curl_init')) return ['ok' => false, 'message' => 'افزونه‌ی cURL روی سرور فعال نیست.', 'items' => [], 'raw' => ''];
    $headers = ['Accept: application/json'];
    $key = (string) $s['auth_key'];
    $authName = trim((string) $s['auth_name']);
    if ($s['auth_style'] === 'bearer') $headers[] = 'Authorization: Bearer ' . $key;
    elseif ($s['auth_style'] === 'header') $headers[] = ($authName !== '' ? $authName : 'X-Api-Key') . ': ' . $key;
    elseif ($s['auth_style'] === 'query') $url .= '?' . rawurlencode($authName !== '' ? $authName : 'api_key') . '=' . rawurlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => $s['verify_ssl'] === '1', CURLOPT_SSL_VERIFYHOST => $s['verify_ssl'] === '1' ? 2 : 0]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $full = is_string($resp) ? $resp : '';
    $raw = mb_substr($full, 0, 4000);
    if ($resp === false || $err !== '') return ['ok' => false, 'message' => 'خطای اتصال: ' . $err, 'items' => [], 'raw' => $raw];
    if ($code < 200 || $code >= 300) return ['ok' => false, 'message' => 'پاسخِ سامانه با کد ' . $code . ' (توکن یا آدرس را چک کنید).', 'items' => [], 'raw' => $raw];
    $j = json_decode($full, true); // پاسخِ کامل (نه بریده‌شده)
    $list = is_array($j) ? ($j['departments'] ?? $j['data'] ?? $j['items'] ?? $j) : [];
    $items = [];
    if (is_array($list)) {
        foreach ($list as $k => $d) {
            if (is_array($d)) {
                $id = $d['id'] ?? $d['department_id'] ?? null;
                $name = $d['name'] ?? $d['title'] ?? $d['label'] ?? '';
                if ($id !== null) $items[] = ['id' => (string) $id, 'name' => (string) $name];
            } elseif (is_scalar($d) && !in_array($k, ['success', 'ok', 'status'], true)) {
                $items[] = ['id' => (string) $k, 'name' => (string) $d];
            }
        }
    }
    if ($items) {
        $save = [];
        foreach ($items as $it) $save[$it['id']] = $it['name'];
        abt_settings_save($pdo, ['departments_json' => json_encode($save, JSON_UNESCAPED_UNICODE)], $userId);
    }
    return ['ok' => true, 'message' => $items ? to_persian_digits((string) count($items)) . ' واحد دریافت و ذخیره شد؛ در «لیست خدمات» می‌توانید شناسه یا نامِ دقیقِ واحد را بنویسید.' : 'پاسخ دریافت شد ولی فهرستی تشخیص داده نشد (متنِ خام پایین آمده).', 'items' => $items, 'raw' => $raw];
}

/** ارسالِ واقعیِ تیکت. @return array{ok:bool, message:string} */
/**
 * ارسالِ یک تیکت — با قفلِ پایگاه‌داده برای همان تیکت: اگر دو درخواست هم‌زمان برسد (دوبار کلیک، دو تب، …)
 * دومی بعد از اولی وضعیتِ تازه را می‌خواند و چون «ارسال شده» است دوباره نمی‌فرستد.
 */
function abt_send(PDO $pdo, array $order, array $ticket, int $userId): array
{
    $tid = (int) ($ticket['id'] ?? 0);
    $lock = 'abt_send_' . $tid;
    $locked = false;
    try {
        $locked = $tid > 0 && (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 25)')->fetchColumn() === 1;
    } catch (Throwable $e) {}
    try {
        if ($tid > 0 && ($fresh = abt_get_ticket($pdo, $tid))) $ticket = $fresh;
        return abt_send_unlocked($pdo, $order, $ticket, $userId);
    } finally {
        if ($locked) { try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')'); } catch (Throwable $e) {} }
    }
}

function abt_send_unlocked(PDO $pdo, array $order, array $ticket, int $userId): array
{
    if (in_array($ticket['status'], ['sent', 'manual', 'bundled'], true)) {
        return ['ok' => true, 'message' => 'تیکتِ این سفارش قبلاً ارسال شده است.'];
    }
    $s = abt_settings($pdo);
    if (!abt_connection_ready($s)) {
        return ['ok' => false, 'message' => 'اتصال به آراد برندینگ هنوز تنظیم/فعال نشده؛ تیکت «آماده‌ی ارسال» ماند.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'افزونه‌ی cURL روی سرور فعال نیست.'];
    }
    // متنِ تیکت همیشه از «آخرین قالبِ» همان خدمت (لیست خدمات / تنظیمات تیکت) از نو ساخته می‌شود؛ متنِ قبلیِ ذخیره‌شده کنار می‌رود
    if ((int) ($ticket['item_id'] ?? 0) > 0) {
        try {
            abt_prepare_items($pdo, $order, $userId, true, (int) $ticket['id']);
            $ticket = abt_get_ticket($pdo, (int) $ticket['id']) ?: $ticket;
        } catch (Throwable $e) {
            error_log('abt_send rebuild: ' . $e->getMessage());
        }
    }
    // سامانه‌ی CRM: اول شرکت ساخته می‌شود، بعد اطلاعاتِ ورود در متنِ تیکت
    try {
        if (!function_exists('crm_prepare_ticket')) require_once __DIR__ . '/crm_provision.php';
        $__crm = crm_prepare_ticket($pdo, $order, $ticket, $userId);
        if (!$__crm['ok']) {
            $pdo->prepare("UPDATE aradbranding_tickets SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([mb_substr('سامانه‌ی CRM: ' . $__crm['message'], 0, 500), date('Y-m-d H:i:s'), (int) $ticket['id']]);
            return ['ok' => false, 'message' => 'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» ارسال نشد — ' . $__crm['message']];
        }
        $ticket = $__crm['ticket'];
    } catch (Throwable $e) {
        error_log('crm_prepare_ticket: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'خطا در اتصال به سامانه‌ی CRM؛ دوباره تلاش کنید.'];
    }
    // خدماتِ آموزشی: اول خرید در سامانه‌ی آموزش اعمال می‌شود، بعد نام کاربری/رمز در متنِ تیکت قرار می‌گیرد
    try {
        if (!function_exists('edu_prepare_ticket')) require_once __DIR__ . '/edu_provision.php';
        $__edu = edu_prepare_ticket($pdo, $order, $ticket, $userId);
        if (!$__edu['ok']) {
            $pdo->prepare("UPDATE aradbranding_tickets SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([mb_substr('سامانه‌ی آموزش: ' . $__edu['message'], 0, 500), date('Y-m-d H:i:s'), (int) $ticket['id']]);
            return ['ok' => false, 'message' => 'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» ارسال نشد — ' . $__edu['message']];
        }
        $ticket = $__edu['ticket'];
    } catch (Throwable $e) {
        error_log('edu_prepare_ticket: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'خطا در اتصال به سامانه‌ی آموزش؛ دوباره تلاش کنید.'];
    }
    // هرگز تیکتی با اطلاعاتِ ورودِ پرنشده («نام_کاربری» و …) ارسال نشود
    foreach (['نام_کاربری', 'رمز_عبور', 'آدرس_ورود', 'اشتراک', 'تاریخ_انقضا', 'نام_شرکت', 'خدمات_اعمال_شده'] as $__ph) {
        if (mb_strpos((string) $ticket['message'] . ' ' . (string) $ticket['subject'], '«' . $__ph . '»') !== false) {
            $why = 'متنِ تیکت متغیرِ «' . $__ph . '» دارد ولی این خدمت به سامانه‌ی آموزش/CRM وصل نیست یا اتصال خاموش است — در «تنظیمات تیکت» اتصال و جدولِ خدمات را بررسی کنید.';
            $pdo->prepare("UPDATE aradbranding_tickets SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([mb_substr($why, 0, 500), date('Y-m-d H:i:s'), (int) $ticket['id']]);
            return ['ok' => false, 'message' => 'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» ارسال نشد — ' . $why];
        }
    }
    $payload = abt_payload($pdo, $s, $order, $ticket);
    $deptField = trim((string) ($s['field_department'] ?? ''));
    if ($deptField !== '' && !is_int($payload[$deptField] ?? null)) {
        $d = abt_ticket_department($pdo, $s, $ticket);
        $why = $d === ''
            ? 'واحدِ تیکت تعیین نشده — برای این خدمت در «لیست خدمات» واحد بگذارید، یا «واحدِ پیش‌فرض» را در تنظیماتِ تیکت پر کنید.'
            : 'واحدِ «' . $d . '» در فهرستِ واحدهای آراد برندینگ پیدا نشد — شناسه‌ی عددی (مثلاً ۴۸) بنویسید یا «دریافتِ فهرستِ واحدها» را بزنید.';
        $pdo->prepare("UPDATE aradbranding_tickets SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?")
            ->execute([mb_substr($why, 0, 500), date('Y-m-d H:i:s'), (int) $ticket['id']]);
        return ['ok' => false, 'message' => 'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» ارسال نشد — ' . $why];
    }
    $url = trim($s['api_url']);
    $attField = trim((string) ($s['field_attachments'] ?? ''));
    $phoneField = trim((string) ($s['field_phone'] ?? ''));
    // ارسال؛ اگر آراد برندینگ پیوست‌ها را نپذیرفت (۴۲۲)، یک بار دیگر بدونِ پیوست (لینکِ اسناد در متن هست)
    $post = static function (array $payload) use ($s, $url, $attField): array {
        [$resp, $err, $code] = abt_http_post($s, $url, $payload);
        $attNote = '';
        if ($code === 422 && $attField !== '' && !empty($payload[$attField])) {
            $attErr = is_string($resp) ? (abt_parse_response(mb_substr($resp, 0, 4000))['error'] ?? '') : '';
            unset($payload[$attField]);
            [$resp, $err, $code] = abt_http_post($s, $url, $payload);
            $attNote = ' — پیوست‌ها پذیرفته نشد' . ($attErr ? ' («' . $attErr . '»)' : '') . '؛ تیکت بدونِ پیوست و با لینکِ اسناد در متن ارسال شد';
        }
        $respText = is_string($resp) ? mb_substr($resp, 0, 4000) : '';
        $parsed = $respText !== '' ? abt_parse_response($respText) : ['ok' => null, 'id' => null, 'url' => null, 'error' => null];
        $ok = $resp !== false && $err === '' && $code >= 200 && $code < 300 && $parsed['ok'] !== false;
        return ['ok' => $ok, 'err' => $err, 'code' => $code, 'text' => $respText, 'parsed' => $parsed, 'att_note' => $attNote];
    };

    // «تاجری با این شماره موبایل پیدا نشد» ← همه‌ی موبایل‌های این شخص در پروفایل ۳۶۰ به‌ترتیب امتحان می‌شوند
    $mobiles = $phoneField !== '' ? abt_person_mobiles($pdo, $order) : [];
    if (!$mobiles) $mobiles = [$phoneField !== '' ? (string) ($payload[$phoneField] ?? '') : ''];
    $tried = [];
    $usedMobile = null;
    $account = null;
    $r = null;
    foreach ($mobiles as $m) {
        if ($phoneField !== '') $payload[$phoneField] = $m;
        $r = $post($payload);
        if (!abt_is_unknown_merchant($r)) { $usedMobile = $m; break; }
        $tried[] = $m;
    }
    // هیچ شماره‌ای حساب نداشت ← ساختِ حسابِ جدید + تیکتِ «اطلاعاتِ حساب» + ارسالِ همین تیکت به حسابِ جدید
    if ($usedMobile === null && $phoneField !== '') {
        $acc = abt_create_account($pdo, $s, $order, $userId);
        if ($acc['ok']) {
            $account = $acc['account'];
            $payload[$phoneField] = (string) $account['mobile'];
            $r = $post($payload);
            $usedMobile = (string) $account['mobile'];
        } else {
            $now = date('Y-m-d H:i:s');
            $why = 'هیچ‌کدام از شماره‌های این مشتری در پروفایل ۳۶۰ (' . to_persian_digits(implode('، ', $tried)) . ') در آراد برندینگ حساب ندارد — ' . $acc['message'];
            $pdo->prepare("UPDATE aradbranding_tickets SET status = 'failed', attempts = attempts + 1, http_code = ?, response_body = ?, last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([$r['code'] ?: null, $r['text'], mb_substr($why, 0, 500), $now, (int) $ticket['id']]);
            return ['ok' => false, 'message' => 'ارسالِ تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» به آراد برندینگ ناموفق بود — ' . $why];
        }
    }

    $now = date('Y-m-d H:i:s');
    $parsed = $r['parsed'];
    $respText = $r['text'];
    $code = $r['code'];
    if ($r['ok']) {
        $altNote = '';
        if (!$account && $usedMobile !== null && $usedMobile !== '' && $usedMobile !== abt_mobile((string) ($order['customer_mobile'] ?? ''))) {
            // شماره‌ی دیگری از پروفایل ۳۶۰ حساب داشت ← برای تیکت‌های بعدی هم همین شماره
            abt_account_remember($pdo, $order, $usedMobile, $userId);
            $altNote = ' — با شماره‌ی ' . to_persian_digits($usedMobile) . ' از پروفایلِ ۳۶۰ (شماره‌ی سفارش در آراد برندینگ حساب نداشت)';
        }
        $pdo->prepare("UPDATE aradbranding_tickets SET status = 'sent', attempts = attempts + 1, external_id = ?, external_url = ?, http_code = ?,
                response_body = ?, last_error = NULL, sent_by = ?, sent_at = ?, updated_at = ?, phone_used = ? WHERE id = ?")
            ->execute([$parsed['id'], $parsed['url'], $code, $respText, $userId, $now, $now, ($usedMobile ?? '') !== '' ? $usedMobile : null, (int) $ticket['id']]);
        if (function_exists('orders_add_history')) {
            orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null,
                'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» در آراد برندینگ ثبت شد' . ($parsed['id'] ? ' (شماره‌ی تیکت: ' . $parsed['id'] . ')' : '') . $altNote . '.');
        }
        $accNote = $account ? ' — ' . abt_account_notice($account) : '';
        return ['ok' => true, 'message' => 'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» در آراد برندینگ ثبت شد' . ($parsed['id'] ? ' (شماره‌ی تیکت: ' . $parsed['id'] . ')' : '') . $altNote . $r['att_note'] . '.' . $accNote,
            'new_account' => $account];
    }
    $why = $r['err'] !== '' ? 'خطای اتصال: ' . $r['err'] : ('پاسخِ سامانه (کد ' . $code . ')' . ($parsed['error'] ? ': ' . $parsed['error'] : ''));
    if ($account) $why .= ' — حسابِ جدید ساخته شد ولی ارسالِ تیکت به آن ناموفق بود؛ دوباره «ارسال» بزنید. ' . abt_account_notice($account);
    $pdo->prepare("UPDATE aradbranding_tickets SET status = 'failed', attempts = attempts + 1, http_code = ?, response_body = ?, last_error = ?, updated_at = ? WHERE id = ?")
        ->execute([$code ?: null, $respText, mb_substr($why, 0, 500), $now, (int) $ticket['id']]);
    return ['ok' => false, 'message' => 'ارسالِ تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» به آراد برندینگ ناموفق بود — ' . $why];
}

/** POST به API آراد برندینگ با همان احرازِ هویتِ تنظیمات. @return array{0:string|false,1:string,2:int} */
function abt_http_post(array $s, string $url, array $payload, string $method = 'POST'): array
{
    $headers = ['Accept: application/json'];
    $key = (string) $s['auth_key'];
    $authName = trim((string) $s['auth_name']);
    switch ($s['auth_style']) {
        case 'bearer':
            $headers[] = 'Authorization: Bearer ' . $key;
            break;
        case 'header':
            $headers[] = ($authName !== '' ? $authName : 'X-Api-Key') . ': ' . $key;
            break;
        case 'query':
            $url .= (strpos($url, '?') !== false ? '&' : '?') . rawurlencode($authName !== '' ? $authName : 'api_key') . '=' . rawurlencode($key);
            break;
        case 'body':
            $payload[$authName !== '' ? $authName : 'api_key'] = $key;
            break;
    }
    if (isset($GLOBALS['ABT_HTTP_MOCK']) && is_callable($GLOBALS['ABT_HTTP_MOCK'])) {
        return ($GLOBALS['ABT_HTTP_MOCK'])($url, $payload); // فقط برای تستِ خودکار
    }
    if ($s['body_format'] === 'form') {
        $body = http_build_query($payload);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
    } else {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }
    $ch = curl_init($url);
    if ($method !== 'POST') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); // مثلاً DELETE با بدنه‌ی JSON
    curl_setopt_array($ch, [
        CURLOPT_POST           => $method === 'POST',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => $s['verify_ssl'] === '1',
        CURLOPT_SSL_VERIFYHOST => $s['verify_ssl'] === '1' ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$resp, $err, $code];
}

/* =========================================================================
   حسابِ مشتری در آراد برندینگ (موبایل‌های پروفایل ۳۶۰ / ساختِ حسابِ جدید)
   ========================================================================= */

/** پاسخِ «تاجری با این شماره موبایل پیدا نشد» (۴۲۲/۴۰۴) */
function abt_is_unknown_merchant(?array $r): bool
{
    if (!$r || $r['ok'] || !in_array((int) $r['code'], [404, 422], true)) return false;
    $txt = (string) ($r['parsed']['error'] ?? '') . ' ' . (string) $r['text'];
    return (bool) preg_match('/تاجر|merchant|(کاربر|user|حساب|account|موبایل|mobile).{0,40}(پیدا|یافت|not\s*found|exist)/iu', $txt);
}

/** کلیدِ شخص در پروفایلِ ۳۶۰ (کوچک‌ترین شناسه‌ی پرونده) */
function abt_person_key(PDO $pdo, int $customerId): int
{
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = cc_person_ids($pdo, $customerId);
    return $ids ? min($ids) : $customerId;
}

function abt_account_get(PDO $pdo, int $customerId): ?array
{
    if (!abt_ready($pdo)) return null;
    try {
        $st = $pdo->prepare('SELECT * FROM aradbranding_accounts WHERE person_key = ? LIMIT 1');
        $st->execute([abt_person_key($pdo, $customerId)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * همه‌ی موبایل‌های این شخص (پروفایلِ ۳۶۰: همه‌ی پرونده‌ها با همان شماره/کدِ ملی، موبایلِ دوم و شماره‌های اضافه)
 * به قالبِ ۰۹xxxxxxxxx — اول شماره‌ای که قبلاً در آراد برندینگ حساب داشته/ساخته شده، بعد موبایلِ سفارش، بعد بقیه.
 */
function abt_person_mobiles(PDO $pdo, array $order): array
{
    $cid = (int) $order['customer_id'];
    $out = [];
    $acc = abt_account_get($pdo, $cid);
    if ($acc) $out[] = (string) $acc['mobile'];
    $out[] = abt_mobile((string) ($order['customer_mobile'] ?? ''));
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = array_map('intval', cc_person_ids($pdo, $cid) ?: [$cid]);
    usort($ids, static fn($a, $b) => ($a === $cid ? -1 : ($b === $cid ? 1 : $a <=> $b))); // پرونده‌ی خودِ سفارش اول
    $in = implode(',', $ids);
    try {
        $rows = [];
        foreach ($pdo->query("SELECT id, mobile, mobile_2 FROM customers WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) $rows[(int) $c['id']] = $c;
        foreach ($ids as $i) {
            if (!isset($rows[$i])) continue;
            $out[] = abt_mobile((string) $rows[$i]['mobile']);
            $out[] = abt_mobile((string) ($rows[$i]['mobile_2'] ?? ''));
        }
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT phone FROM customer_phones WHERE customer_id IN ($in) ORDER BY is_primary DESC, id")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ph) {
            $out[] = abt_mobile((string) $ph);
        }
    } catch (Throwable $e) {}
    return array_values(array_unique(array_filter($out, static fn($m) => (bool) preg_match('/^09\d{9}$/', $m))));
}

/** شماره‌ای از پروفایلِ ۳۶۰ که در آراد برندینگ حساب داشت ← ذخیره برای تیکت‌های بعدی */
function abt_account_remember(PDO $pdo, array $order, string $mobile, int $userId): void
{
    try {
        $pdo->prepare("INSERT INTO aradbranding_accounts (person_key, customer_id, mobile, status, order_id, created_by, created_at) VALUES (?,?,?,'found',?,?,?)
            ON DUPLICATE KEY UPDATE mobile = IF(status = 'created', mobile, VALUES(mobile))")
            ->execute([abt_person_key($pdo, (int) $order['customer_id']), (int) $order['customer_id'], $mobile, (int) $order['id'], $userId ?: null, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('abt_account_remember: ' . $e->getMessage());
    }
}

/** رمزِ عبورِ اولیه: ۸ نویسه، بدونِ حروفِ گیج‌کننده (0/O، 1/l/I) — مناسبِ پیامک */
function abt_gen_password(): string
{
    $l = 'abcdefghjkmnpqrstuvwxyz';
    $d = '23456789';
    $p = strtoupper($l[random_int(0, strlen($l) - 1)]);
    for ($i = 0; $i < 3; $i++) $p .= $l[random_int(0, strlen($l) - 1)];
    for ($i = 0; $i < 4; $i++) $p .= $d[random_int(0, strlen($d) - 1)];
    return $p;
}

/** «فهرست_همه_خدمات»: همه‌ی خدماتِ سفارش‌های تأییدشده‌ی این شخص (همه‌ی پرونده‌های ۳۶۰) + سفارشِ فعلی */
function abt_all_services_text(PDO $pdo, array $order): string
{
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = array_map('intval', cc_person_ids($pdo, (int) $order['customer_id']) ?: [(int) $order['customer_id']]);
    $in = implode(',', $ids);
    $lines = [];
    try {
        $st = $pdo->prepare("SELECT i.title, i.quantity, i.unit, o.order_number FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id
            WHERE (o.customer_id IN ($in) AND o.status = 'approved') OR o.id = ? ORDER BY o.id, i.id");
        $st->execute([(int) $order['id']]);
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
            $qty = (float) ($it['quantity'] ?? 1);
            $unit = trim((string) ($it['unit'] ?? ''));
            $q = ($qty != 1.0 || $unit !== '') ? ' — ' . to_persian_digits(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')) . ($unit !== '' ? ' ' . $unit : '') : '';
            $line = trim((string) $it['title']) . $q . ' (فاکتور ' . "\u{2066}" . to_persian_digits((string) $it['order_number']) . "\u{2069}" . ')';
            if (isset($seen[$line])) continue;
            $seen[$line] = true;
            $lines[] = to_persian_digits((string) (count($lines) + 1)) . '. ' . $line;
        }
    } catch (Throwable $e) {
        error_log('abt_all_services_text: ' . $e->getMessage());
    }
    return $lines ? implode("\n", $lines) : '—';
}

/** پیغامِ یادآوری برای مسئولِ ارسال */
function abt_account_notice(array $account): string
{
    return 'این مشتری قبلاً در آراد برندینگ حساب نداشت و برایش حسابِ جدید ساخته شد (نام کاربری: ' . $account['username']
        . ' — رمز عبور: ' . ($account['password'] ?: '—') . '). لطفاً نام کاربری و رمز عبور را برای مشتری پیامک کنید.';
}

/**
 * ساختِ حسابِ تاجر در آراد برندینگ با موبایلِ سفارش (یا اولین موبایلِ معتبرِ پروفایل ۳۶۰)، سپس تیکتِ «اطلاعاتِ حساب»
 * (نام کاربری، رمز، آدرسِ ورود و نامِ همه‌ی خدماتِ خریداری‌شده) و یادآوری به مسئولی که «ارسال» را زده.
 * @return array{ok:bool, message:string, account?:array}
 */
/** آدرسِ API ساختِ حسابِ تاجر: تنظیمات، وگرنه از روی آدرسِ API تیکت (…/tickets ← …/merchants) */
function abt_account_api_url(array $s): string
{
    $url = trim((string) ($s['acc_api_url'] ?? ''));
    if ($url !== '') return $url;
    $t = rtrim(trim((string) ($s['api_url'] ?? '')), '/');
    return preg_match('#/tickets$#', $t) ? preg_replace('#/tickets$#', '/merchants', $t) : '';
}

/** نام و نام خانوادگی از نامِ کامل (عنوان‌هایی مثلِ «آقای/خانم/دکتر/مهندس» حذف می‌شوند؛ تک‌کلمه‌ای ← نام خانوادگی = همان) */
function abt_split_name(string $full): array
{
    $full = trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $full)));
    $full = trim(preg_replace('/^(?:جناب\s+)?(?:آقای|اقای|آقا|خانم|سرکار\s+خانم|دکتر|مهندس|حاج|حاجی)\s+/u', '', $full));
    if ($full === '') return ['', ''];
    $parts = explode(' ', $full, 2);
    return count($parts) === 2 ? [$parts[0], $parts[1]] : [$parts[0], $parts[0]];
}

/**
 * حذفِ تیکتِ ارسال‌شده از آراد برندینگ (DELETE …/tickets با ticket_id، وگرنه external_id).
 * پس از حذف، شناسه‌ی مرجعِ بعدی عوض می‌شود تا «ارسالِ دوباره» با تیکتِ حذف‌شده یکی نشود.
 */
function abt_delete_remote(PDO $pdo, array $order, array $ticket, int $userId, string $reason = ''): array
{
    if (!in_array($ticket['status'], ['sent', 'manual'], true)) return ['ok' => false, 'message' => 'فقط تیکتِ ارسال‌شده حذف می‌شود.'];
    $s = abt_settings($pdo);
    if (!abt_connection_ready($s)) return ['ok' => false, 'message' => 'اتصال به آراد برندینگ تنظیم/فعال نیست.'];
    $ext = trim((string) ($ticket['external_id'] ?? ''));
    $body = ctype_digit($ext) ? ['ticket_id' => (int) $ext]
        : ['external_id' => 'arad-contact-' . (int) $ticket['id'] . ((int) ($ticket['resend_count'] ?? 0) > 0 ? '-r' . (int) $ticket['resend_count'] : '')];
    [$resp, $err, $code] = abt_http_post($s, trim((string) $s['api_url']), $body, 'DELETE');
    $text = is_string($resp) ? mb_substr($resp, 0, 4000) : '';
    $j = json_decode($text, true);
    $ok = $resp !== false && $err === '' && $code >= 200 && $code < 300 && is_array($j) && ($j['success'] ?? true) !== false
        && (!empty($j['deleted']) || !empty($j['already_deleted']));
    if (!$ok) {
        $why = $err !== '' ? 'خطای اتصال: ' . $err : 'کد ' . $code . (is_array($j) && !empty($j['message']) ? ': ' . $j['message'] : '');
        return ['ok' => false, 'message' => 'حذف از آراد برندینگ ناموفق — ' . $why];
    }
    $pdo->prepare("UPDATE aradbranding_tickets SET status = 'deleted', resend_count = resend_count + 1, external_url = NULL, last_error = ?, updated_at = ? WHERE id = ?")
        ->execute([mb_substr('حذف از آراد برندینگ' . ($reason !== '' ? ': ' . $reason : ''), 0, 500), date('Y-m-d H:i:s'), (int) $ticket['id']]);
    if (function_exists('orders_add_history')) {
        orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null,
            'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» (' . ($ext !== '' ? $ext : '#' . $ticket['id']) . ') از آراد برندینگ حذف شد' . (!empty($j['already_deleted']) ? ' (قبلاً حذف شده بود)' : '') . ($reason !== '' ? ' — ' . $reason : '') . '.');
    }
    return ['ok' => true, 'message' => 'تیکت از آراد برندینگ حذف شد' . (!empty($j['already_deleted']) ? ' (قبلاً حذف شده بود)' : '') . '. در صورتِ نیاز متن را اصلاح و دوباره «ارسال» کنید.'];
}

function abt_create_account(PDO $pdo, array $s, array $order, int $userId): array
{
    $existing = abt_account_get($pdo, (int) $order['customer_id']);
    if ($existing && $existing['status'] === 'created') return ['ok' => true, 'message' => 'حساب قبلاً ساخته شده.', 'account' => $existing];
    $url = abt_account_api_url($s);
    if (($s['acc_enabled'] ?? '0') !== '1' || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'message' => 'ساختِ خودکارِ حساب در «تنظیمات تیکت ← مشتری‌ای که در آراد برندینگ حساب ندارد» فعال/تنظیم نشده؛ یا شماره‌ی درستِ مشتری را در پرونده‌اش اضافه کنید، یا حساب را دستی بسازید و دوباره «ارسال» بزنید.'];
    }
    $mobiles = abt_person_mobiles($pdo, $order);
    $orderMobile = abt_mobile((string) ($order['customer_mobile'] ?? ''));
    $mobile = preg_match('/^09\d{9}$/', $orderMobile) ? $orderMobile : ($mobiles[0] ?? '');
    if ($mobile === '') return ['ok' => false, 'message' => 'هیچ شماره موبایلِ معتبری (۰۹…) برای ساختِ حساب در پرونده‌ی مشتری نیست.'];
    $kyc = function_exists('kyc_get') ? kyc_get($pdo, (int) $order['customer_id']) : [];
    $password = abt_gen_password();
    $payload = json_decode((string) ($s['acc_extra_json'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    [$first, $last] = abt_split_name((string) ($order['customer_name'] ?? ''));
    foreach ([
        'acc_field_mobile'     => $mobile,
        'acc_field_name'       => (string) ($order['customer_name'] ?? ''),
        'acc_field_first_name' => $first,
        'acc_field_last_name'  => $last,
        'acc_field_father'     => trim((string) ($kyc['father_name'] ?? '')),
        'acc_field_password'   => $password,
        // کدِ ملی فقط برای ایرانی‌ها؛ اتباع (بدونِ کدِ ملی) بدونِ این فیلد ساخته می‌شوند
        'acc_field_national'   => ($kyc['id_type'] ?? 'national') === 'national' ? (string) ($kyc['national_id'] ?? '') : '',
    ] as $cfg => $val) {
        $name = trim((string) ($s[$cfg] ?? ''));
        if ($name !== '' && $val !== '') $payload[$name] = $val;
    }
    [$resp, $err, $code] = abt_http_post($s, $url, $payload);
    $text = is_string($resp) ? mb_substr($resp, 0, 4000) : '';
    $parsed = $text !== '' ? abt_parse_response($text) : ['ok' => null, 'id' => null, 'url' => null, 'error' => null];
    if ($resp === false || $err !== '' || $code < 200 || $code >= 300 || $parsed['ok'] === false) {
        $why = $err !== '' ? 'خطای اتصال: ' . $err : 'ساختِ حساب ناموفق بود (کد ' . $code . ')' . ($parsed['error'] ? ': ' . $parsed['error'] : '');
        // برای عیب‌یابی: آنچه فرستاده شد (بدونِ رمز) + پاسخِ کاملِ آراد برندینگ در تاریخچه‌ی سفارش
        $sent = $payload;
        foreach ($sent as $k => $v) if (stripos((string) $k, 'pass') !== false) $sent[$k] = '***';
        if (function_exists('orders_add_history')) orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null, 'آراد برندینگ: ' . $why
            . "\nارسال‌شده به " . $url . ': ' . json_encode($sent, JSON_UNESCAPED_UNICODE) . ($text !== '' ? "\nپاسخ: " . mb_substr($text, 0, 1500) : ''));
        return ['ok' => false, 'message' => $why];
    }
    // نام کاربری/رمز/شناسه اگر API برگرداند (وگرنه: موبایل و همان رمزی که فرستادیم)
    $j = json_decode($text, true);
    $pools = is_array($j) ? [$j] : [];
    foreach (['data', 'user', 'merchant', 'result', 'account'] as $k) if (is_array($j) && isset($j[$k]) && is_array($j[$k])) $pools[] = $j[$k];
    $pick = static function (array $keys) use ($pools): ?string {
        foreach ($pools as $p) foreach ($keys as $k) if (isset($p[$k]) && is_scalar($p[$k]) && (string) $p[$k] !== '') return (string) $p[$k];
        return null;
    };
    $row = [
        abt_person_key($pdo, (int) $order['customer_id']), (int) $order['customer_id'], $mobile, 'created',
        mb_substr($pick(['username', 'user_name', 'login']) ?? $mobile, 0, 120),
        mb_substr($pick(['password', 'pass']) ?? $password, 0, 120),
        $pick(['user_id', 'merchant_id', 'id']),
        (int) $order['id'], $userId ?: null, date('Y-m-d H:i:s'), $text,
    ];
    $pdo->prepare('INSERT INTO aradbranding_accounts (person_key, customer_id, mobile, status, username, password, external_user_id, order_id, created_by, created_at, response_body)
            VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), mobile = VALUES(mobile), status = VALUES(status), username = VALUES(username),
            password = VALUES(password), external_user_id = VALUES(external_user_id), order_id = VALUES(order_id), created_by = VALUES(created_by), created_at = VALUES(created_at),
            response_body = VALUES(response_body), sms_done_by = NULL, sms_done_at = NULL, welcome_ticket_id = NULL, welcome_error = NULL')
        ->execute($row);
    $account = abt_account_get($pdo, (int) $order['customer_id']);
    if (!$account) return ['ok' => false, 'message' => 'حساب در آراد برندینگ ساخته شد ولی ذخیره‌ی آن در آراد کانتکت ناموفق بود.'];

    // تیکتِ «اطلاعاتِ حساب» برای خودِ مشتری: نام کاربری، رمز، آدرسِ ورود و نامِ همه‌ی خدماتِ خریداری‌شده
    $vars = [
        'نام_کاربری' => (string) $account['username'],
        'رمز_عبور' => (string) $account['password'],
        'آدرس_ورود' => trim((string) ($s['acc_login_url'] ?? '')) ?: 'https://my.aradbranding.me',
        'فهرست_همه_خدمات' => abt_all_services_text($pdo, $order),
    ] + abt_vars($pdo, $order + ['total_amount' => 0, 'quote_id' => 0, 'order_number' => '']); // تیکتِ قرارداد ممکن است سفارش نداشته باشد
    $vars['موبایل'] = to_persian_digits($mobile);
    $wTicket = [
        'id' => 0, 'item_id' => null, 'department' => trim((string) ($s['acc_department'] ?? '')),
        'subject' => mb_substr(trim(preg_replace('/\s+/u', ' ', abt_render((string) $s['acc_subject_tpl'], $vars))), 0, 250) ?: 'اطلاعات حساب کاربری شما در آراد برندینگ',
        'message' => trim(preg_replace('/^[ \t]+/mu', '', abt_render(trim((string) $s['acc_body_tpl']) !== '' ? (string) $s['acc_body_tpl'] : abt_default_account_body(), $vars))),
    ];
    $wPayload = abt_payload($pdo, $s, $order, $wTicket);
    if (trim((string) $s['field_phone']) !== '') $wPayload[trim((string) $s['field_phone'])] = $mobile;
    if (trim((string) $s['field_external']) !== '') $wPayload[trim((string) $s['field_external'])] = 'arad-contact-account-' . (int) $account['id'];
    $deptField = trim((string) ($s['field_department'] ?? ''));
    $welcomeErr = null;
    $welcomeId = null;
    if ($deptField !== '' && !is_int($wPayload[$deptField] ?? null)) {
        $welcomeErr = 'واحدِ تیکتِ «اطلاعاتِ حساب» تعیین/پیدا نشد (تنظیمات تیکت ← مشتری‌ای که در آراد برندینگ حساب ندارد).';
    } else {
        [$wr, $we, $wc] = abt_http_post($s, trim($s['api_url']), $wPayload);
        $wt = is_string($wr) ? mb_substr($wr, 0, 4000) : '';
        $wp = $wt !== '' ? abt_parse_response($wt) : ['ok' => null, 'id' => null, 'url' => null, 'error' => null];
        if ($wr !== false && $we === '' && $wc >= 200 && $wc < 300 && $wp['ok'] !== false) {
            $welcomeId = $wp['id'] ?? 'sent';
        } else {
            $welcomeErr = $we !== '' ? 'خطای اتصال: ' . $we : 'کد ' . $wc . ($wp['error'] ? ': ' . $wp['error'] : '');
        }
    }
    $pdo->prepare('UPDATE aradbranding_accounts SET welcome_ticket_id = ?, welcome_error = ? WHERE id = ?')
        ->execute([$welcomeId, $welcomeErr !== null ? mb_substr($welcomeErr, 0, 500) : null, (int) $account['id']]);
    $account['welcome_ticket_id'] = $welcomeId;
    $account['welcome_error'] = $welcomeErr;

    // یادآوری به مسئولی که «ارسال» را زده: تاریخچه‌ی سفارش + اعلانِ پوش + کادرِ ثابت در صفحه‌ی سفارش تا «پیامک شد» بزند
    $msg = abt_account_notice($account) . ($welcomeErr === null ? ' تیکتِ «اطلاعاتِ حساب» (با فهرستِ همه‌ی خدمات) هم برای مشتری ثبت شد.' : ' (تیکتِ «اطلاعاتِ حساب» ارسال نشد: ' . $welcomeErr . ')');
    if (function_exists('orders_add_history')) orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null, 'آراد برندینگ: ' . $msg);
    if ($userId && function_exists('send_push_to_user')) {
        try {
            send_push_to_user($pdo, $userId, 'حسابِ جدید در آراد برندینگ — ' . ($order['customer_name'] ?? ''),
                'مشتری قبلاً حساب نداشت. نام کاربری و رمز را برایش پیامک کنید.', 'order_view.php?id=' . (int) $order['id'] . '#abt-account');
        } catch (Throwable $e) {
            error_log('abt push: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'message' => $msg, 'account' => $account];
}

/** «پیامک شد»: مسئول اعلام می‌کند اطلاعاتِ ورود برای مشتری فرستاده شد */
function abt_account_sms_done(PDO $pdo, int $customerId, int $userId): bool
{
    $acc = abt_account_get($pdo, $customerId);
    if (!$acc || $acc['status'] !== 'created') return false;
    $pdo->prepare('UPDATE aradbranding_accounts SET sms_done_by = ?, sms_done_at = ? WHERE id = ?')->execute([$userId, date('Y-m-d H:i:s'), (int) $acc['id']]);
    if (!empty($acc['order_id']) && function_exists('orders_add_history')) {
        orders_add_history($pdo, (int) $acc['order_id'], $userId, 'note', null, null, 'اطلاعاتِ ورودِ حسابِ آراد برندینگ برای مشتری پیامک شد.');
    }
    return true;
}

/**
 * ارسالِ مجدد / بازگشاییِ تیکتی که قبلاً ارسال (یا دستی ثبت) شده — مثلاً وقتی تیکت در سایتِ اصلی حذف شده.
 * شناسه‌ی یکتای جدید (arad-contact-<id>-r<n>) ساخته می‌شود تا سایتِ مقصد تیکتِ تازه بسازد؛ سابقه‌ی قبلی در تاریخچه‌ی سفارش می‌ماند.
 * $sendNow = false ← فقط «آماده‌ی ارسال» می‌شود تا متن/واحد ویرایش و بعد ارسال شود.
 */
function abt_resend(PDO $pdo, array $order, array $ticket, int $userId, bool $sendNow = true): array
{
    if (!in_array($ticket['status'], ['sent', 'manual', 'bundled'], true)) {
        return $sendNow ? abt_send($pdo, $order, $ticket, $userId) : ['ok' => true, 'message' => 'تیکت از قبل قابلِ ویرایش و ارسال است.'];
    }
    $prev = trim((string) ($ticket['external_id'] ?? ''));
    $pdo->prepare("UPDATE aradbranding_tickets SET status = 'queued', resend_count = resend_count + 1, external_id = NULL, external_url = NULL,
            last_error = NULL, updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int) $ticket['id']]);
    if (function_exists('orders_add_history')) {
        orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null,
            'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» برای ارسالِ مجدد بازگشایی شد' . ($prev !== '' ? ' (تیکتِ قبلی: ' . $prev . ')' : '') . '.');
    }
    $fresh = abt_get_ticket($pdo, (int) $ticket['id']) ?: $ticket;
    if (!$sendNow) return ['ok' => true, 'message' => 'تیکت بازگشایی شد؛ متن/واحد را در صورتِ نیاز اصلاح و «ارسال» را بزنید.'];
    return abt_send($pdo, $order, $fresh, $userId);
}

/** ثبتِ دستی: تیکت بیرون از سیستم (مستقیم در aradbranding.me) ساخته شده */
function abt_mark_manual(PDO $pdo, array $order, array $ticket, int $userId, string $externalId, string $externalUrl): void
{
    $externalUrl = preg_match('#^https?://#i', trim($externalUrl)) ? mb_substr(trim($externalUrl), 0, 500) : null;
    $externalId = trim($externalId) !== '' ? mb_substr(trim($externalId), 0, 100) : null;
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE aradbranding_tickets SET status = 'manual', external_id = COALESCE(?, external_id), external_url = COALESCE(?, external_url),
            last_error = NULL, sent_by = ?, sent_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$externalId, $externalUrl, $userId, $now, $now, (int) $ticket['id']]);
    if (function_exists('orders_add_history')) {
        orders_add_history($pdo, (int) $order['id'], $userId, 'note', null, null, 'تیکتِ «' . ($ticket['service_title'] ?? 'سفارش') . '» به‌صورتِ دستی در آراد برندینگ ثبت شد' . ($externalId ? ' (شماره: ' . $externalId . ')' : '') . '.');
    }
}

/**
 * بعد از «تأیید و ثبتِ سفارش» توسطِ مالی صدا زده می‌شود.
 * @return array{ok:?bool, message:string}  ok=null یعنی ارسال انجام نشد (تنظیم نشده/خاموش)
 */
function abt_on_order_approved(PDO $pdo, int $orderId, int $userId): array
{
    if (!abt_ready($pdo) || !function_exists('orders_get')) {
        return ['ok' => null, 'message' => ''];
    }
    $order = orders_get($pdo, $orderId);
    if (!$order || $order['status'] !== 'approved') {
        return ['ok' => null, 'message' => ''];
    }
    $tickets = abt_prepare_items($pdo, $order, $userId, false);
    $pending = array_values(array_filter($tickets, static fn($t) => !in_array($t['status'], ['sent', 'manual', 'bundled', 'deleted'], true)));
    if (!$pending) {
        return ['ok' => null, 'message' => ''];
    }
    $s = abt_settings($pdo);
    if (!abt_connection_ready($s) || $s['auto_send'] !== '1') {
        return ['ok' => null, 'message' => to_persian_digits((string) count($pending)) . ' تیکتِ آراد برندینگ (یکی برای هر خدمت) آماده شد؛ از بخشِ «تیکت‌های آراد برندینگ» همین صفحه ارسالشان کنید.'];
    }
    return abt_send_all($pdo, $order, $pending, $userId);
}

/** ارسالِ همه‌ی تیکت‌های ارسال‌نشده‌ی یک سفارش */
function abt_send_all(PDO $pdo, array $order, array $tickets, int $userId): array
{
    $ok = 0;
    $fail = [];
    foreach ($tickets as $t) {
        if (in_array($t['status'], ['sent', 'manual', 'bundled', 'skipped', 'deleted'], true)) continue; // «ارسال نشود» فقط تکی از صفحه‌ی سفارش
        $r = abt_send($pdo, $order, $t, $userId);
        if ($r['ok']) $ok++; else $fail[] = $r['message'];
    }
    if (!$fail) {
        return ['ok' => true, 'message' => to_persian_digits((string) $ok) . ' تیکت (یکی برای هر خدمت) در آراد برندینگ ثبت شد.'];
    }
    return ['ok' => false, 'message' => ($ok ? to_persian_digits((string) $ok) . ' تیکت ارسال شد؛ ' : '') . implode(' | ', $fail)];
}
