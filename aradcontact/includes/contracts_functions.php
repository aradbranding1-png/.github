<?php
/**
 * ماژولِ «قرارداد»
 * ---------------------------------------------------------------------------
 * مشتری → پیش‌فاکتورِ قفل‌شده → (شرحِ خدمات) → قرارداد → پیوست‌ها → ارسال برای مشتری
 *
 * - متنِ قرارداد از «قالب قرارداد» (پنل مدیریت) می‌آید؛ قالب نسخه‌دار است و هر قرارداد
 *   نسخه‌ی قالبِ خودش را نگه می‌دارد، پس تغییرِ قالب روی قراردادهای قبلی اثر ندارد.
 * - اطلاعاتِ مشتری (نام، نام پدر، کد ملی، موبایل، آدرس، کد پستی) و اطلاعاتِ مالی
 *   (مبلغ، واریزی، بدهی، اقساط) خودکار از پرونده و سفارش خوانده می‌شوند.
 * - هنگامِ «صدور»، متنِ نهاییِ قرارداد و نسخه‌ی پیش‌فاکتور/شرحِ خدمات منجمد می‌شوند.
 * - اسناد با لینکِ امن و غیرقابل‌حدس (توکنِ تصادفی، تاریخ انقضا) برای مشتری ارسال می‌شوند.
 */

require_once __DIR__ . '/finance_functions.php';
require_once __DIR__ . '/orders_functions.php';

if (!defined('CTR_SCHEMA_FLAG')) {
    define('CTR_SCHEMA_FLAG', __DIR__ . '/../storage/.contracts_schema_v1');
}

/* =========================================================================
   ساختار دیتابیس
   ========================================================================= */

