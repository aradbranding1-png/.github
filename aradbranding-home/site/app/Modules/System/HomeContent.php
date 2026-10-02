<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Settings\Settings;

/**
 * Editable content of the public home page, stored as one JSON value in settings ("home.content").
 * defaults() is the full shape; whatever the admin saved is merged over it, so new fields added in later
 * versions always get a sane default. sanitize() is the only way form input reaches storage.
 */
final class HomeContent
{
    public const KEY = 'home.content';

    public const ICONS = [
        'm-chart' => 'نمودار', 'm-globe' => 'کره زمین', 'm-people' => 'افراد', 'm-letter' => 'نامه', 'm-page' => 'صفحه',
        'm-star' => 'ستاره', 'm-ship' => 'کشتی', 'm-plane' => 'هواپیما', 'm-anchor' => 'لنگر', 'm-box' => 'بسته',
    ];
    public const ARTS = [
        'prod-saffron' => 'زعفران', 'prod-dates' => 'خرما', 'prod-pistachio' => 'پسته', 'prod-petro' => 'پتروشیمی', 'prod-carpet' => 'فرش',
    ];
    public const METRICS = ['users' => 'اعضا', 'countries' => 'کشورها', 'pages' => 'صفحه‌های تجاری', 'proposals' => 'پیشنهادهای فعال', 'connections' => 'ارتباط‌های تجاری'];
    public const SECTIONS = [
        'rail' => 'ستون آمار کنار کره', 'cards' => 'کارت‌های کشور روی کره', 'stats' => 'نوار آمار', 'finder' => 'جستجوی بازار',
        'markets' => 'کارت‌های بازار', 'opps' => 'فرصت‌های تجاری', 'modules' => 'بخش‌های سامانه', 'banner' => 'بنر تجارت بین‌المللی',
        'how' => 'چطور کار می‌کند', 'features' => 'امکانات', 'stars' => 'Stars', 'countries' => 'کشورها', 'faq' => 'پرسش‌ها', 'final' => 'دعوت پایانی',
    ];
    /** Lists and their maximum number of rows. */
    public const LISTS = [
        'nav' => 10, 'rail' => 6, 'stats_live' => 4, 'stats_static' => 4, 'markets' => 16, 'opps' => 8, 'modules' => 8,
        'steps' => 6, 'features' => 8, 'stars_items' => 6, 'faq' => 12,
    ];
    /** Text fields that may be long. */
    private const LONG = ['lead', 'text', 'a', 'intro', 'subtitle'];

    public function __construct(private Settings $settings)
    {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $saved = $this->settings->get(self::KEY);
        return is_array($saved) ? self::retire(self::merge(self::defaults(), $saved)) : self::defaults();
    }

    /** Old default texts that are no longer true; a saved copy of one is shown with its current wording. */
    private const RETIRED = [
        'شماره تماس، ایمیل و راه‌های ارتباطی فقط در بخش کامل صفحه و برای تجار واردشده نمایش داده می‌شود. در معرفی عمومی و پیشنهادها اجازه درج اطلاعات تماس داده نمی‌شود.'
            => 'بله. شماره تماس، ایمیل و شبکه‌های اجتماعی شما در هیچ بخشی از سامانه به تجار دیگر نمایش داده نمی‌شود و در صفحه تجاری، پروفایل و پیشنهادها هم درج آن‌ها مجاز نیست. ارتباط از داخل سامانه انجام می‌شود و اگر خودتان بخواهید، می‌توانید اطلاعات تماس را در نامه خصوصی برای تاجر مورد نظر بفرستید.',
    ];

    private static function retire(array $content): array
    {
        array_walk_recursive($content, static function (&$v): void {
            if (is_string($v) && isset(self::RETIRED[$v])) {
                $v = self::RETIRED[$v];
            }
        });
        return $content;
    }

    public function save(array $content, ?int $actorId): void
    {
        $this->settings->set(self::KEY, $content, $actorId);
    }

    public function reset(?int $actorId): void
    {
        $this->settings->set(self::KEY, null, $actorId);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'show' => array_fill_keys(array_keys(self::SECTIONS), true),
            'nav' => [
                ['label' => 'صفحه اصلی', 'href' => '/'],
                ['label' => 'بازارهای هدف', 'href' => '/discover'],
                ['label' => 'فرصت‌های تجاری', 'href' => '/proposals'],
                ['label' => 'شبکه تجاری', 'href' => '/connections'],
                ['label' => 'نامه‌ها', 'href' => '/letters'],
                ['label' => 'چطور کار می‌کند', 'href' => '#how'],
                ['label' => 'Stars', 'href' => '#stars'],
                ['label' => 'پرسش‌ها', 'href' => '#faq'],
            ],
            'header' => ['search_placeholder' => 'جستجوی تاجر، کالا یا فرصت…', 'login_label' => 'ورود', 'register_label' => 'ثبت‌نام'],
            'hero' => [
                'badge' => 'بستر ارتباطات تجارت جهانی',
                'title' => 'تجارت جهانی',
                'subtitle' => 'همین حالا در دسترس شماست',
                'lead' => 'دسترسی به بازارهای جهانی، فرصت‌های تجاری، تأمین‌کنندگان، خریداران و مسیرهای تجارت بین‌المللی در یک سامانه یکپارچه.',
                'cta1_label' => 'شروع تجارت', 'cta1_href' => '/register',
                'cta2_label' => 'مشاهده فرصت‌ها', 'cta2_href' => '/proposals',
                'note' => 'بدون هزینه عضویت · صفحه تجاری چندزبانه · ارتباط مستقیم',
                'hint' => 'بکشید تا بچرخد · دوبار کلیک برای بزرگنمایی · نمایش تصویری مسیرهای تجارت جهانی',
            ],
            'rail' => [
                ['icon' => 'm-globe', 'label' => 'پوشش جهانی', 'value' => '۲۴۳ کشور'],
                ['icon' => 'm-ship', 'label' => 'مسیرهای دریایی', 'value' => '{sea} مسیر'],
                ['icon' => 'm-plane', 'label' => 'مسیرهای هوایی باری', 'value' => '{air} مسیر'],
                ['icon' => 'm-anchor', 'label' => 'بنادر و هاب‌ها', 'value' => '{nodes} گره'],
            ],
            'stats_live' => [
                ['metric' => 'users', 'icon' => 'm-people', 'label' => 'تاجر عضو'],
                ['metric' => 'countries', 'icon' => 'm-globe', 'label' => 'کشور فعال'],
                ['metric' => 'proposals', 'icon' => 'm-box', 'label' => 'فرصت تجاری فعال'],
                ['metric' => 'connections', 'icon' => 'm-chart', 'label' => 'ارتباط تجاری'],
            ],
            'stats_static' => [
                ['icon' => 'm-globe', 'value' => '243', 'label' => 'کشور و منطقه قابل انتخاب'],
                ['icon' => 'm-page', 'value' => '27', 'label' => 'زبان برای صفحه تجاری'],
                ['icon' => 'm-box', 'value' => '6', 'label' => 'نوع فرصت تجاری'],
                ['icon' => 'm-star', 'value' => 'رایگان', 'label' => 'عضویت و ساخت صفحه'],
            ],
            'finder' => [
                'title' => 'بازار بعدی خود را پیدا کنید',
                'text' => 'در میان تجار، صفحه‌های تجاری و فرصت‌های منتشرشده کشورهای مختلف جستجو کنید و بازار هدف محصول خود را بشناسید.',
                'button' => 'جستجوی بازارها',
            ],
            'markets' => [
                ['code' => 'CN', 'name' => 'چین', 'lat' => 33.5, 'lon' => 106.0, 'globe' => true, 'card' => true, 'secondary' => false, 'port' => 'شانگهای', 'currency' => 'یوان (CNY)', 'timezone' => 'UTC+8', 'image' => ''],
                ['code' => 'IN', 'name' => 'هند', 'lat' => 22.0, 'lon' => 78.5, 'globe' => true, 'card' => true, 'secondary' => false, 'port' => 'نهاوا شوا (بمبئی)', 'currency' => 'روپیه (INR)', 'timezone' => 'UTC+5:30', 'image' => ''],
                ['code' => 'AE', 'name' => 'امارات', 'lat' => 24.0, 'lon' => 54.5, 'globe' => true, 'card' => true, 'secondary' => false, 'port' => 'جبل‌علی (دبی)', 'currency' => 'درهم (AED)', 'timezone' => 'UTC+4', 'image' => ''],
                ['code' => 'TR', 'name' => 'ترکیه', 'lat' => 39.0, 'lon' => 35.0, 'globe' => true, 'card' => true, 'secondary' => false, 'port' => 'آمبارلی (استانبول)', 'currency' => 'لیر (TRY)', 'timezone' => 'UTC+3', 'image' => ''],
                ['code' => 'DE', 'name' => 'آلمان', 'lat' => 51.0, 'lon' => 10.3, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'هامبورگ', 'currency' => 'یورو (EUR)', 'timezone' => 'UTC+1', 'image' => ''],
                ['code' => 'RU', 'name' => 'روسیه', 'lat' => 56.0, 'lon' => 40.0, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'نووروسیسک', 'currency' => 'روبل (RUB)', 'timezone' => 'UTC+3', 'image' => ''],
                ['code' => 'IQ', 'name' => 'عراق', 'lat' => 33.0, 'lon' => 43.5, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'ام‌القصر (بصره)', 'currency' => 'دینار (IQD)', 'timezone' => 'UTC+3', 'image' => ''],
                ['code' => 'KZ', 'name' => 'قزاقستان', 'lat' => 48.0, 'lon' => 67.0, 'globe' => false, 'card' => true, 'secondary' => true, 'port' => 'آکتائو (خزر)', 'currency' => 'تنگه (KZT)', 'timezone' => 'UTC+5', 'image' => ''],
                ['code' => 'KE', 'name' => 'کنیا', 'lat' => 0.3, 'lon' => 37.9, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'مومباسا', 'currency' => 'شیلینگ کنیا (KES)', 'timezone' => 'UTC+3', 'image' => ''],
                ['code' => 'NG', 'name' => 'نیجریه', 'lat' => 9.1, 'lon' => 8.7, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'لاگوس (آپاپا)', 'currency' => 'نایرا (NGN)', 'timezone' => 'UTC+1', 'image' => ''],
                ['code' => 'US', 'name' => 'آمریکا', 'lat' => 39.5, 'lon' => -98.0, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'نیویورک', 'currency' => 'دلار (USD)', 'timezone' => 'UTC−5', 'image' => ''],
                ['code' => 'CA', 'name' => 'کانادا', 'lat' => 56.0, 'lon' => -106.0, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'ونکوور', 'currency' => 'دلار کانادا (CAD)', 'timezone' => 'UTC−8', 'image' => ''],
                ['code' => 'BR', 'name' => 'برزیل', 'lat' => -11.0, 'lon' => -50.0, 'globe' => true, 'card' => true, 'secondary' => true, 'port' => 'سانتوس', 'currency' => 'رئال (BRL)', 'timezone' => 'UTC-3', 'image' => ''],
            ],
            'opps_head' => ['title' => 'فرصت‌های تجاری', 'subtitle' => 'جستجوی سریع در پیشنهادهای منتشرشده', 'link_label' => 'مشاهده همه', 'link_href' => '/proposals'],
            'opps' => [
                ['product' => 'زعفران', 'code' => 'ES', 'market' => 'اسپانیا', 'tag' => 'کشاورزی', 'mode' => 'air', 'art' => 'prod-saffron', 'image' => ''],
                ['product' => 'خرما', 'code' => 'RU', 'market' => 'روسیه', 'tag' => 'مواد غذایی', 'mode' => 'sea', 'art' => 'prod-dates', 'image' => ''],
                ['product' => 'پسته', 'code' => 'IN', 'market' => 'هند', 'tag' => 'خشکبار', 'mode' => 'sea', 'art' => 'prod-pistachio', 'image' => ''],
                ['product' => 'محصولات پتروشیمی', 'code' => 'TR', 'market' => 'ترکیه', 'tag' => 'صنعتی', 'mode' => 'sea', 'art' => 'prod-petro', 'image' => ''],
                ['product' => 'فرش دستباف', 'code' => 'DE', 'market' => 'آلمان', 'tag' => 'صنایع دستی', 'mode' => 'air', 'art' => 'prod-carpet', 'image' => ''],
            ],
            'modules' => [
                ['icon' => 'm-chart', 'title' => 'فرصت‌های تجاری', 'text' => 'خرید، فروش، مشارکت و نمایندگی', 'href' => '/proposals'],
                ['icon' => 'm-globe', 'title' => 'بازارهای هدف', 'text' => 'کشف تجار بر اساس کشور', 'href' => '/discover'],
                ['icon' => 'm-people', 'title' => 'شبکه تجاری', 'text' => 'ارتباط‌های ساخته‌شده شما', 'href' => '/connections'],
                ['icon' => 'm-letter', 'title' => 'نامه‌های تجاری', 'text' => 'نامه اختصاصی و عمومی', 'href' => '/letters'],
                ['icon' => 'm-page', 'title' => 'صفحه تجاری', 'text' => 'معرفی چندزبانه کسب‌وکار', 'href' => '/pages'],
                ['icon' => 'm-star', 'title' => 'Stars', 'text' => 'اعتبار داخلی و گردش حساب', 'href' => '/wallet'],
            ],
            'banner' => ['title' => 'مسیر مطمئن تجارت بین‌المللی', 'subtitle' => 'ببینید سامانه چطور کار می‌کند', 'href' => '#how', 'image' => ''],
            'how' => [
                'kicker' => 'مسیر شروع', 'title' => 'چطور کار می‌کند',
                'intro' => 'سامانه توسعه تجارت جایی است که تولیدکننده، بازرگان و ارائه‌دهنده خدمات، خود را به بازارهای دیگر معرفی می‌کند و طرف معامله‌اش را پیدا می‌کند. هر گفتگو یک ارتباط تجاری می‌سازد و هر ارتباط، درِ بازار تازه‌ای را باز می‌کند.',
            ],
            'steps' => [
                ['title' => 'عضو شوید', 'text' => 'در کمتر از یک دقیقه و رایگان.'],
                ['title' => 'صفحه تجاری بسازید', 'text' => 'به هر زبانی که مشتریانتان صحبت می‌کنند.'],
                ['title' => 'فرصت‌ها را کشف کنید', 'text' => 'پیشنهادهای خرید، فروش، مشارکت و سرمایه‌گذاری.'],
                ['title' => 'ارتباط بگیرید', 'text' => 'پیشنهاد و نامه بفرستید؛ هر پاسخ، یک ارتباط تجاری.'],
            ],
            'features_head' => ['kicker' => 'ابزارهای سامانه', 'title' => 'امکانات'],
            'features' => [
                ['icon' => 'm-page', 'title' => 'صفحه تجاری چندزبانه', 'text' => 'یک وب‌سایت کوچک برای کسب‌وکار شما. هر بازدیدکننده صفحه هم‌زبان خودش را می‌بیند و در گوگل هم پیدا می‌شوید.'],
                ['icon' => 'm-chart', 'title' => 'پیشنهادهای تجاری', 'text' => 'فرصت خرید، فروش، مشارکت یا نمایندگی را با تصویر منتشر کنید و در فید تجار کشورهای دیگر دیده شوید.'],
                ['icon' => 'm-letter', 'title' => 'نامه اختصاصی و عمومی', 'text' => 'به یک تاجر نامه بزنید، یا با یک نامه تجار یک کشور، زبان یا حوزه را باخبر کنید. پاسخ‌دادن همیشه رایگان است.'],
                ['icon' => 'm-people', 'title' => 'شبکه ارتباطات', 'text' => 'هر پاسخ، یک ارتباط تجاری ثبت می‌کند. شبکه‌ای که با هر گفتگو بزرگ‌تر می‌شود.'],
            ],
            'stars' => [
                'kicker' => 'اعتبار داخلی', 'title' => 'Stars؛ اعتبار داخلی، شفاف و منصفانه',
                'text' => 'عضویت، ساخت صفحه و پاسخ‌دادن رایگان است. برای {paid}، از Stars استفاده می‌کنید. پیش از هر کسر، دقیقاً می‌بینید چقدر لازم است.',
            ],
            'stars_items' => [
                ['text' => 'هزینه داخلی و بین‌المللی جدا'],
                ['text' => 'بسته‌های خرید با Star هدیه'],
                ['text' => 'گردش حساب کامل و قابل پیگیری'],
            ],
            'countries' => ['kicker' => 'شبکه بین‌المللی', 'title' => 'تجار از کشورهای مختلف', 'codes' => 'IR,AE,TR,IQ,AF,RU,CN,IN,OM,QA,AZ,AM,KZ,DE,GB,IT', 'more' => 'و کشورهای دیگر…'],
            'faq_head' => ['kicker' => 'پاسخ‌ها', 'title' => 'پرسش‌های پرتکرار'],
            'faq' => [
                ['q' => 'عضویت هزینه دارد؟', 'a' => 'نه. عضویت، ساخت صفحه تجاری به چند زبان و پاسخ‌دادن به نامه‌ها رایگان است. {paid_cap} با اعتبار داخلی «Stars» انجام می‌شود.'],
                ['q' => 'Stars چیست؟', 'a' => 'Stars اعتبار داخلی سامانه است و برای {paid} استفاده می‌شود. هزینه داخلی و بین‌المللی جداست و پیش از هر کسر، دقیقاً می‌بینید چند Star لازم است.'],
                ['q' => 'صفحه تجاری چندزبانه چطور کار می‌کند؟', 'a' => 'برای هر زبان یک صفحه می‌سازید. هر تاجری که صفحه شما را باز کند، بر اساس کشور و زبان حسابش، صفحه مناسب را می‌بیند. خودتان تعیین می‌کنید چه کسی کدام صفحه را ببیند.'],
                ['q' => 'اطلاعات تماس من امن است؟', 'a' => 'بله. شماره تماس، ایمیل و شبکه‌های اجتماعی شما در هیچ بخشی از سامانه به تجار دیگر نمایش داده نمی‌شود و در صفحه تجاری، پروفایل و پیشنهادها هم درج آن‌ها مجاز نیست. ارتباط از داخل سامانه انجام می‌شود و اگر خودتان بخواهید، می‌توانید اطلاعات تماس را در نامه خصوصی برای تاجر مورد نظر بفرستید.'],
                ['q' => 'ارتباط تجاری چطور ساخته می‌شود؟', 'a' => 'هر بار تاجری به نامه یا پیشنهاد شما پاسخ دهد، یک ارتباط تجاری ثبت می‌شود. شبکه شما با هر گفتگو بزرگ‌تر می‌شود.'],
                ['q' => 'از چه کشورهایی عضو می‌شوند؟', 'a' => 'سامانه بین‌المللی است و تجار همه کشورها و زبان‌ها می‌توانند عضو شوند، صفحه بسازند و با هم ارتباط بگیرند.'],
            ],
            'final' => [
                'title' => 'شبکه تجاری خود را از امروز بسازید', 'text' => 'عضویت رایگان است و کمتر از یک دقیقه طول می‌کشد.',
                'cta1_label' => 'عضویت رایگان', 'cta1_href' => '/register', 'cta2_label' => 'ورود به سامانه', 'cta2_href' => '/login',
            ],
        ];
    }

    /** Saved values over defaults: maps merge key by key, lists are replaced as a whole. */
    private static function merge(array $base, array $over): array
    {
        foreach ($base as $k => $v) {
            if (!array_key_exists($k, $over)) {
                continue;
            }
            if (is_array($v) && !array_is_list($v) && is_array($over[$k])) {
                $base[$k] = self::merge($v, $over[$k]);
            } elseif (is_array($v) && array_is_list($v) && is_array($over[$k])) {
                $tpl = $v[0] ?? [];
                $base[$k] = array_values(array_map(static fn ($row) => is_array($row) ? self::merge($tpl, $row) : $tpl, $over[$k]));
            } elseif (!is_array($v) && !is_array($over[$k])) {
                $base[$k] = $over[$k];
            }
        }
        return $base;
    }

    /**
     * Form input (home[...]) → clean content. Rows are kept in the order of their "_order" field;
     * rows ticked "_delete" or left completely empty are dropped. Images are handled by the controller.
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $current): array
    {
        $out = [];
        foreach (self::defaults() as $key => $def) {
            $in = is_array($input[$key] ?? null) ? $input[$key] : [];
            if (array_is_list($def)) {
                $tpl = $def[0];
                $rows = [];
                foreach ($in as $i => $row) {
                    if (!is_array($row) || !empty($row['_delete'])) {
                        continue;
                    }
                    $clean = self::cleanRow($tpl, $row, $key);
                    if (!self::isEmptyRow($clean, $tpl)) {
                        $rows[] = ['o' => (float) self::digits((string) ($row['_order'] ?? $i)), 'i' => count($rows), 'r' => $clean];
                    }
                }
                usort($rows, static fn ($a, $b) => [$a['o'], $a['i']] <=> [$b['o'], $b['i']]);
                $out[$key] = array_slice(array_column($rows, 'r'), 0, self::LISTS[$key] ?? 12);
            } else {
                $out[$key] = $key === 'show'
                    ? array_map(static fn ($k) => !empty($in[$k]), array_combine(array_keys($def), array_keys($def)))
                    : self::cleanRow($def, $in, $key);
            }
        }
        return $out;
    }

    private static function cleanRow(array $tpl, array $row, string $list): array
    {
        $out = [];
        foreach ($tpl as $field => $default) {
            $v = $row[$field] ?? null;
            if (is_bool($default)) {
                $out[$field] = !empty($v);
            } elseif (is_float($default) || is_int($default)) {
                $n = (float) self::digits(trim((string) $v));
                $lim = $field === 'lat' ? 85.0 : 180.0;
                $out[$field] = max(-$lim, min($lim, $n));
            } elseif ($field === 'image') {
                $out[$field] = self::cleanImage((string) $v);
            } else {
                $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '');
                $s = mb_substr($s, 0, in_array($field, self::LONG, true) ? 1200 : 240);
                $out[$field] = match (true) {
                    str_ends_with($field, 'href') => self::cleanHref($s),
                    $field === 'code' => preg_match('/^[A-Za-z]{2}$/', $s) ? strtoupper($s) : '',
                    $field === 'codes' => implode(',', array_slice(array_values(array_unique(array_filter(
                        array_map(static fn ($c) => strtoupper(trim($c)), preg_split('/[\s,،]+/u', $s) ?: []),
                        static fn ($c) => (bool) preg_match('/^[A-Z]{2}$/', $c)
                    ))), 0, 40)),
                    $field === 'icon' => isset(self::ICONS[$s]) ? $s : (string) $default,
                    $field === 'art' => isset(self::ARTS[$s]) ? $s : (string) $default,
                    $field === 'metric' => isset(self::METRICS[$s]) ? $s : (string) $default,
                    $field === 'mode' => $s === 'air' ? 'air' : 'sea',
                    default => $s,
                };
            }
        }
        return $out;
    }

    private static function isEmptyRow(array $row, array $tpl): bool
    {
        foreach ($row as $field => $v) {
            if (is_string($v) && $v !== '' && !in_array($field, ['icon', 'art', 'metric', 'mode'], true)) {
                return false;
            }
        }
        return true;
    }

    /** Only site-relative paths, in-page anchors and http(s) URLs. */
    public static function cleanHref(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('~^(/(?!/)|#)[^\s<>"\']*$~u', $href) || preg_match('~^https?://[^\s<>"\']+$~iu', $href)) {
            return $href;
        }
        return '';
    }

    /** Stored image paths are only ones produced by ImageUploader (preset/yyyy/mm/ulid.webp). */
    public static function cleanImage(string $path): string
    {
        return preg_match('~^[a-z]+/\d{4}/\d{2}/[0-9a-z]{26}\.webp$~', $path) ? $path : '';
    }

    /** Text with the live paid-features phrase filled in ({paid} and sentence-start {paid_cap}). */
    public static function fill(string $text, string $paid): string
    {
        return strtr($text, ['{paid_cap}' => $paid, '{paid}' => $paid]);
    }

    private static function digits(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '−' => '-']);
    }
}