function ctr_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (is_file(CTR_SCHEMA_FLAG)) {
        return $ready = true;
    }
    $ddl = [
        "CREATE TABLE IF NOT EXISTS `contract_templates` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `version` INT UNSIGNED NOT NULL DEFAULT 1,
          `body` MEDIUMTEXT NOT NULL,
          `number_prefix` VARCHAR(20) DEFAULT NULL,
          `attachment_text` VARCHAR(60) DEFAULT NULL,
          `note` VARCHAR(255) DEFAULT NULL,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `contracts` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_number` VARCHAR(40) NOT NULL,
          `customer_id` INT UNSIGNED NOT NULL,
          `quote_id` INT UNSIGNED NOT NULL,
          `order_id` INT UNSIGNED DEFAULT NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
          `template_id` INT UNSIGNED DEFAULT NULL,
          `template_version` INT UNSIGNED DEFAULT NULL,
          `template_body` MEDIUMTEXT,
          `attachment_text` VARCHAR(60) DEFAULT NULL,
          `contract_date` DATE NOT NULL,
          `fields_json` MEDIUMTEXT,
          `final_fields_json` MEDIUMTEXT,
          `rendered_html` MEDIUMTEXT,
          `quote_snapshot_json` MEDIUMTEXT,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `approved_by` INT UNSIGNED DEFAULT NULL,
          `approved_at` DATETIME DEFAULT NULL,
          `issued_by` INT UNSIGNED DEFAULT NULL,
          `issued_at` DATETIME DEFAULT NULL,
          `cancelled_by` INT UNSIGNED DEFAULT NULL,
          `cancelled_at` DATETIME DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          `updated_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_contract_number` (`contract_number`),
          KEY `idx_contract_customer` (`customer_id`),
          KEY `idx_contract_quote` (`quote_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `contract_links` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `doc_type` VARCHAR(20) NOT NULL,
          `token` VARCHAR(64) NOT NULL,
          `expires_at` DATETIME NOT NULL,
          `revoked` TINYINT(1) NOT NULL DEFAULT 0,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
          `first_viewed_at` DATETIME DEFAULT NULL,
          `last_viewed_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_contract_link_token` (`token`),
          KEY `idx_contract_link_contract` (`contract_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `contract_sends` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `customer_id` INT UNSIGNED NOT NULL,
          `quote_id` INT UNSIGNED DEFAULT NULL,
          `doc_types` VARCHAR(60) NOT NULL,
          `channel` VARCHAR(20) NOT NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'sending',
          `result_note` VARCHAR(500) DEFAULT NULL,
          `link_ids` VARCHAR(100) DEFAULT NULL,
          `sent_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          `updated_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_contract_sends_contract` (`contract_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('ctr_ready: ' . $e->getMessage());
        }
    }
    // نام پدر و عنوان (آقای/خانم) در پرونده‌ی مشتری
    if (function_exists('finance_schema_ready') && finance_schema_ready($pdo)) {
        foreach ([
            'father_name' => 'ALTER TABLE customer_kyc ADD COLUMN father_name VARCHAR(100) DEFAULT NULL',
            'title'       => 'ALTER TABLE customer_kyc ADD COLUMN title VARCHAR(10) DEFAULT NULL',
        ] as $col => $sql) {
            try {
                $pdo->query("SELECT `$col` FROM customer_kyc LIMIT 1");
            } catch (Throwable $e) {
                try { $pdo->exec($sql); } catch (Throwable $e2) {}
            }
        }
    }
    try {
        foreach (['contract_templates', 'contracts', 'contract_links', 'contract_sends'] as $t) {
            $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        }
    } catch (Throwable $e) {
        return $ready = false;
    }
    if (!is_dir(dirname(CTR_SCHEMA_FLAG))) {
        @mkdir(dirname(CTR_SCHEMA_FLAG), 0755, true);
    }
    @file_put_contents(CTR_SCHEMA_FLAG, (string) time());
    return $ready = true;
}

/* =========================================================================
   وضعیت‌ها و دسترسی‌ها
   ========================================================================= */

function ctr_statuses(): array
{
    return [
        'draft'     => ['label' => 'پیش‌نویس',   'color' => 'secondary', 'icon' => 'fa-pen'],
        'approved'  => ['label' => 'تأییدشده',  'color' => 'info',      'icon' => 'fa-circle-check'],
        'issued'    => ['label' => 'صادرشده',   'color' => 'success',   'icon' => 'fa-stamp'],
        'cancelled' => ['label' => 'باطل‌شده',  'color' => 'dark',      'icon' => 'fa-ban'],
    ];
}

function ctr_doc_types(bool $invoice = false): array
{
    // سندِ «quote» بعد از صدورِ فاکتور، خودِ «فاکتور فروش» است (نه پیش‌فاکتور)
    return [
        'contract' => ['label' => 'قرارداد',     'file' => 'قرارداد',     'icon' => 'fa-file-signature'],
        'quote'    => $invoice ? ['label' => 'فاکتور', 'file' => 'فاکتور', 'icon' => 'fa-file-invoice-dollar']
                               : ['label' => 'پیش‌فاکتور',  'file' => 'پیش‌فاکتور',  'icon' => 'fa-file-invoice'],
        'services' => ['label' => 'شرح خدمات',   'file' => 'شرح-خدمات',   'icon' => 'fa-list-check'],
    ];
}

function ctr_send_statuses(): array
{
    return [
        'sending' => ['label' => 'در حال ارسال', 'color' => 'warning'],
        'sent'    => ['label' => 'ارسال موفق',   'color' => 'success'],
        'failed'  => ['label' => 'ارسال ناموفق', 'color' => 'danger'],
        'viewed'  => ['label' => 'مشاهده‌شده توسط مشتری', 'color' => 'primary'],
    ];
}

/** کاربر می‌تواند پرونده‌ی این مشتری را مدیریت کند (همان قاعده‌ی پرونده‌ی مشتری) */
function ctr_can_manage_customer(PDO $pdo, array $user, int $ownerId): bool
{
    return $ownerId === (int) $user['id']
        || (function_exists('can_manage_service_requests') && can_manage_service_requests($user))
        || (function_exists('leader_supervises_owner') && leader_supervises_owner($pdo, $user, $ownerId));
}

/** تأیید و صدورِ قرارداد: واحدِ مالی / مدیران */
function ctr_can_approve(array $user): bool
{
    return (function_exists('is_super_admin') && is_super_admin($user)) || user_can('finance_orders_decide', $user)
        || user_can('contracts_manage', $user); // واحدِ قرارداد: تنظیم/ویرایش/صدور (بدونِ تصمیمِ مالیِ سفارش)
}

function ctr_can_view(PDO $pdo, array $user, array $contract): bool
{
    if (ctr_can_approve($user) || user_can('finance_orders_view', $user) || user_can('contracts_view_all', $user)
        || ctr_can_manage_customer($pdo, $user, (int) ($contract['owner_user_id'] ?? 0))) {
        return true;
    }
    return function_exists('kyc_can_view') && kyc_can_view($pdo, $user, (int) $contract['customer_id']);
}

/* =========================================================================
   عدد و تاریخ به حروف
   ========================================================================= */

function fa_num_words(int $n): string
{
    if ($n === 0) {
        return 'صفر';
    }
    if ($n < 0) {
        return 'منفی ' . fa_num_words(-$n);
    }
    $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $scales = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];
    $three = static function (int $x) use ($ones, $teens, $tens, $hundreds): string {
        $p = [];
        if ($x >= 100) { $p[] = $hundreds[intdiv($x, 100)]; $x %= 100; }
        if ($x >= 20) { $p[] = $tens[intdiv($x, 10)]; $x %= 10; if ($x) $p[] = $ones[$x]; }
        elseif ($x >= 10) { $p[] = $teens[$x - 10]; }
        elseif ($x > 0) { $p[] = $ones[$x]; }
        return implode(' و ', $p);
    };
    $groups = [];
    $i = 0;
    while ($n > 0 && $i < count($scales)) {
        $g = $n % 1000;
        if ($g > 0) {
            $w = $three($g);
            if ($scales[$i] !== '') {
                $w .= ' ' . $scales[$i];
            }
            array_unshift($groups, $w);
        }
        $n = intdiv($n, 1000);
        $i++;
    }
    return implode(' و ', $groups);
}

function fa_ordinal_words(int $n): string
{
    $special = [1 => 'یکم', 2 => 'دوم', 3 => 'سوم', 30 => 'سی‌ام'];
    if (isset($special[$n])) {
        return $special[$n];
    }
    $w = fa_num_words($n);
    if (str_ends_with($w, 'سه')) {
        return mb_substr($w, 0, -2) . 'سوم';
    }
    if (str_ends_with($w, 'سی')) {
        return $w . '‌ام';
    }
    return $w . 'م';
}

function fa_jalali_months(): array
{
    return [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
}

/** «۱۴۰۵/۰۴/۰۹» → «نهم تیر ماه یک هزار و چهارصد و پنج» */
function fa_date_words(string $gregorianYmd): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $gregorianYmd, $m)) {
        return '';
    }
    [$jy, $jm, $jd] = gregorian_to_jalali_arr((int) $m[1], (int) $m[2], (int) $m[3]);
    return fa_ordinal_words($jd) . ' ' . fa_jalali_months()[$jm] . ' ماه ' . fa_num_words($jy);
}

function fa_money(int $n): string
{
    return to_persian_digits(number_format($n));
}

/* =========================================================================
   قالبِ قرارداد
   ========================================================================= */

/** راهنمای متغیرها (برای صفحه‌ی قالب و فرمِ قرارداد) */
function ctr_placeholders(): array
{
    return [
        'تاریخ_قرارداد' => 'تاریخ قرارداد (شمسی)',
        'تاریخ_قرارداد_به_حروف' => 'تاریخ قرارداد به حروف',
        'شماره_قرارداد' => 'شماره قرارداد',
        'عنوان' => 'آقای / خانم',
        'نام_و_نام_خانوادگی' => 'نام و نام خانوادگی مشتری',
        'نام_پدر' => 'نام پدر',
        'کد_ملی' => 'کد ملی',
        'شماره_همراه' => 'شماره همراه',
        'آدرس' => 'آدرس',
        'کد_پستی' => 'کد پستی',
        'شماره_پیش_فاکتور' => 'شماره پیش‌فاکتور (بعد از صدورِ فاکتور = شماره فاکتور)',
        'شماره_فاکتور' => 'شماره فاکتور (بعد از صدورِ فاکتور)',
        'تاریخ_فاکتور' => 'تاریخ فاکتور',
        'عنوان_سند_مالی' => '«فاکتور» یا «پیش‌فاکتور» (هر کدام که مبنای قرارداد است)',
        'شماره_سند_مالی' => 'شماره‌ی فاکتور، یا پیش‌فاکتور اگر هنوز فاکتور صادر نشده',
        'فهرست_خدمات' => 'عنوان خدماتِ پیش‌فاکتور',
        'مبلغ_قرارداد' => 'مبلغ کل قرارداد (تومان)',
        'مبلغ_قرارداد_به_حروف' => 'مبلغ کل به حروف',
        'مبلغ_واریزی' => 'مبلغ پرداخت‌شده',
        'مبلغ_واریزی_به_حروف' => 'مبلغ پرداخت‌شده به حروف',
        'مبلغ_بدهی' => 'مانده‌ی بدهی',
        'مبلغ_بدهی_به_حروف' => 'مانده‌ی بدهی به حروف',
        'تاریخ_تسویه_بدهی' => 'تاریخ تسویه‌ی بدهی (بدونِ قسط)',
        'بدهی_یکجا' => 'شرط: بدهی دارد و قسط‌بندی نشده',
        'مبلغ_قسط' => 'جمع مبلغِ اقساط',
        'مبلغ_قسط_به_حروف' => 'جمع اقساط به حروف',
        'تعداد_قسط' => 'تعداد اقساط',
        'تعداد_قسط_به_حروف' => 'تعداد اقساط به حروف',
        'مبلغ_قسط_ماهیانه' => 'مبلغ هر قسط',
        'مبلغ_قسط_ماهیانه_به_حروف' => 'مبلغ هر قسط به حروف',
        'تاریخ_اولین_قسط' => 'سررسید اولین قسط',
        'نحوه_پرداخت' => 'روشِ پرداخت (کارت به کارت، تهاتر، …)',
        'شیوه_تسویه' => 'شیوه‌ی تسویه (تسویه‌ی کامل / اقساطی / چکی)',
        'تعداد_چک' => 'تعداد چک‌ها (شرط: تسویه‌ی چکی)',
        'تعداد_چک_به_حروف' => 'تعداد چک‌ها به حروف',
        'مبلغ_چکها' => 'جمعِ مبلغِ چک‌ها',
        'مبلغ_چکها_به_حروف' => 'جمعِ چک‌ها به حروف',
        'فهرست_چکها' => 'فهرستِ چک‌ها (شماره، بانک، مبلغ، سررسید)',
        'مبلغ_تهاتر' => 'ارزشِ تهاتر (شرط: پرداخت با تهاتر)',
        'مبلغ_تهاتر_به_حروف' => 'ارزشِ تهاتر به حروف',
        'شرح_تهاتر' => 'تهاتر با چه چیزی',
        'مبلغ_تبدیل' => 'مبلغ منتقل‌شده از قراردادها/سفارش‌های قبلی',
        'قراردادهای_قبلی' => 'شماره‌ی قراردادها/سفارش‌های قبلیِ لغوشده',
        'مبلغ_تبدیل_به_حروف' => 'مبلغ منتقل‌شده به حروف',
        'سطح_پروموشن' => 'سطح پروموشن (دستی)',
    ];
}

/** نام‌های قدیمی/غلط‌املایی در فایلِ خامِ قرارداد → نامِ درست */
function ctr_placeholder_aliases(): array
{
    return [
        'واریزی_به_حروف' => 'مبلغ_واریزی_به_حروف',
        'مبلغ_بدیه_به_حروف' => 'مبلغ_بدهی_به_حروف',
        'مبلغ_قسط_ماهی انه' => 'مبلغ_قسط_ماهیانه',
        'مبلغ_قسط_ماهی انه_به_حروف' => 'مبلغ_قسط_ماهیانه_به_حروف',
    ];
}

/** متنِ پیش‌فرضِ قالب — همان متنِ «قرارداد تجارت» فعلیِ شرکت */
function ctr_default_template_body(): string
{
    return <<<'TPL'
# قرارداد تجارت

قرارداد حاضر براساس ماده ۱۰ قانون مدنی و با تابعیت از قوانین و مقررات جمهوری اسلامی ایران منعقد می‌گردد.

## ماده ۱) طرفین قرارداد

**طرف اول:** شرکت با مسئولیت محدود مدیریت فضای توسعه گستر صادرات آراد (**آراد برندینگ**) به شماره ثبت **۱۸۹۰۳** و شناسه ملی **۱۴۰۰۸۸۶۰۵۵۴** و به نشانی **قم، خیابان جمهوری، بین بلوار قائم و خیابان قیام، ساختمان آراد برندینگ** کدپستی **۳۷۱۶۸۳۸۱۸۳** که در این قرارداد «شرکت» هم نامیده می‌شود.

تبصره – وفق آگهی آخرین تغییرات شرکت مدیریت فضای توسعه گستر صادرات آراد، آقای علیرضا شعبانی به همراه مهر شرکت، صاحب حق امضا می‌باشند. لذا قرارداد حاضر با امضای ایشان، به همراه مهر شرکت، معتبر بوده و لازم‌الاجرا خواهد بود.

**طرف دوم (کارفرما):** «عنوان» «نام_و_نام_خانوادگی» فرزند «نام_پدر» کدملی «کد_ملی» شماره همراه «شماره_همراه» به نشانی: «آدرس».
کدپستی: «کد_پستی» که در این قرارداد به اختصار «کارفرما» هم نامیده می‌شود.

## ماده ۲) موضوع قرارداد

موضوع قرارداد عبارتست از:
الف) ارائه خدمات تخصصی اجرائی شرکت مدیریت فضای توسعه گستر صادرات آراد در حوزه تجارت مطابق فاکتور پیوستی («عنوان_سند_مالی» شماره «شماره_سند_مالی» و شرح خدمات پیوست).
ب) ارائه خدمات تخصصی آموزش و مشاوره کسب و کار در حوزه فعالیت کارفرما (تجارت) عبارتست از تجارت یک محصول مشخص که کارفرما آن را انتخاب نموده و از طریق تیکت به شرکت طبق قوانین تجارت الکترونیک اعلام می‌نماید.

**نکته** – با توجه به نحوه ارتباطات طرفین، مذکور در ماده ۵، تاکید می‌شود کارفرما باید کلیه اطلاعات لازم جهت اجرای موضوع قرارداد از قبیل نام محصول و کمیت و کیفیت آن را از طریق ارسال تیکت در سامانه جامع به آدرس aradbranding.me به شرکت اعلام نماید.

**توجه:** قسمت سامانه جامع به آدرس aradbranding.me فقط جهت انجام کار قرارداد می‌باشد و بار مالی برای آراد برندینگ ندارد.

## ماده ۳) تاریخ و مدت قرارداد

۳-۱) تاریخ قرارداد عبارتست از «تاریخ_قرارداد» به حروف «تاریخ_قرارداد_به_حروف».

تبصره ۱ – مدت این قرارداد از زمان انعقاد (مطابق با بند فوق) به مدت یک سال تعیین می‌گردد. شایان ذکر است بعد از پایان قرارداد، با توافق و رضایت طرفین، این قرارداد قابل تمدید می‌باشد و اگر قرارداد مذکور تمدید نشد طرفین هر گونه ادعایی را از هم سلب و ساقط نموده‌اند.

## ماده ۴) امور مالی قرارداد

۴-۱- مبلغ قرارداد عبارتست از مبلغی که در آخرین فاکتور طبق تعرفه درج گردیده و ضمیمه این قرارداد می‌باشد (مبلغ «مبلغ_قرارداد» تومان به حروف «مبلغ_قرارداد_به_حروف» تومان).
۴-۲- پرداخت مالیات بر ارزش افزوده که به رقم قرارداد تعلق می‌گیرد برعهده کارفرما (طرف دوم) می‌باشد.

**تبصره ۱: نحوه پرداخت مبلغ قرارداد:**

[[اگر مبلغ_تبدیل]]
الف) مبلغ «مبلغ_تبدیل» تومان به حروف «مبلغ_تبدیل_به_حروف» تومان پیرو توافق طرفین، بابت کنسل کردن کلیه قراردادها و سفارشات قبلی، به این قرارداد منتقل شده و بابت مبلغ این قرارداد محاسبه شد. لذا قراردادها و تعهدات قبلی کان لم یکن خواهد بود و طرف دوم قرارداد حاضر، حق هیچگونه ادعایی راجع به مبلغ و تعهدات مذکور نخواهد داشت و صرفا قرارداد حاضر معتبر خواهد بود.
[[/اگر]]

[[اگر مبلغ_واریزی]]
ب) مبلغ «مبلغ_واریزی» تومان به حروف «مبلغ_واریزی_به_حروف» تومان پرداخت شد.
[[/اگر]]

[[اگر بدهی_یکجا]]
ج) مبلغ «مبلغ_بدهی» تومان به حروف «مبلغ_بدهی_به_حروف» تومان تا تاریخ «تاریخ_تسویه_بدهی» پرداخت خواهد شد.
[[/اگر]]

[[اگر تعداد_قسط]]
ج) حسب توافق فی‌مابین مقرر گردید مبلغ قرارداد به نحو اقساط به شرح ذیل پرداخت گردد: تاجر موظف است الباقی مبلغ که «مبلغ_قسط» تومان به حروف «مبلغ_قسط_به_حروف» تومان است در «تعداد_قسط» (به حروف «تعداد_قسط_به_حروف») قسط مساوی ماهیانه به مبلغ «مبلغ_قسط_ماهیانه» تومان به حروف «مبلغ_قسط_ماهیانه_به_حروف» تومان در هر ماه به همان حساب اعلامی شرکت واریز نماید. پرداخت اقساط از تاریخ «تاریخ_اولین_قسط» انجام خواهد شد.
[[/اگر]]

[[اگر مبلغ_تهاتر]]
ب) مبلغ «مبلغ_تهاتر» تومان به حروف «مبلغ_تهاتر_به_حروف» تومان از مبلغ قرارداد از طریق تهاتر با «شرح_تهاتر» پرداخت گردید.
[[/اگر]]

[[اگر تعداد_چک]]
ج) الباقی مبلغ قرارداد به مبلغ «مبلغ_چکها» تومان به حروف «مبلغ_چکها_به_حروف» تومان طی «تعداد_چک» (به حروف «تعداد_چک_به_حروف») فقره چک به شرح ذیل به شرکت تحویل گردید و تاجر متعهد است وجه چک‌ها را در سررسیدهای مقرر در حساب تأمین نماید: «فهرست_چکها».
[[/اگر]]

د) در صورت تهاتر مبلغ قرارداد با مال منقول یا غیر منقولی (غیر از ریال ایران) اعم از خودرو، سکه، دلار، ملک و غیره، مبلغ ریالی مال تهاتر شده مدنظر خواهد بود و چنانچه به هر دلیلی قرارداد حاضر منحل گردد و بنا بر استرداد مبلغ باشد، شرکت موظف به استرداد مبلغ ریالی قرارداد مندرج در ماده ۴ قرارداد حاضر خواهد بود نه عین اموال تهاتر شده.

با توجه به آفر ارائه شده توسط شرکت آراد برندینگ، الباقی مبلغ قرارداد (در صورت وجود) به عنوان تخفیف محاسبه شده و لذا لازم نیست مشتری مبلغ جدیدی به شرکت بپردازد.

[[اگر سطح_پروموشن]]
تبصره: همزمان با پرداخت پیش قسط، خدمات پروموشن «سطح_پروموشن» برای تاجر فعال و در اختیار وی قرار می‌گیرد. لیکن چنانچه تاجر در مواعد معین مبلغ بدهی را پرداخت ننماید یا با تاخیر پرداخت نماید؛ شرکت حق خواهد داشت نسبت به تعدیل و تبدیل پروموشن متناسب با مجموع مبالغ پرداختی تاجر تا آن روز اقدام نماید. تاجر حق هر گونه ادعا و اعتراض حقوقی یا کیفری را در این خصوص از خود سلب و اسقاط نمود.
[[/اگر]]

## ماده ۵) اعلانات و ارتباطات طرفین

روند اجرایی خدمات موضوع قرارداد:
کلیه اعلانات، اخطارها، پیام‌ها و مکاتبات شرکت مدیریت فضای توسعه گستر صادرات آراد، جهت اجرای خدمات موضوع قرارداد، از طریق پنل کاربری کارفرما در سامانه جامع به آدرس aradbranding.me و توسط ارسال تیکت صورت می‌گیرد. کارفرما موظف است روزانه با ورود به حساب کاربری خود، تیکت‌های ارسالی را بررسی نماید. طرف اول مسئولیتی در خصوص عدم توجه و یا عدم مشاهده پیام‌ها توسط کارفرما نخواهد داشت.

تبصره: سامانه جامع به آدرس aradbranding.me سامانه‌ای برای ارتباط واحدهای اجرایی شرکت مدیریت فضای توسعه گستر صادرات آراد با کارفرما می‌باشد. کارفرما با اکانتی که توسط طرف اول برای او تعریف شده، به سامانه وارد می‌شود. این سامانه برای کارفرما، یک پنل مدیریتی جهت سفارشات ایجاد می‌نماید.

## ماده ۶) تعاریف

به جهت جلوگیری از بروز اختلافات و شفافیت بیشتر اصطلاحات مذکور در قرارداد حاضر یا فاکتور مربوطه، این اصطلاحات به شرح ذیل توضیح داده می‌شود. شایان ذکر است که با توجه به توافق طرفین، ممکن است فقط یک یا چند مورد از اصطلاحات مذکور در فاکتور قید شده و موضوع قرارداد باشند.

**الف- خدمات «یکباره، هفتگی، ماهانه، دائمی»**
خدمات یکباره خدماتی هستند که در طول مدت قرارداد، صرفا یک مرتبه برای مشتری انجام می‌گردند.
خدمات هفتگی عبارتند از خدماتی که در طول مدت قرارداد هر هفته، یک مرتبه به مشتری ارائه می‌گردند.
خدمات ماهانه عبارتند از خدماتی که در طول مدت قرارداد هر یک ماه، یک مرتبه به مشتری ارائه می‌گردند.
خدمات دائمی عبارتند از خدماتی که در طول مدت قرارداد، مستمرا به طرف دوم ارائه می‌گردند. البته راجع به میتینگ‌هایی که بصورت دائمی ارائه می‌شوند، دائمی بدین معناست که هر تعداد مرتبه‌ای که در طول مدت قرارداد میتینگ برگزار گردد طرف دوم می‌تواند در آن میتینگ‌ها شرکت کند.

**ب- کار انجام شده و کار انجام نشده**
خدمات یکباره پس از انجام شدن و ارائه در سامانه جامع به آدرس aradbranding.me، «کار انجام شده» محسوب می‌شوند.
خدمات هفتگی بر اساس تعداد هفته‌هایی که از قرارداد گذشته باشد و حسب مورد با اعلام یا ارسال لینک در سامانه جامع به آدرس aradbranding.me یا انتشار محتوا در سایت کارفرما، کار انجام شده محسوب می‌شوند.
خدمات ماهانه بر اساس تعداد ماه‌هایی که از قرارداد گذشته باشد و ارسال لینک در سامانه جامع به آدرس aradbranding.me، «کار انجام شده» محسوب می‌شوند.
خدمات دائمی بر اساس تعداد روزهایی که از قرارداد گذشته باشد و حسب مورد با ارسال لینک در سامانه جامع به آدرس aradbranding.me یا تقاضای کارفرما و پاسخ شرکت، کار انجام شده محسوب می‌شوند.

## ماده ۷) شرایط حاکم بر قرارداد

۷-۱- طرفین موظفند کلیه اطلاعات محرمانه طرف دیگر از جمله اسناد حقوقی و توافق‌نامه همکاری فی‌مابین را در زمان قرارداد و پس از آن محرمانه نگه دارند، مگر در مورد مراجع ذی‌صلاح قانونی که طرفین در صورت لزوم، طبق قانون مجازند تا اطلاعات مربوط به یکدیگر را با دستور رسمی نهادهای قضایی، در اختیار مراجع مذکور قرار دهند.
۷-۲- هیچ یک از مواد و مفاد قرارداد حاضر به معنای شراکت یا وجود رابطه کارگر و کارفرمایی و سرمایه‌گذاری فی‌مابین کارفرما و شرکت مدیریت فضای توسعه گستر صادرات آراد نبوده و نخواهد بود.
۷-۳- در صورت بروز شرایط غیر منتظره و غیر مترقبه، مانند جنگ، قطعی سراسری اینترنت، مشکلات فنی زیرساخت‌ها و عدم امکان ارائه خدمات از سوی شرکت، قرارداد موقتا متوقف و پس از رفع آن به همان روال سابق ادامه می‌یابد و زمان توقف جزو مدت قرارداد محسوب نمی‌شود. مالک تشخیص شرایط غیر منتظره و غیر مترقبه نظر داور مرضی‌الطرفین می‌باشد.
۷-۴- قرارداد حاضر متضمن کلیه توافقات طرفین بوده و توافقات و اظهارات پیشین، اعم از شفاهی و کتبی، در ارتباط با موضوع این قرارداد از تاریخ انعقاد، فاقد هرگونه اثر قانونی خواهد بود.
۷-۵- این قرارداد به هیچ عنوان سرمایه‌گذاری نمی‌باشد و در قبال خدمات ارائه شده تنظیم می‌گردد.

## ماده ۸) تعهدات کارفرما

۸-۱- کارفرما متعهد می‌گردد در قبال امکاناتی که از سوی شرکت در اختیارش قرار گیرد تمام شئونات اخلاقی را رعایت نماید. در غیر اینصورت شرکت مسئولیتی از بابت عدم رعایت شئونات اخلاقی نخواهد داشت. همچنین بصورت کلی، مسئولیت قانونی آنچه کارفرما از امکانات تجارت الکترونیک استفاده می‌نماید بر عهده کارفرما می‌باشد.
۸-۲- کارفرما می‌پذیرد که تمامی درخواست‌های او، با اعلام در سامانه جامع به آدرس aradbranding.me رسمیت می‌یابد و چنانچه طرح درخواست کارفرما مبنی بر توقف و یا تغییر در انجام سفارش و یا موارد مشابه از طریقی به جز ارسال تیکت به دپارتمان مربوطه در این سامانه انجام شود، شرکت مسئولیتی در قبال عدم اجرای آن نخواهد داشت.
۸-۳- کارفرما موظف است در قبال هر سفارش، حداکثر ظرف ۲۴ ساعت به تیکت‌های ارسالی مربوطه در سامانه جامع به آدرس aradbranding.me پاسخ دهد.

## ماده ۹) تعهدات شرکت

۹-۱- شرکت متعهد به حفظ تمام اسرار کاری کارفرما در نزد خود می‌باشد.
۹-۲- شرکت موظف است مطابق پاسخ‌هایی که کارفرما به سوالات مربوطه در تیکت‌های هر سفارش درج نموده، تعهدات قراردادی را به انجام برساند. لذا در صورت عدم پاسخ کارفرما به سوالات تیکت‌ها، شرکت تکلیفی به انجام تعهدات قراردادی نخواهد داشت و در صورت انجام تمام یا بخشی از تعهدات قراردادی، به صلاحدید خود عمل خواهد نمود.

## ماده ۱۰) روش حل و فصل اختلاف

طرفین به این وسیله و ضمن عقد بیع خارج لازم، متعهد و ملزم شدند که به طور کلی هرگونه اختلاف فی‌مابین و در هر موضوعی را از طریق داور مرضی‌الطرفین حل و فصل نمایند. آقای ناصر سرحدی به شماره تماس ۰۹۱۲۲۵۲۳۱۴۳ وکیل پایه یک دادگستری به شماره پروانه ۱۶۷۷ عضو کانون وکلای دادگستری استان قم به عنوان داور مرضی‌الطرفین انتخاب و در صورت حدوث هرگونه اختلاف و دعاوی ناشی از این قرارداد یا راجع به آن، رأیی که پس از درخواست داوری هر کدام از طرفین قرارداد که از طریق ارسال اظهارنامه رسمی به داور، صادر و اعلام می‌گردد، برای طرفین قطعی و لازم‌الاتباع و لازم‌الاجرا می‌باشد.

تبصره – طرفین توافق نمودند که درخواست‌کننده داوری موظف است حق‌الزحمه داور را قبل از رسیدگی داور پرداخت نماید. در غیر اینصورت زمان شروع داوری تا پرداخت کامل حق‌الزحمه داور به تعویق خواهد افتاد. طبق آئین‌نامه حق‌الزحمه داوری مصوبه ریاست محترم قوه قضائیه به تاریخ تصویب ۱۳۸۰/۰۹/۲۰ و تاریخ انتشار روزنامه رسمی به شماره ۶۵۶۳ به تاریخ ۱۳۸۰/۱۰/۱۵ حق‌الزحمه داور در این قرارداد ۵ درصد مبلغ قرارداد خواهد بود و رای صادره داوری با توافق طرفین از طریق ارسال اظهارنامه ابلاغ خواهد شد.

## ماده ۱۱) فسخ یا اقاله قرارداد

۱۱-۱- طرفین ضمن‌العقد حق برهم زدن و فسخ یکطرفه قرارداد حاضر را تا پایان مدت قرارداد از خود سلب و اسقاط نمودند و اقاله (تفاسخ) قرارداد صرفا با رضایت طرفین قابل اجرا خواهد بود. این قرارداد مطابق ماده ۲۳۱ قانون مدنی برای طرفین و قائم‌مقام ایشان لازم‌الاجرا و لازم‌الاتباع است.

## ماده ۱۲) نسخ قرارداد

طرفین در کمال صحت عقل و با آگاهی و اختیار کامل و بدون هیچ اکراه و اجبار و اضطراری اقدام به انعقاد این قرارداد نمودند. این قرارداد در دو نسخه متحدالشکل و ۱۲ ماده در تاریخ «تاریخ_قرارداد» فی‌مابین طرفین امضاء و تبادل گردید که هر دو نسخه دارای اعتبار یکسان می‌باشند.
TPL;
}

/** آخرین نسخه‌ی فعالِ قالب (اگر هنوز قالبی نیست، قالبِ پیش‌فرض ساخته می‌شود) */
function ctr_template_active(PDO $pdo): array
{
    ctr_template_add_settle_clauses($pdo);
    $row = null;
    try {
        $row = $pdo->query('SELECT * FROM contract_templates ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }
    if (!$row) {
        try {
            $pdo->prepare('INSERT INTO contract_templates (version, body, number_prefix, attachment_text, note, created_at) VALUES (1, ?, ?, ?, ?, ?)')
                ->execute([ctr_default_template_body(), '', 'دارد', 'نسخه‌ی اولیه — بر اساسِ فایلِ خامِ قراردادِ شرکت', date('Y-m-d H:i:s')]);
            $row = $pdo->query('SELECT * FROM contract_templates ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
        }
    }
    return $row ?: ['id' => null, 'version' => 1, 'body' => ctr_default_template_body(), 'number_prefix' => '', 'attachment_text' => 'دارد'];
}

/** ذخیره‌ی قالب = ساختِ نسخه‌ی جدید (نسخه‌های قبلی و قراردادهای صادرشده دست‌نخورده می‌مانند) */
function ctr_template_save(PDO $pdo, string $body, string $prefix, string $attachment, string $note, int $userId): int
{
    $cur = ctr_template_active($pdo);
    $ver = (int) ($cur['version'] ?? 0) + 1;
    $pdo->prepare('INSERT INTO contract_templates (version, body, number_prefix, attachment_text, note, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$ver, $body, mb_substr($prefix, 0, 20), mb_substr($attachment, 0, 60), mb_substr($note, 0, 255), $userId, date('Y-m-d H:i:s')]);
    return $ver;
}

/* =========================================================================
   تبدیلِ قالب به HTML
   ========================================================================= */

/**
 * نشانه‌گذاریِ ساده‌ی قالب:
 *   # عنوان              → عنوانِ وسط‌چین
 *   ## ماده …           → عنوانِ ماده
 *   **متن**             → پررنگ
 *   «نام_متغیر»          → مقدارِ واقعی (فقط متغیرهای شناخته‌شده؛ بقیه‌ی «…» همان متن می‌مانند)
 *   [[اگر متغیر]] … [[/اگر]] → فقط وقتی متغیر مقدار دارد نمایش داده می‌شود
 *   [[صفحه_جدید]]       → شکستِ صفحه در چاپ
 *   خطِ خالی             → پاراگرافِ جدید
 */
function ctr_render_body(string $body, array $fields, bool $preview = false): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $aliases = ctr_placeholder_aliases();
    $val = static function (string $name) use ($fields, $aliases) {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $key = str_replace(' ', '', $name);
        if (isset($aliases[$name])) $key = $aliases[$name];
        elseif (isset($aliases[$key])) $key = $aliases[$key];
        return [$key, array_key_exists($key, $fields) ? (string) $fields[$key] : null];
    };
    $isEmpty = static fn(?string $v) => $v === null || trim($v) === '' || preg_match('/^[0۰,٬\s]+$/u', $v);

    // شرط‌ها
    $body = preg_replace_callback('/\[\[\s*اگر\s+([^\]]+?)\s*\]\](.*?)\[\[\s*\/\s*اگر\s*\]\]/su', static function ($m) use ($val, $isEmpty) {
        [, $v] = $val($m[1]);
        return $isEmpty($v) ? '' : trim($m[2], "\n");
    }, $body);

    $blocks = preg_split("/\n\s*\n/u", trim($body));
    $html = [];
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;
        if (preg_match('/^\[\[\s*صفحه_جدید\s*\]\]$/u', $block)) {
            $html[] = '<div class="ctr-pb"></div>';
            continue;
        }
        $lines = [];
        $kind = 'p';
        foreach (explode("\n", $block) as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($i === 0 && str_starts_with($line, '## ')) { $kind = 'h3'; $line = mb_substr($line, 3); }
            elseif ($i === 0 && str_starts_with($line, '# ')) { $kind = 'h1'; $line = mb_substr($line, 2); }
            $safe = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $safe = preg_replace_callback('/«([^«»\n]{1,60})»/u', static function ($m) use ($val, $isEmpty, $preview) {
                [$key, $v] = $val(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if (!array_key_exists($key, ctr_placeholders())) {
                    return $m[0]; // «شرکت»، «کارفرما» و … متنِ عادی‌اند
                }
                if ($isEmpty($v) && !in_array($key, ['مبلغ_واریزی'], true)) {
                    return $preview ? '<span class="ph-miss">«' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '»</span>' : '<span class="ph-blank">..................</span>';
                }
                return '<b class="ph">' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</b>';
            }, $safe);
            $safe = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $safe);
            $lines[] = $safe;
        }
        if (!$lines) continue;
        $inner = implode('<br>', $lines);
        $html[] = $kind === 'h1' ? '<h1 class="ctr-title">' . $inner . '</h1>' : ($kind === 'h3' ? '<h3 class="ctr-art">' . $inner . '</h3>' : '<p>' . $inner . '</p>');
    }
    return ctr_fix_lettering(implode("\n", $html));
}

/* =========================================================================
   اطلاعاتِ قرارداد (خودکار از پرونده و سفارش)
   ========================================================================= */

function ctr_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ct.*, c.full_name AS customer_name, c.mobile AS customer_mobile, c.owner_user_id, q.quote_number, q.status AS quote_status,
            cu.full_name AS creator_name, au.full_name AS approver_name, iu.full_name AS issuer_name
        FROM contracts ct
        JOIN customers c ON c.id = ct.customer_id
        LEFT JOIN quotes q ON q.id = ct.quote_id
        LEFT JOIN users cu ON cu.id = ct.created_by
        LEFT JOIN users au ON au.id = ct.approved_by
        LEFT JOIN users iu ON iu.id = ct.issued_by
        WHERE ct.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function ctr_for_customer(PDO $pdo, int $customerId): array
{
    if (!ctr_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare('SELECT ct.*, q.quote_number FROM contracts ct LEFT JOIN quotes q ON q.id = ct.quote_id WHERE ct.customer_id = ? ORDER BY ct.id DESC');
    $st->execute([$customerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ctr_next_number(PDO $pdo, string $prefix = ''): string
{
    [$jy] = gregorian_to_jalali_arr((int) date('Y'), (int) date('m'), (int) date('d'));
    $base = $prefix . $jy . '-';
    $st = $pdo->prepare('SELECT contract_number FROM contracts WHERE contract_number LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$base . '%']);
    $last = (string) ($st->fetchColumn() ?: '');
    $seq = $last !== '' ? ((int) substr($last, strlen($base))) + 1 : 1;
    return $base . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/** اطلاعاتِ مالیِ معامله از سفارشِ همان پیش‌فاکتور */
function ctr_finance(PDO $pdo, array $quote, ?array $order = null): array
{
    $out = ['order' => null, 'total' => (int) $quote['total_amount'], 'paid' => 0, 'balance' => (int) $quote['total_amount'],
            'installments' => [], 'method' => ''];
    if (!function_exists('orders_active_for_quote')) {
        return $out;
    }
    $order = $order ?: orders_active_for_quote($pdo, (int) $quote['id']);
    if (!$order || ($order['status'] ?? '') === 'rejected') {
        return $out;
    }
    $out['order'] = $order;
    $out['total'] = (int) $order['total_amount'];
    if (function_exists('finance_schema_ready') && finance_schema_ready($pdo)) {
        $pays = array_values(array_filter(fin_payments($pdo, (int) $order['id']), static fn($p) => ($p['kind'] ?? '') !== 'transfer'));
        $inst = fin_installments($pdo, (int) $order['id']);
        $f = fin_compute($order, $pays, $inst);
        // پرداختِ اولیه‌ی در انتظارِ تأییدِ مالی هم حساب می‌شود؛ مالی پیش از صدور تأیید می‌کند
        $out['paid'] = (int) $f['paid'] + (int) $f['pending_paid'];
        $out['installments'] = $inst;
    } else {
        $out['paid'] = (int) ($order['confirmed_amount'] ?? $order['paid_amount']);
    }
    $out['balance'] = max(0, $out['total'] - $out['paid']);
    $methods = function_exists('orders_payment_methods') ? orders_payment_methods() : [];
    $out['method'] = (string) ($methods[$order['payment_method']] ?? ($order['payment_method'] ?? ''));
    $out['method_key'] = (string) ($order['payment_method'] ?? '');
    $out['barter_desc'] = trim((string) ($order['barter_desc'] ?? ''));
    $out['settle_type'] = (string) ($order['settle_type'] ?? '');
    return $out;
}

/**
 * مقادیرِ متغیرها. $manual: مقادیرِ دستیِ ذخیره‌شده در قرارداد (نام پدر، عنوان، مبلغ تبدیل، …)
 * @return array{fields:array, missing:string[], fin:array}
 */
function ctr_build_fields(PDO $pdo, array $contract, array $quote, array $manual = [], ?array $invoice = null): array
{
    $kyc = function_exists('kyc_get') ? kyc_get($pdo, (int) $contract['customer_id']) : [];
    $fin = ctr_finance($pdo, $quote, $invoice);
    // مبنای قرارداد: بعد از صدورِ فاکتور، فاکتور (شماره و تاریخِ فاکتور)؛ قبل از آن، پیش‌فاکتور
    $isl = static fn(string $v): string => "\u{2066}" . to_persian_digits($v) . "\u{2069}";
    $invNo = $invoice ? (string) $invoice['order_number'] : '';
    $invDate = $invoice ? ctr_invoice_date($invoice) : '';
    $date = (string) ($manual['contract_date'] ?? $contract['contract_date'] ?? date('Y-m-d'));
    // اقساط و چک‌ها جدا: بندِ «اقساط» فقط برای اقساط، بندِ «چک» برای چک‌ها
    $allSched = $fin['installments'];
    $cheques = array_values(array_filter($allSched, static fn($i) => ($i['kind'] ?? 'installment') === 'cheque'));
    $inst = array_values(array_filter($allSched, static fn($i) => ($i['kind'] ?? 'installment') !== 'cheque'));
    $chqCount = count($cheques);
    $chqSum = array_sum(array_map(static fn($i) => (int) $i['amount'], $cheques));
    $chqLines = [];
    foreach ($cheques as $n => $c) {
        $chqLines[] = to_persian_digits((string) ($n + 1)) . '- چک شماره ' . "\u{2066}" . to_persian_digits((string) ($c['cheque_no'] ?? '')) . "\u{2069}"
            . (!empty($c['bank']) ? ' بانک ' . $c['bank'] : '') . ' به مبلغ ' . fa_money((int) $c['amount']) . ' تومان'
            . ' با سررسید ' . to_jalali((string) $c['due_date']);
    }
    $isBarter = ($fin['method_key'] ?? '') === 'barter';
    $instCount = count($inst);
    $instSum = array_sum(array_map(static fn($i) => (int) $i['amount'], $inst));
    $instEach = $instCount ? (int) $inst[0]['amount'] : 0;
    $firstDue = $instCount ? (string) $inst[0]['due_date'] : '';
    $transfer = ctr_transfer_total($manual);
    $debt = max(0, (int) $fin['balance'] - $transfer);
    // مبلغِ پرداخت‌شده در متنِ قرارداد (ماده ۴، تبصره ۱): اگر واریزیِ مشتری بیشتر از مبلغِ فاکتور باشد،
    // همان مبلغِ فاکتور درج می‌شود (منهای مبلغِ منتقل‌شده از قبل، تا جمعِ بندها از مبلغِ قرارداد بیشتر نشود).
    // اگر برابر یا کمتر باشد، همان مبلغِ پرداخت‌شده.
    $paidShown = min((int) $fin['paid'], max(0, (int) $fin['total'] - $transfer));
    $settle = (string) ($manual['تاریخ_تسویه_بدهی'] ?? '');
    if ($settle === '' && $debt > 0 && !$instCount && !$chqCount) {
        $settle = date('Y-m-d', strtotime($date . ' +21 days'));
    }
    $items = [];
    try {
        $st = $pdo->prepare('SELECT title_snapshot FROM quote_items WHERE quote_id = ? ORDER BY id');
        $st->execute([(int) $quote['id']]);
        $items = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }
    // عنوان و نام پدر فقط از «مدارک و اطلاعاتِ هویتیِ مشتری» خوانده می‌شود (مقدارِ دستیِ قدیمیِ قرارداد فقط اگر آن‌جا خالی باشد)
    $fatherName = trim((string) ($kyc['father_name'] ?? '')) ?: trim((string) ($manual['نام_پدر'] ?? ''));
    $title = trim((string) ($kyc['title'] ?? '')) ?: (trim((string) ($manual['عنوان'] ?? '')) ?: 'آقای');

    $f = [
        'تاریخ_قرارداد' => to_jalali($date),
        'تاریخ_قرارداد_به_حروف' => fa_date_words($date),
        'شماره_قرارداد' => "\u{2066}" . to_persian_digits((string) $contract['contract_number']) . "\u{2069}",
        'عنوان' => $title,
        'نام_و_نام_خانوادگی' => (string) ($contract['customer_name'] ?? ''),
        'نام_پدر' => $fatherName,
        // اتباع: شماره‌ی پاسپورت/کد فراگیر با نوعش (مثلاً «P01234567 (شماره پاسپورت)»)
        'کد_ملی' => empty($kyc['national_id']) ? '' : (($kyc['id_type'] ?? 'national') === 'national' ? to_persian_digits((string) $kyc['national_id'])
            : "\u{2066}" . (string) $kyc['national_id'] . "\u{2069} (" . kyc_id_label($kyc) . ')'),
        'شماره_همراه' => to_persian_digits((string) ($contract['customer_mobile'] ?? '')),
        'آدرس' => trim((string) ($kyc['address'] ?? '')),
        'کد_پستی' => !empty($kyc['postal_code']) ? to_persian_digits((string) $kyc['postal_code']) : '',
        // در قالب‌های قدیمی «پیش‌فاکتور شماره «شماره_پیش_فاکتور»» آمده؛ بعد از صدورِ فاکتور هم عبارت و هم شماره به فاکتور تبدیل می‌شود
        'شماره_پیش_فاکتور' => $isl($invoice ? $invNo : (string) $quote['quote_number']),
        'شماره_فاکتور' => $invoice ? $isl($invNo) : '',
        'تاریخ_فاکتور' => $invDate !== '' ? to_jalali($invDate) : '',
        'عنوان_سند_مالی' => $invoice ? 'فاکتور' : 'پیش‌فاکتور',
        'شماره_سند_مالی' => $isl($invoice ? $invNo : (string) $quote['quote_number']),
        'فهرست_خدمات' => implode('، ', $items),
        'مبلغ_قرارداد' => fa_money((int) $fin['total']),
        'مبلغ_قرارداد_به_حروف' => fa_num_words((int) $fin['total']),
        // پرداخت با تهاتر: به‌جای «پرداخت شد»، بندِ تهاتر («مبلغ_تهاتر») درج می‌شود
        'مبلغ_واریزی' => !$isBarter && $paidShown > 0 ? fa_money($paidShown) : '',
        'مبلغ_واریزی_به_حروف' => !$isBarter && $paidShown > 0 ? fa_num_words($paidShown) : '',
        'مبلغ_بدهی' => $debt > 0 ? fa_money($debt) : '',
        'مبلغ_بدهی_به_حروف' => $debt > 0 ? fa_num_words($debt) : '',
        'بدهی_یکجا' => ($debt > 0 && !$instCount && !$chqCount) ? 'بله' : '',
        'تاریخ_تسویه_بدهی' => $settle !== '' ? to_jalali($settle) : '',
        'مبلغ_قسط' => $instCount ? fa_money($instSum) : '',
        'مبلغ_قسط_به_حروف' => $instCount ? fa_num_words($instSum) : '',
        'تعداد_قسط' => $instCount ? to_persian_digits((string) $instCount) : '',
        'تعداد_قسط_به_حروف' => $instCount ? fa_num_words($instCount) : '',
        'مبلغ_قسط_ماهیانه' => $instCount ? fa_money($instEach) : '',
        'مبلغ_قسط_ماهیانه_به_حروف' => $instCount ? fa_num_words($instEach) : '',
        'تاریخ_اولین_قسط' => $firstDue !== '' ? to_jalali($firstDue) : '',
        'نحوه_پرداخت' => $fin['method'],
        'شیوه_تسویه' => function_exists('orders_settle_types') ? (orders_settle_types()[$fin['settle_type'] ?? ''] ?? ($chqCount ? 'چکی' : ($instCount ? 'اقساطی' : 'تسویه‌ی کامل'))) : '',
        'تعداد_چک' => $chqCount ? to_persian_digits((string) $chqCount) : '',
        'تعداد_چک_به_حروف' => $chqCount ? fa_num_words($chqCount) : '',
        'مبلغ_چکها' => $chqCount ? fa_money($chqSum) : '',
        'مبلغ_چکها_به_حروف' => $chqCount ? fa_num_words($chqSum) : '',
        'فهرست_چکها' => implode('؛ ', $chqLines),
        'مبلغ_تهاتر' => $isBarter && $paidShown > 0 ? fa_money($paidShown) : '',
        'مبلغ_تهاتر_به_حروف' => $isBarter && $paidShown > 0 ? fa_num_words($paidShown) : '',
        'شرح_تهاتر' => $isBarter ? (string) ($fin['barter_desc'] ?? '') : '',
        'مبلغ_تبدیل' => $transfer > 0 ? fa_money($transfer) : '',
        'مبلغ_تبدیل_به_حروف' => $transfer > 0 ? fa_num_words($transfer) : '',
        'قراردادهای_قبلی' => implode('، ', array_map(static fn($t) => "\u{2066}" . to_persian_digits((string) ($t['label'] ?? '')) . "\u{2069}", ctr_transfers($manual))),
        'سطح_پروموشن' => trim((string) ($manual['سطح_پروموشن'] ?? '')),
    ];
    $missing = [];
    foreach (['نام_پدر' => 'نام پدر', 'کد_ملی' => 'کد ملی', 'آدرس' => 'آدرس', 'کد_پستی' => 'کد پستی'] as $k => $label) {
        if (trim($f[$k]) === '') $missing[] = $label;
    }
    if (($quote['status'] ?? '') !== 'locked') {
        $missing[] = 'قفل‌بودنِ پیش‌فاکتور';
    }
    $fin['paid_shown'] = $paidShown;
    $fin['invoice'] = $invoice;
    $fin['overpaid'] = max(0, (int) $fin['paid'] - $paidShown);
    return ['fields' => $f, 'missing' => $missing, 'fin' => $fin, 'settle_raw' => $settle];
}

/**
 * فاکتورِ مبنای قرارداد (همان سفارشِ ثبت‌شده برای پیش‌فاکتور، در وضعیتِ «در انتظار بررسی مالی» یا «تأیید و ثبت شد»).
 *  - قراردادِ صادرشده: همان فاکتوری که در لحظه‌ی صدور منجمد شد (اگر آن موقع فاکتوری نبوده، null)
 *  - پیش‌نویس/تأییدشده: فاکتورِ فعلیِ پیش‌فاکتور؛ اگر هنوز فاکتور صادر نشده یا رد/لغو شده، null → مبنا پیش‌فاکتور است
 */
function ctr_invoice_order(PDO $pdo, array $contract): ?array
{
    if (($contract['status'] ?? '') === 'issued' && !empty($contract['quote_snapshot_json'])) {
        $s = json_decode((string) $contract['quote_snapshot_json'], true);
        if (is_array($s) && !empty($s['quote'])) {
            return !empty($s['order']) && is_array($s['order']) ? $s['order'] : null;
        }
    }
    return ctr_live_invoice_order($pdo, (int) $contract['quote_id']);
}

/** فاکتورِ فعلیِ یک پیش‌فاکتور (بدونِ درنظرگرفتنِ نسخه‌ی منجمد) */
function ctr_live_invoice_order(PDO $pdo, int $quoteId): ?array
{
    if ($quoteId <= 0 || !function_exists('orders_active_for_quote')) {
        return null;
    }
    $o = orders_active_for_quote($pdo, $quoteId);
    return ($o && in_array((string) ($o['status'] ?? ''), ['pending', 'approved'], true)) ? $o : null;
}

/** تاریخِ فاکتور — همان تاریخی که روی سندِ «فاکتور فروش» چاپ می‌شود */
function ctr_invoice_date(array $order): string
{
    $raw = (string) (($order['decided_at'] ?? '') ?: ($order['created_at'] ?? ''));
    return $raw !== '' ? date('Y-m-d', strtotime($raw)) : '';
}

/** بعد از صدورِ فاکتور، در متنِ قرارداد هیچ‌جا «پیش‌فاکتور» نمی‌آید (متغیرهای «…» دست نمی‌خورند) */
function ctr_body_invoice_wording(string $body): string
{
    return preg_replace('/پیش[\x{200C}\x{200F}\s\-]*فاکتور/u', 'فاکتور', $body) ?? $body;
}

/** نسخه‌ی منجمدِ پیش‌فاکتور (+ فاکتور، اگر صادر شده) برای پیوست‌های قراردادِ صادرشده */
function ctr_quote_snapshot(PDO $pdo, int $quoteId, ?array $order = null): array
{
    $st = $pdo->prepare('SELECT q.*, c.full_name AS customer_name, c.mobile AS customer_mobile, u.full_name AS issuer_name
        FROM quotes q JOIN customers c ON c.id = q.customer_id LEFT JOIN users u ON u.id = q.created_by WHERE q.id = ?');
    $st->execute([$quoteId]);
    $quote = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $it = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id');
    $it->execute([$quoteId]);
    return ['quote' => $quote, 'items' => $it->fetchAll(PDO::FETCH_ASSOC) ?: [], 'order' => $order, 'taken_at' => date('Y-m-d H:i:s')];
}

/** پیش‌فاکتور + ردیف‌ها برای پیوست: از نسخه‌ی منجمد (قرارداد صادرشده) یا پیش‌فاکتورِ فعلی */
function ctr_attachment_data(PDO $pdo, array $contract): array
{
    if (!empty($contract['quote_snapshot_json'])) {
        $s = json_decode((string) $contract['quote_snapshot_json'], true);
        if (is_array($s) && !empty($s['quote'])) {
            return $s;
        }
    }
    if (function_exists('quote_snapshot_descriptions')) {
        quote_snapshot_descriptions($pdo, (int) $contract['quote_id'], true);
    }
    return ctr_quote_snapshot($pdo, (int) $contract['quote_id'], ctr_live_invoice_order($pdo, (int) $contract['quote_id']));
}

/* =========================================================================
   صفحه‌ی چاپیِ قرارداد — روی سربرگِ رسمیِ شرکت (همان تصویرِ فایلِ خامِ قرارداد)
   ========================================================================= */

function ctr_letterhead_url(string $base = ''): string
{
    $custom = __DIR__ . '/../uploads/contract/letterhead.jpg';
    if (is_file($custom)) {
        return $base . 'uploads/contract/letterhead.jpg?v=' . filemtime($custom);
    }
    return $base . 'assets/img/contract-letterhead.jpg';
}

/**
 * $o: body_html, number, date_jalali, attachment_text, back_url, public, draft, base (مسیرِ نسبی)
 */
function ctr_doc_render(array $o): void
{
    $lh = ctr_letterhead_url($o['base'] ?? '');
    $title = 'قرارداد-' . preg_replace('/[^0-9A-Za-z\-]/', '', (string) ($o['number_raw'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  html, body { margin: 0; padding: 0; }
  body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: #eceae4; color: #111; direction: rtl; }
  .toolbar { text-align: center; padding: 14px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
  .toolbar a, .toolbar button { font-family: inherit; font-size: 14px; padding: 9px 20px; border-radius: 8px; border: 1px solid #d6d0c2; background: #fff; color: #222; text-decoration: none; cursor: pointer; }
  .toolbar button { background: #c0647b; color: #fff; border-color: #c0647b; font-weight: 700; }
  .draft-flag { background: #fff3cd; color: #7a5a00; border: 1px solid #f1d38a; border-radius: 8px; padding: 6px 14px; font-size: 13px; }
  .paper { width: 210mm; margin: 0 auto 30px; background: #fff; box-shadow: 0 8px 30px -18px rgba(0,0,0,.45); position: relative; }
  table.lay { width: 100%; border-collapse: collapse; }
  table.lay > thead td, table.lay > tfoot td, table.lay > tbody td { padding: 0; }
  .hd { height: 52mm; position: relative; background: url('<?= e($lh) ?>') top center / 210mm auto no-repeat; }
  .ft { height: 75mm; position: relative; background: url('<?= e($lh) ?>') bottom center / 210mm auto no-repeat; }
  .hv { position: absolute; left: 30mm; width: 26mm; text-align: center; font-size: 9.5pt; font-weight: 700; color: #222; line-height: 1; white-space: nowrap; }
  .hv.long { font-size: 8pt; left: 27mm; width: 32mm; }
  .hv.d { top: 19.4mm; } .hv.n { top: 27.6mm; } .hv.a { top: 36.2mm; }
  .sig { position: absolute; top: 4mm; left: 22mm; right: 18mm; display: flex; justify-content: space-between; font-weight: 800; font-size: 10.5pt; }
  .content { padding: 0 16mm 0 24mm; font-size: 10.5pt; line-height: 2.05; text-align: justify; }
  .content p { margin: 0 0 3.2mm; }
  .content h1.ctr-title { text-align: center; font-size: 13pt; margin: 0 0 5mm; font-weight: 800; }
  .content h3.ctr-art { font-size: 11pt; font-weight: 800; margin: 5mm 0 2mm; }
  .content b.ph { font-weight: 800; }
  .content .ph-miss { background: #fee2e2; color: #b91c1c; border-radius: 4px; padding: 0 4px; font-weight: 700; }
  .content .ph-blank { letter-spacing: 1px; }
  .ctr-pb { break-after: page; page-break-after: always; }
  .fixed-bg, .fixed-hv, .fixed-sig { display: none; }
  @media (max-width: 820px) {
    .paper { width: 100%; }
    .hd { height: 26vw; background-size: 100% auto; } .ft { height: 36vw; background-size: 100% auto; }
    .hv { font-size: 2.2vw; left: 14.3%; width: 12.5%; } .hv.d { top: 8.9vw; } .hv.n { top: 12.8vw; } .hv.a { top: 16.9vw; }
    .sig { font-size: 2.6vw; left: 10%; right: 8%; top: 2vw; }
    .content { padding: 0 6% 0 9%; font-size: 14px; }
  }
  @media print {
    @page { size: A4; margin: 0; }
    body { background: #fff; }
    .toolbar { display: none; }
    .paper { width: 210mm; margin: 0; box-shadow: none; background: transparent; }
    table.lay > thead, table.lay > tfoot { display: none; }
    /* فاصله‌ی سربرگ و پاورقی در «هر» صفحه تکرار می‌شود (box-decoration-break: clone) */
    .content { padding: 58mm 16mm 76mm 24mm; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
    /* سربرگِ کامل، تاریخ/شماره/پیوست و ردیفِ امضا روی تک‌تکِ صفحه‌ها */
    .fixed-bg { display: block; position: fixed; top: 0; left: 0; width: 210mm; height: 297mm; z-index: -1; }
    .fixed-bg img { width: 210mm; height: 297mm; display: block; }
    .fixed-hv { display: block; position: fixed; top: 0; left: 0; width: 210mm; height: 60mm; }
    .fixed-sig { display: flex; position: fixed; top: 228.5mm; left: 22mm; width: 170mm; justify-content: space-between; font-weight: 800; font-size: 10.5pt; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <?php if (!empty($o['back_url'])): ?><a href="<?= e($o['back_url']) ?>">→ برگشت</a><?php endif; ?>
  <button onclick="window.print()"><?= !empty($o['public']) ? 'دانلود / ذخیره PDF' : 'چاپ / ذخیره PDF' ?></button>
  <?php if (!empty($o['draft'])): ?><span class="draft-flag">پیش‌نویس — هنوز صادر نشده</span><?php endif; ?>
</div>
<div class="fixed-bg"><img src="<?= e($lh) ?>" alt=""></div>
<div class="fixed-hv">
  <div class="hv d"><?= e((string) $o['date_jalali']) ?></div>
  <div class="hv n<?= mb_strlen((string) $o['number']) > 11 ? ' long' : '' ?>"><bdi dir="ltr"><?= e((string) $o['number']) ?></bdi></div>
  <div class="hv a"><?= e((string) ($o['attachment_text'] ?? 'دارد')) ?></div>
</div>
<div class="fixed-sig"><span>امضای کارفرما</span><span>داور مرضی‌الطرفین</span><span>امضای شرکت</span></div>
<div class="paper">
  <table class="lay">
    <thead><tr><td><div class="hd">
      <div class="hv d"><?= e((string) $o['date_jalali']) ?></div>
      <div class="hv n<?= mb_strlen((string) $o['number']) > 11 ? ' long' : '' ?>"><bdi dir="ltr"><?= e((string) $o['number']) ?></bdi></div>
      <div class="hv a"><?= e((string) ($o['attachment_text'] ?? 'دارد')) ?></div>
    </div></td></tr></thead>
    <tfoot><tr><td><div class="ft">
      <div class="sig"><span>امضای کارفرما</span><span>داور مرضی‌الطرفین</span><span>امضای شرکت</span></div>
    </div></td></tr></tfoot>
    <tbody><tr><td><div class="content"><?= $o['body_html'] ?></div></td></tr></tbody>
  </table>
</div>
</body>
</html>
<?php
}

/* =========================================================================
   لینک‌های امنِ اسناد برای مشتری + سابقه‌ی ارسال
   ========================================================================= */

function ctr_link_for(PDO $pdo, int $contractId, string $docType, int $userId, int $days = 30): array
{
    $st = $pdo->prepare('SELECT * FROM contract_links WHERE contract_id = ? AND doc_type = ? AND revoked = 0 AND expires_at > ? ORDER BY id DESC LIMIT 1');
    $st->execute([$contractId, $docType, date('Y-m-d H:i:s', time() + 3 * 86400)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $token = bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO contract_links (contract_id, doc_type, token, expires_at, created_by, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$contractId, $docType, $token, date('Y-m-d H:i:s', time() + $days * 86400), $userId, date('Y-m-d H:i:s')]);
    $st = $pdo->prepare('SELECT * FROM contract_links WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function ctr_link_resolve(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM contract_links WHERE token = ? AND revoked = 0 AND expires_at > ? LIMIT 1');
    $st->execute([$token, date('Y-m-d H:i:s')]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** آدرسِ کاملِ سایت برای لینک‌هایی که برای مشتری فرستاده می‌شوند */
function ctr_site_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/**
 * پیام‌رسان‌های ثبت‌شده‌ی مشتری (برای هر دو شماره‌ی موبایل)
 * @return array<int, array{key:string, messenger:string, slot:string, mobile:string, label:string}>
 */
function ctr_customer_channels(PDO $pdo, int $customerId): array
{
    $out = [];
    try {
        $c = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $c->execute([$customerId]);
        $cust = $c->fetch(PDO::FETCH_ASSOC) ?: [];
        $bySlot = function_exists('customer_messengers_by_slot') ? customer_messengers_by_slot($pdo, $customerId) : ['mobile' => [], 'mobile_2' => []];
        $names = function_exists('social_network_options') ? social_network_options() : [];
        foreach (['mobile', 'mobile_2'] as $slot) {
            $mobile = trim((string) ($cust[$slot] ?? ''));
            if ($mobile === '') continue;
            foreach (array_unique($bySlot[$slot] ?? []) as $m) {
                if (!isset($names[$m])) continue;
                $out[] = ['key' => $m . ':' . $slot, 'messenger' => $m, 'slot' => $slot, 'mobile' => $mobile,
                          'label' => $names[$m] . ' — ' . to_persian_digits($mobile)];
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * نحوه‌ی بازکردنِ گفتگوی مشتری در هر پیام‌رسان.
 * واتساپ: لینکِ رسمیِ wa.me با متنِ آماده (پیام کامل با لینک‌ها در کادرِ ارسال قرار می‌گیرد).
 * بقیه: لینکِ رسمیِ بازکردنِ گفتگو با شماره؛ متن در کلیپ‌بورد کپی می‌شود (امکانِ پرکردنِ متن ندارند).
 */
function ctr_channel_open_url(string $channel, string $mobile, string $message): array
{
    $intl = function_exists('format_intl_phone') ? format_intl_phone($mobile) : preg_replace('/\D/', '', $mobile);
    switch ($channel) {
        case 'whatsapp':
            return ['url' => 'https://wa.me/' . $intl . '?text=' . rawurlencode($message), 'prefill' => true];
        case 'telegram':
            return ['url' => 'https://t.me/+' . $intl, 'prefill' => false];
        case 'eitaa':
            return ['url' => 'https://eitaa.com/+' . $intl, 'prefill' => false];
        case 'bale':
            return ['url' => 'https://ble.ir/+' . $intl, 'prefill' => false];
        case 'rubika':
            return ['url' => 'https://rubika.ir/', 'prefill' => false];
    }
    return ['url' => '', 'prefill' => false];
}

function ctr_sends_of(PDO $pdo, int $contractId): array
{
    $st = $pdo->prepare('SELECT s.*, u.full_name AS sender_name FROM contract_sends s LEFT JOIN users u ON u.id = s.sent_by WHERE s.contract_id = ? ORDER BY s.id DESC');
    $st->execute([$contractId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // «مشاهده‌شده»: اگر مشتری لینک‌های این ارسال را باز کرده باشد
    $lv = $pdo->prepare('SELECT doc_type, first_viewed_at, view_count FROM contract_links WHERE id = ?');
    foreach ($rows as &$r) {
        $r['views'] = [];
        foreach (array_filter(explode(',', (string) $r['link_ids'])) as $lid) {
            $lv->execute([(int) $lid]);
            if ($l = $lv->fetch(PDO::FETCH_ASSOC)) {
                $r['views'][] = $l;
            }
        }
    }
    unset($r);
    return $rows;
}

/** ذخیره‌ی نام پدر و عنوان در پرونده‌ی مشتری (یک‌بار ثبت، همه‌جا استفاده) */
function ctr_kyc_save_extra(PDO $pdo, int $customerId, array $data, int $userId): void
{
    if (!ctr_ready($pdo) || !function_exists('finance_schema_ready') || !finance_schema_ready($pdo)) {
        return;
    }
    $set = [];
    if (array_key_exists('father_name', $data)) {
        $fn = trim(preg_replace('/\s+/u', ' ', (string) $data['father_name']));
        if ($fn !== '') $set['father_name'] = mb_substr($fn, 0, 100);
    }
    if (array_key_exists('title', $data) && in_array($data['title'], ['آقای', 'خانم'], true)) {
        $set['title'] = $data['title'];
    }
    if (!$set) {
        return;
    }
    try {
        $ex = $pdo->prepare('SELECT 1 FROM customer_kyc WHERE customer_id = ? LIMIT 1');
        $ex->execute([$customerId]);
        $set['updated_by'] = $userId;
        if ($ex->fetchColumn()) {
            $pdo->prepare('UPDATE customer_kyc SET ' . implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set))) . ' WHERE customer_id = ?')
                ->execute(array_merge(array_values($set), [$customerId]));
        } else {
            $cols = array_keys($set);
            $pdo->prepare('INSERT INTO customer_kyc (customer_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
                ->execute(array_merge([$customerId], array_values($set)));
        }
    } catch (Throwable $e) {
        error_log('ctr_kyc_save_extra: ' . $e->getMessage());
    }
}

/** ساختِ پیش‌نویسِ قرارداد از یک پیش‌فاکتورِ قفل‌شده */
function ctr_create(PDO $pdo, int $quoteId, int $userId): array
{
    $st = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
    $st->execute([$quoteId]);
    $quote = $st->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        return ['ok' => false, 'message' => 'پیش‌فاکتور پیدا نشد.'];
    }
    if (($quote['status'] ?? '') !== 'locked') {
        return ['ok' => false, 'message' => 'برای ساختِ قرارداد، ابتدا پیش‌فاکتور را قفل کنید.'];
    }
    // هر سفارش / پیش‌فاکتور فقط یک قرارداد: قفل تا دوبار کلیک (یا دو تب) دو قرارداد نسازد
    $__lock = 'ctr_create_' . (int) $quote['customer_id'];
    $__locked = false;
    try { $__locked = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($__lock) . ', 25)')->fetchColumn() === 1; } catch (Throwable $e) {}
    try {
        return ctr_create_locked($pdo, $quote, $quoteId, $userId);
    } finally {
        if ($__locked) { try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($__lock) . ')'); } catch (Throwable $e) {} }
    }
}

/** قراردادِ فعالِ (باطل‌نشده‌ی) این پیش‌فاکتور یا سفارشِ آن (null = ندارد) */
function ctr_existing_for(PDO $pdo, int $quoteId, ?int $orderId): ?int
{
    $ex = $pdo->prepare("SELECT id FROM contracts WHERE (quote_id = ?" . ($orderId ? ' OR order_id = ?' : '') . ") AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
    $ex->execute($orderId ? [$quoteId, $orderId] : [$quoteId]);
    $id = (int) $ex->fetchColumn();
    return $id ?: null;
}

function ctr_create_locked(PDO $pdo, array $quote, int $quoteId, int $userId): array
{
    $order = function_exists('orders_active_for_quote') ? orders_active_for_quote($pdo, $quoteId) : null;
    if ($id = ctr_existing_for($pdo, $quoteId, $order ? (int) $order['id'] : null)) {
        return ['ok' => true, 'id' => $id, 'existing' => true,
            'message' => 'برای این سفارش قبلاً قرارداد ساخته شده؛ قراردادِ دوم ساخته نمی‌شود. همین قرارداد را ویرایش/اصلاح و دوباره صادر کنید.'];
    }
    // اطلاعاتِ هویتیِ مشتری باید پیش از ساختِ قرارداد کامل باشد (عنوان، نام پدر، کد ملی، آدرس، کد پستی، کارت ملی)
    if (function_exists('kyc_get')) {
        $kycMissing = kyc_missing_labels(kyc_get($pdo, (int) $quote['customer_id']));
        if ($kycMissing) {
            return ['ok' => false, 'kyc_incomplete' => true, 'customer_id' => (int) $quote['customer_id'],
                'message' => 'قرارداد ساخته نشد: اطلاعاتِ هویتیِ مشتری ناقص است (' . implode('، ', $kycMissing) . '). اول در «مدارک و اطلاعاتِ هویتیِ مشتری» تکمیلش کنید، بعد «ساخت قرارداد» را بزنید.'];
        }
    }
    $tpl = ctr_template_active($pdo);
    $now = date('Y-m-d H:i:s');
    // اگر قراردادِ همین پیش‌فاکتور قبلاً حذف شده، همان شماره دوباره استفاده می‌شود (شماره‌ی تازه = «قراردادِ دوم» به نظر می‌رسید)
    $reuse = '';
    try {
        $d = $pdo->prepare('SELECT d.contract_number FROM contract_deletions d WHERE d.quote_id = ? AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.contract_number = d.contract_number) ORDER BY d.id DESC LIMIT 1');
        $d->execute([$quoteId]);
        $reuse = (string) ($d->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    for ($try = 0; $try < 3; $try++) {
        $number = ($try === 0 && $reuse !== '') ? $reuse : ctr_next_number($pdo, (string) ($tpl['number_prefix'] ?? ''));
        try {
            $pdo->prepare('INSERT INTO contracts (contract_number, customer_id, quote_id, order_id, status, template_id, template_version, template_body, attachment_text, contract_date, fields_json, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$number, (int) $quote['customer_id'], $quoteId, $order ? (int) $order['id'] : null, 'draft',
                    $tpl['id'] ?? null, (int) ($tpl['version'] ?? 1), (string) $tpl['body'], (string) ($tpl['attachment_text'] ?? 'دارد'),
                    date('Y-m-d'), '{}', $userId, $now, $now]);
            return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'message' => 'پیش‌نویسِ قرارداد ساخته شد.'];
        } catch (Throwable $e) {
            error_log('ctr_create: ' . $e->getMessage());
        }
    }
    return ['ok' => false, 'message' => 'ساختِ قرارداد ناموفق بود.'];
}

/** متن و اطلاعاتِ نمایش/چاپِ یک قرارداد (صادرشده: از نسخه‌ی منجمد) */
function ctr_document(PDO $pdo, array $contract, bool $preview = true): array
{
    if ($contract['status'] === 'issued' && !empty($contract['rendered_html'])) {
        $ff = json_decode((string) $contract['final_fields_json'], true) ?: [];
        return ['html' => ctr_single_installment_wording(ctr_fix_lettering((string) $contract['rendered_html']), (string) ($ff['تعداد_قسط'] ?? '')), 'fields' => $ff, 'missing' => [], 'frozen' => true,
                'invoice' => ctr_invoice_order($pdo, $contract)];
    }
    $data = ctr_attachment_data($pdo, $contract);
    $invoice = $data['order'] ?? null;
    $manual = json_decode((string) ($contract['fields_json'] ?? '{}'), true) ?: [];
    $b = ctr_build_fields($pdo, $contract, $data['quote'], $manual, $invoice);
    $body = (string) $contract['template_body'];
    if ($invoice) {
        $body = ctr_body_invoice_wording($body);
    }
    return ['html' => ctr_single_installment_wording(ctr_render_body($body, $b['fields'], $preview), (string) ($b['fields']['تعداد_قسط'] ?? '')), 'fields' => $b['fields'],
            'missing' => $b['missing'], 'fin' => $b['fin'], 'frozen' => false, 'settle_raw' => $b['settle_raw'], 'invoice' => $invoice];
}

/**
 * پرداخت در «یک» قسط: عبارت‌های چندقسطی جمله‌ی اقساط درست می‌شوند
 *   «در ۱ (به حروف یک) قسط مساوی ماهیانه به مبلغ … تومان به حروف … تومان در هر ماه» ← «در یک قسط»
 *   «پرداخت اقساط از تاریخ …»  ← «پرداخت این قسط در تاریخ …»
 *   «به نحو اقساط به شرح ذیل»  ← «به شرح ذیل»
 * روی متنِ نهایی (HTML) کار می‌کند؛ پس قراردادهای صادرشده‌ی قبلی هم درست نمایش داده می‌شوند.
 */
function ctr_single_installment_wording(string $html, string $count): string
{
    if (trim(normalize_digits($count)) !== '1') return $html;
    $t = '(?:\s|<[^>]*>)*'; // فاصله یا تگ (مقادیرِ پررنگ)
    $html = preg_replace('/در' . $t . '[۱1]' . $t . '\(' . $t . 'به حروف' . $t . 'یک' . $t . '\)' . $t . 'قسط' . $t . 'مساوی' . $t . 'ماهیانه' . $t . 'به مبلغ.*?تومان' . $t . 'به حروف.*?تومان' . $t . 'در هر ماه/su',
        'در یک قسط', $html) ?? $html;
    // اگر جمله‌ی بالا شکلِ دیگری داشت، دست‌کم «ماهیانه» و «در هر ماه» برداشته شوند
    $html = preg_replace('/(قسط' . $t . ')مساوی' . $t . 'ماهیانه' . $t . '/u', '$1', $html) ?? $html;
    $html = preg_replace('/' . $t . 'در هر ماه(?=' . $t . 'به همان حساب)/u', '', $html) ?? $html;
    $html = preg_replace('/پرداخت' . $t . 'اقساط' . $t . 'از' . $t . 'تاریخ/u', 'پرداخت این قسط در تاریخ', $html) ?? $html;
    $html = preg_replace('/به نحو' . $t . 'اقساط' . $t . 'به شرح ذیل/u', 'به شرح ذیل', $html) ?? $html;
    return $html;
}

/** صدور: منجمدکردنِ متن، مقادیر و نسخه‌ی پیش‌فاکتور/شرحِ خدمات */
function ctr_issue(PDO $pdo, array $contract, int $userId): array
{
    $doc = ctr_document($pdo, $contract, false);
    if ($doc['missing']) {
        return ['ok' => false, 'message' => 'پیش از صدور این موارد را تکمیل کنید: ' . implode('، ', $doc['missing'])];
    }
    $pdo->beginTransaction();
    try {
        $tErr = ctr_apply_transfers($pdo, $contract, $userId);
        if ($tErr) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => implode(' ', $tErr)];
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('ctr_apply_transfers: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'انتقالِ مبلغ از قرارداد قبلی ناموفق بود؛ صدور انجام نشد.'];
    }
    if (function_exists('quote_snapshot_descriptions')) {
        quote_snapshot_descriptions($pdo, (int) $contract['quote_id'], true);
    }
    $invoice = $doc['invoice'] ?? null;
    $snap = ctr_quote_snapshot($pdo, (int) $contract['quote_id'], $invoice);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE contracts SET status = 'issued', rendered_html = ?, final_fields_json = ?, quote_snapshot_json = ?, issued_by = ?, issued_at = ?,
            approved_by = COALESCE(approved_by, ?), approved_at = COALESCE(approved_at, ?), order_id = COALESCE(?, order_id), updated_at = ? WHERE id = ?")
        ->execute([$doc['html'], json_encode($doc['fields'], JSON_UNESCAPED_UNICODE), json_encode($snap, JSON_UNESCAPED_UNICODE),
            $userId, $now, $userId, $now, $invoice ? (int) $invoice['id'] : null, $now, (int) $contract['id']]);
    $pdo->commit();
    return ['ok' => true, 'message' => $invoice
        ? 'قرارداد بر اساسِ فاکتور شماره ' . $invoice['order_number'] . ' صادر شد و متنِ آن منجمد شد.'
        : 'قرارداد (بر اساسِ پیش‌فاکتور — هنوز فاکتوری صادر نشده) صادر شد و متنِ آن منجمد شد.'];
}

/** خروجیِ یک سندِ قرارداد (قرارداد / پیش‌فاکتور / شرح خدمات) — مشترک بین چاپِ داخلی و لینکِ مشتری */
function ctr_output_document(PDO $pdo, array $contract, string $doc, array $o): void
{
    if (!in_array($doc, ['contract', 'quote', 'services'], true)) {
        $doc = 'contract';
    }
    if ($doc === 'contract') {
        $d = ctr_document($pdo, $contract, empty($o['public']));
        $f = $d['fields'];
        ctr_doc_render([
            'body_html'       => $d['html'],
            // شماره‌ی بالای سربرگ همیشه همان شماره‌ی ثبت‌شده‌ی قرارداد است (نه مقدارِ ذخیره‌شده در متن)
            'number'          => to_persian_digits((string) $contract['contract_number']),
            'number_raw'      => (string) $contract['contract_number'],
            'date_jalali'     => $f['تاریخ_قرارداد'] ?? to_jalali((string) $contract['contract_date']),
            'attachment_text' => (string) ($contract['attachment_text'] ?: 'دارد'),
            'back_url'        => $o['back_url'] ?? '',
            'public'          => !empty($o['public']),
            'draft'           => $contract['status'] !== 'issued',
            'base'            => '',
        ]);
        return;
    }
    $data = ctr_attachment_data($pdo, $contract);
    $quote = $data['quote'];
    $items = $data['items'];
    $invoice = !empty($data['order']) && is_array($data['order']) ? $data['order'] : null;
    quote_doc_render(get_invoice_settings($pdo), $quote, $items, [
        // بعد از صدورِ فاکتور، پیوستِ مالیِ قرارداد «فاکتور فروش» است و شرحِ خدمات هم پیوستِ همان فاکتور
        'mode'        => $doc === 'services' ? 'services' : ($invoice ? 'invoice' : 'quote'),
        'order'       => $invoice,
        'issuer_name' => (string) ($quote['issuer_name'] ?? ''),
        'back_url'    => $o['back_url'] ?? '',
        'back_label'  => 'برگشت به قرارداد',
        'public'      => !empty($o['public']),
        'asset_base'  => '',
    ]);
}

/**
 * شماره‌گذاریِ حرفیِ بندها (الف، ب، ج، …) همیشه از «الف» و به‌ترتیب.
 * وقتی بندی به‌خاطرِ شرط حذف شود (مثلاً مبلغِ منتقل‌شده ندارد)، بندهای بعدی
 * خودکار دوباره حرف‌گذاری می‌شوند. شمارش در ابتدای هر ماده از نو شروع می‌شود.
 */
function ctr_fix_lettering(string $html): string
{
    $letters = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح', 'ط', 'ی', 'ک', 'ل', 'م', 'ن'];
    $alt = 'الف|ب|ج|د|ه|و|ز|ح|ط|ی|ک|ل|م|ن';
    $i = 0;
    return preg_replace_callback(
        '/(<h[13][^>]*>)|((?:<p>|<br>)\s*(?:<b>)?)(' . $alt . ')(\s*[)\-–])/u',
        static function ($m) use (&$i, $letters) {
            if (!empty($m[1])) { // عنوانِ ماده ← شمارش از نو
                $i = 0;
                return $m[1];
            }
            $l = $letters[$i] ?? $m[3];
            $i++;
            return $m[2] . $l . $m[4];
        },
        $html
    ) ?? $html;
}

/* =========================================================================
   انتقالِ مبلغ از قرارداد/سفارشِ قبلی (بندِ «الف» ماده ۴)
   مشتری قبلاً خدماتی خریده و مبلغی پرداخته؛ آن سفارش (و قراردادش) لغو می‌شود
   و مبلغِ پرداخت‌شده به حسابِ قراردادِ جدید منتقل می‌شود.
   ========================================================================= */

/** انتقال‌های انتخاب‌شده در یک قرارداد */
function ctr_transfers(array $manual): array
{
    $out = [];
    foreach ((array) ($manual['transfers'] ?? []) as $t) {
        if (!empty($t['order_id']) && (int) ($t['amount'] ?? 0) > 0) {
            $out[] = ['order_id' => (int) $t['order_id'], 'amount' => (int) $t['amount'], 'label' => (string) ($t['label'] ?? '')];
        }
    }
    return $out;
}

/** جمعِ مبلغِ منتقل‌شده (سفارش‌های قبلیِ داخلِ سیستم + مبلغِ دستی برای خریدهای خارج از سیستم) */
function ctr_transfer_total(array $manual): int
{
    $sum = 0;
    foreach (ctr_transfers($manual) as $t) {
        $sum += $t['amount'];
    }
    $sum += (int) preg_replace('/\D/', '', normalize_digits((string) ($manual['مبلغ_تبدیل'] ?? '')));
    return $sum;
}

/**
 * سفارش‌های قبلیِ همین مشتری که مبلغی پرداخت شده و می‌توانند لغو و منتقل شوند
 * @return array<int, array{order_id:int, order_number:string, quote_number:string, contract_id:?int, contract_number:?string, total:int, paid:int, date:string, label:string}>
 */
function ctr_transfer_sources(PDO $pdo, array $contract): array
{
    if (!function_exists('orders_ready') || !orders_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare("SELECT o.*, q.quote_number FROM sales_orders o LEFT JOIN quotes q ON q.id = o.quote_id
        WHERE o.customer_id = ? AND o.status IN ('pending', 'approved') AND o.quote_id <> ? ORDER BY o.id DESC");
    $st->execute([(int) $contract['customer_id'], (int) $contract['quote_id']]);
    $out = [];
    $cst = $pdo->prepare("SELECT id, contract_number FROM contracts WHERE quote_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
        $paid = 0;
        if (function_exists('finance_schema_ready') && finance_schema_ready($pdo)) {
            $f = fin_compute($o, fin_payments($pdo, (int) $o['id']), fin_installments($pdo, (int) $o['id']));
            $paid = (int) $f['paid'];
        } else {
            $paid = (int) ($o['confirmed_amount'] ?? $o['paid_amount']);
        }
        if ($paid <= 0) {
            continue;
        }
        $cst->execute([(int) $o['quote_id']]);
        $c = $cst->fetch(PDO::FETCH_ASSOC) ?: null;
        $label = $c ? 'قرارداد ' . $c['contract_number'] : 'سفارش ' . $o['order_number'];
        $out[] = [
            'order_id' => (int) $o['id'], 'order_number' => (string) $o['order_number'], 'quote_number' => (string) ($o['quote_number'] ?? ''),
            'contract_id' => $c ? (int) $c['id'] : null, 'contract_number' => $c ? (string) $c['contract_number'] : null,
            'total' => (int) $o['total_amount'], 'paid' => $paid, 'date' => substr((string) ($o['decided_at'] ?? $o['created_at']), 0, 10),
            'label' => $label, 'status' => (string) $o['status'],
        ];
    }
    return $out;
}

/**
 * اجرای انتقال‌ها در لحظه‌ی صدور:
 *  - سفارشِ قبلی «لغو شده» می‌شود (با ثبت در تاریخچه‌ی سفارش) و قراردادِ آن باطل و لینک‌هایش غیرفعال می‌شود.
 *  - مبلغ به‌صورتِ پرداختِ تأییدشده‌ی «انتقال از قراردادِ قبلی» روی سفارشِ جدید ثبت می‌شود تا مانده‌ی بدهی درست باشد.
 * @return string[] خطاها (خالی = موفق)
 */
function ctr_apply_transfers(PDO $pdo, array $contract, int $userId): array
{
    $manual = json_decode((string) ($contract['fields_json'] ?? '{}'), true) ?: [];
    $transfers = ctr_transfers($manual);
    if (!$transfers || !empty($manual['transfers_applied'])) {
        // انتقال‌ها در صدورِ قبلی انجام شده‌اند (قراردادِ بازگشایی‌شده برای ویرایش) — دوباره اعمال نمی‌شوند
        return [];
    }
    $valid = [];
    foreach (ctr_transfer_sources($pdo, $contract) as $s) {
        $valid[$s['order_id']] = $s;
    }
    foreach ($transfers as $t) {
        $s = $valid[$t['order_id']] ?? null;
        if (!$s) {
            return ['سفارشِ «' . $t['label'] . '» دیگر قابلِ انتقال نیست (لغو شده یا پرداختی ندارد). انتقال را در فرمِ قرارداد اصلاح کنید.'];
        }
        if ($t['amount'] > $s['paid']) {
            return ['مبلغِ انتقال از «' . $t['label'] . '» بیشتر از مبلغِ پرداخت‌شده‌ی آن است.'];
        }
    }
    $now = date('Y-m-d H:i:s');
    $newNumber = (string) $contract['contract_number'];
    foreach ($transfers as $t) {
        $s = $valid[$t['order_id']];
        $note = 'لغو و انتقالِ ' . number_format($t['amount']) . ' تومان به قرارداد ' . $newNumber;
        $pdo->prepare("UPDATE sales_orders SET status = 'cancelled' WHERE id = ?")->execute([$s['order_id']]);
        if (function_exists('orders_add_history')) {
            orders_add_history($pdo, $s['order_id'], $userId, 'cancelled', $s['status'], 'cancelled', $note);
        }
        if ($s['contract_id']) {
            $pdo->prepare("UPDATE contracts SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$userId, $now, $now, $s['contract_id']]);
            $pdo->prepare('UPDATE contract_links SET revoked = 1 WHERE contract_id = ?')->execute([$s['contract_id']]);
        }
        if (!empty($contract['order_id']) || ($order = orders_active_for_quote($pdo, (int) $contract['quote_id']))) {
            $orderId = (int) ($contract['order_id'] ?: $order['id']);
            $pdo->prepare('INSERT INTO sales_order_payments (order_id, customer_id, kind, amount, paid_at, method, ref, note, status, recorded_by, decided_by, decided_at, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$orderId, (int) $contract['customer_id'], 'transfer', $t['amount'], date('Y-m-d'), 'transfer', $s['order_number'],
                    'انتقال از ' . $t['label'] . ' (لغوشده)', 'confirmed', $userId, $userId, $now, $now]);
            if (function_exists('orders_add_history')) {
                orders_add_history($pdo, $orderId, $userId, 'note', null, null, 'انتقالِ ' . number_format($t['amount']) . ' تومان از ' . $t['label'] . ' (لغوشده)');
            }
        }
    }
    return [];
}

/* =========================================================================
   قراردادِ فعالِ یک پیش‌فاکتور، حذف، بازگشایی برای ویرایش
   ========================================================================= */

/** آخرین قراردادِ باطل‌نشده‌ی یک پیش‌فاکتور (برای صفحه‌ی بررسیِ مالیِ سفارش) */
function ctr_for_quote(PDO $pdo, int $quoteId): ?array
{
    if ($quoteId <= 0 || !ctr_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare("SELECT id FROM contracts WHERE quote_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
    $st->execute([$quoteId]);
    $id = (int) $st->fetchColumn();
    return $id ? ctr_get($pdo, $id) : null;
}

/** حذفِ قرارداد — از «نقش‌ها و دسترسی‌ها» (مجوزِ contracts_delete) کنترل می‌شود */
function ctr_can_delete(array $user): bool
{
    return (function_exists('is_super_admin') && is_super_admin($user)) || user_can('contracts_delete', $user);
}

/**
 * حذفِ کاملِ یک قرارداد + لینک‌ها و سابقه‌ی ارسالش.
 * یک نسخه‌ی خلاصه از قرارداد در contract_deletions نگه داشته می‌شود تا ردِ حذف بماند.
 * نکته: اگر این قرارداد با صدور، سفارش‌های قبلی را لغو و مبلغشان را منتقل کرده باشد، حذفِ قرارداد
 * آن انتقال را برنمی‌گرداند (پرداختِ «انتقال» روی سفارش می‌ماند).
 */
function ctr_delete(PDO $pdo, array $contract, int $userId): array
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `contract_deletions` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `contract_number` VARCHAR(40) NOT NULL,
          `customer_id` INT UNSIGNED NOT NULL,
          `quote_id` INT UNSIGNED DEFAULT NULL,
          `status` VARCHAR(20) DEFAULT NULL,
          `snapshot_json` MEDIUMTEXT,
          `deleted_by` INT UNSIGNED DEFAULT NULL,
          `deleted_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_ctr_del_customer` (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ctr_delete schema: ' . $e->getMessage());
    }
    $id = (int) $contract['id'];
    $snap = $contract;
    unset($snap['rendered_html'], $snap['template_body'], $snap['quote_snapshot_json']);
    $pdo->beginTransaction();
    try {
        try {
            $pdo->prepare('INSERT INTO contract_deletions (contract_id, contract_number, customer_id, quote_id, status, snapshot_json, deleted_by, deleted_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id, (string) $contract['contract_number'], (int) $contract['customer_id'], (int) $contract['quote_id'],
                    (string) $contract['status'], json_encode($snap, JSON_UNESCAPED_UNICODE), $userId, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('ctr_delete log: ' . $e->getMessage());
        }
        $pdo->prepare('DELETE FROM contract_links WHERE contract_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM contract_sends WHERE contract_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('ctr_delete: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'حذفِ قرارداد ناموفق بود.'];
    }
    if (!empty($contract['order_id']) && function_exists('orders_add_history')) {
        try {
            orders_add_history($pdo, (int) $contract['order_id'], $userId, 'note', null, null, 'قرارداد ' . $contract['contract_number'] . ' حذف شد.');
        } catch (Throwable $e) {
        }
    }
    return ['ok' => true, 'message' => 'قرارداد ' . $contract['contract_number'] . ' حذف شد.'];
}

/**
 * بازگشاییِ قراردادِ صادرشده برای اصلاح (واحد مالی): متنِ منجمد کنار می‌رود و قرارداد به وضعیتِ
 * «تأییدشده» برمی‌گردد؛ بعد از اصلاح دوباره «صدور» زده می‌شود. انتقال‌هایی که در صدورِ قبلی انجام
 * شده‌اند علامت می‌خورند تا دوباره اعمال نشوند. تا صدورِ دوباره، لینک‌های مشتری سند را نشان نمی‌دهند.
 */
function ctr_reopen(PDO $pdo, array $contract, int $userId): array
{
    if (($contract['status'] ?? '') !== 'issued') {
        return ['ok' => false, 'message' => 'فقط قراردادِ صادرشده بازگشایی می‌شود.'];
    }
    $manual = json_decode((string) ($contract['fields_json'] ?? '{}'), true) ?: [];
    if (ctr_transfers($manual)) {
        $manual['transfers_applied'] = 1;
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE contracts SET status = 'approved', rendered_html = NULL, final_fields_json = NULL, issued_by = NULL, issued_at = NULL,
            fields_json = ?, updated_at = ? WHERE id = ?")
        ->execute([json_encode($manual, JSON_UNESCAPED_UNICODE), $now, (int) $contract['id']]);
    if (!empty($contract['order_id']) && function_exists('orders_add_history')) {
        try {
            orders_add_history($pdo, (int) $contract['order_id'], $userId, 'note', null, null, 'قرارداد ' . $contract['contract_number'] . ' برای اصلاح بازگشایی شد.');
        } catch (Throwable $e) {
        }
    }
    return ['ok' => true, 'message' => 'قرارداد برای ویرایش باز شد. بعد از اصلاح، دوباره «صدور قرارداد» را بزنید.'];
}

/**
 * یک‌بار: بندهای «تهاتر» و «چکی» به قالبِ فعالِ قرارداد اضافه می‌شود (بعد از بندِ اقساط) و به‌عنوانِ
 * نسخه‌ی جدید ذخیره می‌شود. اگر قالب قبلاً این بندها را داشته باشد، دست نمی‌خورد.
 */
function ctr_template_add_settle_clauses(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.ctr_tpl_settle_clauses_v1';
    if (is_file($flag) || !ctr_ready($pdo)) return;
    try {
        $tpl = ctr_template_active($pdo);
        $body = (string) ($tpl['body'] ?? '');
        if ($body === '' || mb_strpos($body, 'تعداد_چک') !== false) {
            @file_put_contents($flag, (string) time());
            return;
        }
        $add = "\n\n[[اگر مبلغ_تهاتر]]\nب) مبلغ «مبلغ_تهاتر» تومان به حروف «مبلغ_تهاتر_به_حروف» تومان از مبلغ قرارداد از طریق تهاتر با «شرح_تهاتر» پرداخت گردید.\n[[/اگر]]"
             . "\n\n[[اگر تعداد_چک]]\nج) الباقی مبلغ قرارداد به مبلغ «مبلغ_چکها» تومان به حروف «مبلغ_چکها_به_حروف» تومان طی «تعداد_چک» (به حروف «تعداد_چک_به_حروف») فقره چک به شرح ذیل به شرکت تحویل گردید و تاجر متعهد است وجه چک‌ها را در سررسیدهای مقرر در حساب تأمین نماید: «فهرست_چکها».\n[[/اگر]]";
        $pos = mb_strpos($body, '[[اگر تعداد_قسط]]');
        if ($pos !== false) {
            $end = mb_strpos($body, '[[/اگر]]', $pos);
            $end = $end === false ? mb_strlen($body) : $end + mb_strlen('[[/اگر]]');
            $body = mb_substr($body, 0, $end) . $add . mb_substr($body, $end);
        } else {
            $p5 = mb_strpos($body, '## ماده ۵');
            $body = $p5 !== false ? mb_substr($body, 0, $p5) . ltrim($add) . "\n\n" . mb_substr($body, $p5) : $body . $add;
        }
        ctr_template_save($pdo, $body, (string) ($tpl['number_prefix'] ?? ''), (string) ($tpl['attachment_text'] ?? 'دارد'),
            'افزودنِ خودکارِ بندهای «تهاتر» و «تسویه‌ی چکی» به ماده ۴', 0);
        @file_put_contents($flag, (string) time());
    } catch (Throwable $e) {
        error_log('ctr_template_add_settle_clauses: ' . $e->getMessage());
    }
}

/* =========================================================================
   ارسالِ اسنادِ قرارداد از طریقِ «تیکت» در سامانه‌ی آراد برندینگ
   (کنارِ ارسال با پیام‌رسان؛ دپارتمانِ پیش‌فرض: قرارداد)
   ========================================================================= */

function ctr_ticket_default_department(): string
{
    // از «تنظیمات تیکت» ← بخشِ «تیکتِ اسنادِ قرارداد»
    try {
        if (function_exists('abt_settings')) {
            $d = trim((string) (abt_settings(db())['ctr_department'] ?? ''));
            if ($d !== '') return $d;
        }
    } catch (Throwable $e) {}
    return 'قرارداد';
}

/** مدتِ اعتبارِ لینکِ اسناد برای ارسال (روز) — از تنظیمات تیکت */
function ctr_link_days(): int
{
    try {
        if (function_exists('abt_settings')) {
            $d = (int) (abt_settings(db())['ctr_link_days'] ?? 30);
            if ($d >= 1 && $d <= 365) return $d;
        }
    } catch (Throwable $e) {}
    return 30;
}

/**
 * موضوع و متنِ تیکتِ اسنادِ قرارداد از قالبِ «تنظیمات تیکت».
 * متغیرها: «عنوان» «نام_مشتری» «موبایل» «شماره_قرارداد» «لینک_اسناد» «فهرست_اسناد» «تاریخ_اعتبار»
 * @return array{subject:string, body:string}
 */
function ctr_ticket_texts(array $contract, array $fields, array $links): array
{
    $s = function_exists('abt_settings') ? abt_settings(db()) : [];
    $linkTxt = '';
    foreach ($links as $l) $linkTxt .= '📄 ' . $l['label'] . ":\n" . $l['url'] . "\n\n";
    $vars = [
        'عنوان' => (string) ($fields['عنوان'] ?? ''),
        'نام_مشتری' => (string) ($contract['customer_name'] ?? ''),
        'موبایل' => (string) ($contract['customer_mobile'] ?? ''),
        'شماره_قرارداد' => to_persian_digits((string) ($fields['شماره_قرارداد'] ?? $contract['contract_number'])),
        'لینک_اسناد' => rtrim($linkTxt),
        'فهرست_اسناد' => implode('، ', array_map(static fn($l) => $l['label'], $links)),
        'تاریخ_اعتبار' => (string) ($links[0]['expires'] ?? ''),
    ];
    $render = static function (string $tpl) use ($vars): string {
        foreach ($vars as $k => $v) $tpl = str_replace('«' . $k . '»', $v, $tpl);
        return trim(preg_replace('/[ \t]+\n/u', "\n", $tpl));
    };
    $subTpl = trim((string) ($s['ctr_subject_tpl'] ?? '')) ?: 'اسناد قرارداد «شماره_قرارداد» — آراد برندینگ';
    $bodyTpl = trim((string) ($s['ctr_body_tpl'] ?? ''));
    if ($bodyTpl === '' && function_exists('abt_default_contract_body')) $bodyTpl = abt_default_contract_body();
    return ['subject' => mb_substr(preg_replace('/\s+/u', ' ', $render($subTpl)), 0, 250), 'body' => $render($bodyTpl)];
}

/** آیا ارسالِ تیکت در این نصب در دسترس است؟ */
function ctr_ticket_available(PDO $pdo): bool
{
    return function_exists('abt_ready') && abt_ready($pdo);
}

/**
 * ساخت و ارسالِ تیکتِ اسنادِ قرارداد برای مشتری.
 * @param array $links خروجیِ ctr_link_for برای هر سند: [['label'=>…, 'url'=>…], …]
 * @return array{ok:bool, message:string, ticket_id:int, status:string}
 */
function ctr_send_ticket(PDO $pdo, array $contract, array $docs, array $links, string $message, string $department, int $userId): array
{
    if (!ctr_ticket_available($pdo)) {
        return ['ok' => false, 'message' => 'ماژولِ تیکتِ آراد برندینگ آماده نیست.', 'ticket_id' => 0, 'status' => 'failed'];
    }
    $department = mb_substr(trim($department) !== '' ? trim($department) : ctr_ticket_default_department(), 0, 100);
    // سفارشِ مبنا (برای شماره‌ی مرجع و اطلاعاتِ مشتری در تیکت)
    $order = null;
    if (function_exists('orders_get') && !empty($contract['order_id'])) {
        $order = orders_get($pdo, (int) $contract['order_id']);
    }
    if (!$order && function_exists('orders_active_for_quote')) {
        $o = orders_active_for_quote($pdo, (int) $contract['quote_id']);
        if ($o && function_exists('orders_get')) $order = orders_get($pdo, (int) $o['id']);
    }
    $orderLike = $order ?: [
        'id' => 0, 'customer_id' => (int) $contract['customer_id'], 'customer_name' => (string) ($contract['customer_name'] ?? ''),
        'customer_mobile' => (string) ($contract['customer_mobile'] ?? ''), 'order_number' => (string) $contract['contract_number'],
    ];
    $number = to_persian_digits((string) $contract['contract_number']);
    $subject = mb_substr('اسناد قرارداد ' . $number, 0, 250);
    try {
        $tx = ctr_ticket_texts($contract, ctr_document($pdo, $contract, false)['fields'] ?? [], $links);
        if ($tx['subject'] !== '') $subject = $tx['subject'];
        if ($tx['body'] !== '') $message = $tx['body'];
    } catch (Throwable $e) {
        error_log('ctr_ticket_texts: ' . $e->getMessage());
    }
    // پیوست‌ها: اگر PDFِ سند ساخته شده، لینکِ مستقیمِ PDF؛ وگرنه لینکِ صفحه‌ی سند
    // فقط فایل‌های واقعیِ PDF پیوست می‌شوند (صفحه‌ی وبِ سند پیوستِ معتبر نیست؛ لینکش در متنِ تیکت هست)
    $__att = [];
    foreach ($links as $l) if (!empty($l['pdf_url'])) $__att[] = ['title' => (string) $l['label'] . '.pdf', 'url' => (string) $l['pdf_url']];
    $attachments = $__att ? json_encode($__att, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $now = date('Y-m-d H:i:s');
    $serviceTitle = mb_substr('قرارداد ' . $number, 0, 250);
    // ─── جلوگیری از ارسالِ تکراری: اسنادی که قبلاً با تیکت برای مشتری رفته‌اند دوباره فرستاده نمی‌شوند ───
    // قفل برای همین قرارداد: دو درخواستِ هم‌زمان (دوبار کلیک) پشتِ سرِ هم اجرا می‌شوند و دومی ارسالِ اولی را می‌بیند.
    $__lock = 'ctr_ticket_' . (int) $contract['id'];
    $__locked = false;
    try { $__locked = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($__lock) . ', 25)')->fetchColumn() === 1; } catch (Throwable $e) {}
    try {
        $dup = ctr_ticket_already_sent($pdo, $contract, $docs, $serviceTitle);
        if ($dup) {
            return ['ok' => false, 'status' => 'duplicate', 'ticket_id' => (int) $dup['id'],
                'message' => 'اسنادِ این قرارداد قبلاً با تیکت' . (!empty($dup['external_id']) ? ' ' . to_persian_digits((string) $dup['external_id']) : '')
                    . ' برای مشتری ارسال شده؛ تیکتِ تکراری فرستاده نشد. اگر واقعاً لازم است (مثلاً تیکت در سایتِ آراد برندینگ حذف شده) در صفحه‌ی سفارش روی همان تیکت «ارسال مجدد» را بزنید.'];
        }
        $prevSent = [];
        try {
            $pq = $pdo->prepare("SELECT * FROM aradbranding_tickets WHERE item_id IS NULL AND service_title = ? AND customer_id = ? AND status IN ('sent','manual') ORDER BY id");
            $pq->execute([$serviceTitle, (int) $contract['customer_id']]);
            $prevSent = $pq->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
        $res = ctr_send_ticket_locked($pdo, $contract, $docs, $links, $message, $department, $userId, $orderLike, $subject, $attachments, $serviceTitle, $now);
        // هر قرارداد فقط یک تیکت در آراد برندینگ: بعد از ارسالِ موفقِ تیکتِ تازه (با همه‌ی اسناد)، تیکت‌های قبلیِ همین قرارداد حذف می‌شوند
        if ($res['ok'] && $prevSent) {
            $gone = 0;
            foreach ($prevSent as $old) {
                if ((int) $old['id'] === (int) $res['ticket_id']) continue;
                try {
                    $r = abt_delete_remote($pdo, $orderLike, $old, $userId, 'جایگزین شد با تیکتِ تازه‌ی همین قرارداد');
                    if ($r['ok']) {
                        $pdo->prepare('DELETE FROM aradbranding_tickets WHERE id = ?')->execute([(int) $old['id']]);
                        $gone++;
                    }
                } catch (Throwable $e) {
                    error_log('ctr replace old ticket: ' . $e->getMessage());
                }
            }
            if ($gone) $res['message'] .= ' تیکتِ قبلیِ این قرارداد (' . to_persian_digits((string) $gone) . ' تیکت) از آراد برندینگ حذف شد تا فقط یک تیکت بماند.';
        }
        return $res;
    } finally {
        if ($__locked) { try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($__lock) . ')'); } catch (Throwable $e) {} }
    }
}

/**
 * تیکتِ ارسال‌شده‌ای که همه‌ی اسنادِ خواسته‌شده را قبلاً برای مشتری برده؟ (null = نه)
 * اسنادِ ارسال‌شده از سابقه‌ی «ارسالِ اسناد» (contract_sends با کانالِ تیکت) خوانده می‌شود؛ اگر سابقه نباشد، هر تیکتِ ارسال‌شده‌ی همین قرارداد کافی است.
 */
function ctr_ticket_already_sent(PDO $pdo, array $contract, array $docs, string $serviceTitle): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, external_id FROM aradbranding_tickets WHERE item_id IS NULL AND service_title = ? AND customer_id = ?
            AND status IN ('sent', 'manual', 'bundled') ORDER BY id DESC LIMIT 1");
        $st->execute([$serviceTitle, (int) $contract['customer_id']]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $sent = [];
        $hasLog = false;
        $q = $pdo->prepare("SELECT doc_types FROM contract_sends WHERE contract_id = ? AND channel = 'ticket' AND status = 'sent'");
        $q->execute([(int) $contract['id']]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) ?: [] as $dt) {
            $hasLog = true;
            foreach (explode(',', (string) $dt) as $d) if (trim($d) !== '') $sent[trim($d)] = true;
        }
        if (!$hasLog) return $t;
        foreach ($docs as $d) if (!isset($sent[(string) $d])) return null; // سندِ تازه‌ای هست ← ارسال مجاز
        return $t;
    } catch (Throwable $e) {
        error_log('ctr_ticket_already_sent: ' . $e->getMessage());
        return null;
    }
}

function ctr_send_ticket_locked(PDO $pdo, array $contract, array $docs, array $links, string $message, string $department, int $userId,
                                array $orderLike, string $subject, ?string $attachments, string $serviceTitle, string $now): array
{
    try {
        // تیکتِ ارسال‌نشده‌ی قبلیِ همین قرارداد دوباره استفاده می‌شود (تیکتِ تکراری ساخته نمی‌شود)
        $ex = $pdo->prepare("SELECT id FROM aradbranding_tickets WHERE item_id IS NULL AND order_id = ? AND service_title = ? AND status IN ('queued','failed','skipped') ORDER BY id DESC");
        $ex->execute([(int) $orderLike['id'], $serviceTitle]);
        $exIds = array_map('intval', $ex->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($exIds) {
            $tid = array_shift($exIds);
            if ($exIds) $pdo->exec('DELETE FROM aradbranding_tickets WHERE id IN (' . implode(',', $exIds) . ')');
            $pdo->prepare("UPDATE aradbranding_tickets SET department = ?, subject = ?, message = ?, status = 'queued', last_error = NULL, updated_at = ? WHERE id = ?")
                ->execute([$department, $subject, $message, $now, $tid]);
        } else {
            $pdo->prepare('INSERT INTO aradbranding_tickets (order_id, item_id, service_title, department, customer_id, subject, message, status, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([(int) $orderLike['id'], null, $serviceTitle, $department, (int) $contract['customer_id'],
                    $subject, $message, 'queued', $userId, $now, $now]);
            $tid = (int) $pdo->lastInsertId();
        }
        try { $pdo->prepare('UPDATE aradbranding_tickets SET attachments_json = ? WHERE id = ?')->execute([$attachments, $tid]); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        error_log('ctr_send_ticket insert: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'ساختِ تیکت ناموفق بود.', 'ticket_id' => 0, 'status' => 'failed'];
    }
    $ticket = abt_get_ticket($pdo, $tid);
    if (!$ticket) {
        return ['ok' => false, 'message' => 'تیکت ساخته شد ولی خوانده نشد.', 'ticket_id' => $tid, 'status' => 'failed'];
    }
    $r = abt_send($pdo, $orderLike, $ticket, $userId);
    $after = abt_get_ticket($pdo, $tid);
    return ['ok' => (bool) $r['ok'], 'message' => $r['message'], 'ticket_id' => $tid, 'status' => (string) ($after['status'] ?? 'queued'), 'body' => $message];
}
