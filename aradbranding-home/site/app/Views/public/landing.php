<?php
/**
 * @var array|null $stats @var string $baseUrl @var int $publishFee
 * @var array<string, int> $countryIds ISO code → countries.id (links to /discover/country/{id})
 * @var array<int, array> $countryStats country id → users/pages/proposals (only when public stats are switched on)
 */
$countryIds = $countryIds ?? [];
$countryStats = $countryStats ?? [];
// Paid features are listed from the live settings: if publishing a proposal costs Stars, it is never described as free.
$paid = $publishFee > 0
    ? 'انتشار پیشنهاد در فید، مشاهده کامل صفحه تجار دیگر و ارسال نامه و پیشنهاد'
    : 'مشاهده کامل صفحه تجار دیگر و ارسال نامه و پیشنهاد';
$faq = [
    ['عضویت هزینه دارد؟', 'نه. عضویت، ساخت صفحه تجاری به چند زبان و پاسخ‌دادن به نامه‌ها رایگان است. ' . $paid . ' با اعتبار داخلی «Stars» انجام می‌شود.'],
    ['Stars چیست؟', 'Stars اعتبار داخلی سامانه است و برای ' . $paid . ' استفاده می‌شود. هزینه داخلی و بین‌المللی جداست و پیش از هر کسر، دقیقاً می‌بینید چند Star لازم است.'],
    ['صفحه تجاری چندزبانه چطور کار می‌کند؟', 'برای هر زبان یک صفحه می‌سازید. هر تاجری که صفحه شما را باز کند، بر اساس کشور و زبان حسابش، صفحه مناسب را می‌بیند. خودتان تعیین می‌کنید چه کسی کدام صفحه را ببیند.'],
    ['اطلاعات تماس من امن است؟', 'شماره تماس، ایمیل و راه‌های ارتباطی فقط در بخش کامل صفحه و برای تجار واردشده نمایش داده می‌شود. در معرفی عمومی و پیشنهادها اجازه درج اطلاعات تماس داده نمی‌شود.'],
    ['ارتباط تجاری چطور ساخته می‌شود؟', 'هر بار تاجری به نامه یا پیشنهاد شما پاسخ دهد، یک ارتباط تجاری ثبت می‌شود. شبکه شما با هر گفتگو بزرگ‌تر می‌شود.'],
    ['از چه کشورهایی عضو می‌شوند؟', 'سامانه بین‌المللی است و تجار همه کشورها و زبان‌ها می‌توانند عضو شوند، صفحه بسازند و با هم ارتباط بگیرند.'],
];
$countries = [['IR', 'ایران'], ['AE', 'امارات'], ['TR', 'ترکیه'], ['IQ', 'عراق'], ['AF', 'افغانستان'], ['RU', 'روسیه'], ['CN', 'چین'], ['IN', 'هند'],
    ['OM', 'عمان'], ['QA', 'قطر'], ['AZ', 'آذربایجان'], ['AM', 'ارمنستان'], ['KZ', 'قزاقستان'], ['DE', 'آلمان'], ['GB', 'بریتانیا'], ['IT', 'ایتالیا']];

// Markets shown on the globe and in the market cards. Coordinates anchor the floating cards on the 3D globe.
$markets = [
    'CN' => ['name' => 'چین', 'lat' => 33.5, 'lon' => 106.0, 'secondary' => false],
    'IN' => ['name' => 'هند', 'lat' => 22.0, 'lon' => 78.5, 'secondary' => false],
    'AE' => ['name' => 'امارات', 'lat' => 24.0, 'lon' => 54.5, 'secondary' => false],
    'TR' => ['name' => 'ترکیه', 'lat' => 39.0, 'lon' => 35.0, 'secondary' => false],
    'DE' => ['name' => 'آلمان', 'lat' => 51.0, 'lon' => 10.3, 'secondary' => true],
    'RU' => ['name' => 'روسیه', 'lat' => 56.0, 'lon' => 40.0, 'secondary' => true],
    'BR' => ['name' => 'برزیل', 'lat' => -11.0, 'lon' => -50.0, 'secondary' => true],
];
$marketHref = static fn (string $code): string => isset($countryIds[$code]) ? '/discover/country/' . $countryIds[$code] : '/discover';
$marketStat = static function (string $code) use ($countryIds, $countryStats): ?array {
    $id = $countryIds[$code] ?? null;
    return $id !== null && isset($countryStats[$id]) ? $countryStats[$id] : null;
};
$maxProposals = 1;
foreach (array_keys($markets) as $code) {
    $maxProposals = max($maxProposals, (int) ($marketStat($code)['proposals'] ?? 0));
}
$marketNote = static function (string $code) use ($marketStat): string {
    $s = $marketStat($code);
    if ($s === null) {
        return 'تجار و فرصت‌های این بازار';
    }
    return '+' . fa_int((int) $s['proposals']) . ' فرصت · ' . fa_int((int) $s['users']) . ' عضو';
};

// Stats strip: real cached totals when the admin switches them on, otherwise platform facts that are always true.
$strip = $stats !== null
    ? [
        ['value' => (int) $stats['users'], 'plus' => true, 'label' => 'تاجر عضو', 'icon' => 'm-people'],
        ['value' => (int) $stats['countries'], 'plus' => false, 'label' => 'کشور فعال', 'icon' => 'm-globe'],
        ['value' => (int) $stats['proposals'], 'plus' => true, 'label' => 'فرصت تجاری فعال', 'icon' => 'm-box'],
        ['value' => (int) $stats['connections'], 'plus' => true, 'label' => 'ارتباط تجاری', 'icon' => 'm-chart'],
    ]
    : [
        ['value' => 243, 'plus' => false, 'label' => 'کشور و منطقه قابل انتخاب', 'icon' => 'm-globe'],
        ['value' => 27, 'plus' => false, 'label' => 'زبان برای صفحه تجاری', 'icon' => 'm-page'],
        ['value' => 6, 'plus' => false, 'label' => 'نوع فرصت تجاری', 'icon' => 'm-box'],
        ['value' => null, 'text' => 'رایگان', 'label' => 'عضویت و ساخت صفحه', 'icon' => 'm-star'],
    ];

// Quick searches into published proposals (/search?type=proposals). Illustrative shortcuts, not live demand data.
$shortcuts = [
    ['art' => 'prod-saffron', 'product' => 'زعفران', 'market' => 'چین', 'code' => 'CN', 'mode' => 'm-plane', 'tag' => 'کشاورزی'],
    ['art' => 'prod-dates', 'product' => 'خرما', 'market' => 'روسیه', 'code' => 'RU', 'mode' => 'm-ship', 'tag' => 'مواد غذایی'],
    ['art' => 'prod-pistachio', 'product' => 'پسته', 'market' => 'هند', 'code' => 'IN', 'mode' => 'm-ship', 'tag' => 'خشکبار'],
    ['art' => 'prod-petro', 'product' => 'محصولات پتروشیمی', 'market' => 'ترکیه', 'code' => 'TR', 'mode' => 'm-ship', 'tag' => 'صنعتی'],
    ['art' => 'prod-carpet', 'product' => 'فرش دستباف', 'market' => 'آلمان', 'code' => 'DE', 'mode' => 'm-plane', 'tag' => 'صنایع دستی'],
];
$searchHref = static function (string $q, ?string $code) use ($countryIds): string {
    $params = ['type' => 'proposals', 'q' => $q];
    if ($code !== null && isset($countryIds[$code])) {
        $params['country'] = $countryIds[$code];
    }
    return '/search?' . http_build_query($params);
};

// Platform modules → existing routes (signed-out visitors are sent to /login and returned afterwards).
$modules = [
    ['href' => '/proposals', 'icon' => 'm-chart', 'title' => 'فرصت‌های تجاری', 'text' => 'خرید، فروش، مشارکت و نمایندگی'],
    ['href' => '/discover', 'icon' => 'm-globe', 'title' => 'بازارهای هدف', 'text' => 'کشف تجار بر اساس کشور'],
    ['href' => '/connections', 'icon' => 'm-people', 'title' => 'شبکه تجاری', 'text' => 'ارتباط‌های ساخته‌شده شما'],
    ['href' => '/letters', 'icon' => 'm-letter', 'title' => 'نامه‌های تجاری', 'text' => 'نامه اختصاصی و عمومی'],
    ['href' => '/pages', 'icon' => 'm-page', 'title' => 'صفحه تجاری', 'text' => 'معرفی چندزبانه کسب‌وکار'],
    ['href' => '/wallet', 'icon' => 'm-star', 'title' => 'Stars', 'text' => 'اعتبار داخلی و گردش حساب'],
];

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', 'name' => 'سامانه توسعه تجارت', 'alternateName' => 'Arad Branding', 'url' => $baseUrl . '/', 'logo' => $baseUrl . '/icons/icon-512.png'],
        ['@type' => 'WebSite', 'name' => 'سامانه توسعه تجارت', 'url' => $baseUrl . '/', 'inLanguage' => 'fa',
            'potentialAction' => ['@type' => 'SearchAction', 'target' => $baseUrl . '/search?q={q}', 'query-input' => 'required name=q']],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(static fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
    ],
];
$desc = 'شبکه بین‌المللی تجار و فعالان اقتصادی: صفحه تجاری چندزبانه بسازید، فرصت‌های تجاری را کشف کنید و مستقیم با تجار کشورهای مختلف ارتباط بگیرید. عضویت رایگان است.';
$flagUse = static fn (string $code): string => '<svg class="th-flag" viewBox="0 0 30 20" aria-hidden="true"><use href="#flag-' . e($code) . '"/></svg>';
$icon = static fn (string $id, string $cls = 'th-ic'): string => '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true"><use href="#' . e($id) . '"/></svg>';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<?= $this->partial('partials/head') ?>
<link rel="stylesheet" href="<?= e(asset('trade-home.css')) ?>">
<link rel="modulepreload" href="<?= e(asset('trade-globe.js')) ?>">
<script src="<?= e(asset('trade-home.js')) ?>" defer></script>
<script type="module" src="<?= e(asset('trade-globe.js')) ?>"></script>
<title>سامانه توسعه تجارت · شبکه بین‌المللی تجار و فعالان اقتصادی</title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($baseUrl) ?>/">
<link rel="alternate" hreflang="fa" href="<?= e($baseUrl) ?>/">
<link rel="alternate" hreflang="x-default" href="<?= e($baseUrl) ?>/">
<meta property="og:type" content="website">
<meta property="og:site_name" content="سامانه توسعه تجارت">
<meta property="og:title" content="سامانه توسعه تجارت · شبکه بین‌المللی تجار">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($baseUrl) ?>/">
<meta property="og:image" content="<?= e($baseUrl) ?>/icons/icon-512.png">
<meta property="og:locale" content="fa_IR">
<meta name="twitter:card" content="summary">
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="landing th">
<?= $this->partial('partials/icons') ?>
<?= $this->partial('public/_trade_sprite') ?>
<a class="skip" href="#main">پرش به محتوا</a>

<header class="th-head" data-th-head>
  <div class="th-head-in">
    <a class="th-brand" href="/" aria-label="سامانه توسعه تجارت · صفحه اصلی">
      <span class="brand-mark th-mark"><svg class="icon"><use href="#i-mark"/></svg></span>
      <span class="th-brand-name">سامانه توسعه تجارت<small>Arad Branding · شبکه بین‌المللی تجار</small></span>
    </a>
    <nav class="th-nav" aria-label="ناوبری اصلی">
      <a class="is-active" href="/" aria-current="page">صفحه اصلی</a>
      <a href="/discover">بازارهای هدف</a>
      <a href="/proposals">فرصت‌های تجاری</a>
      <a href="/connections">شبکه تجاری</a>
      <a class="th-nav-xl" href="/letters">نامه‌ها</a>
      <a href="#how">چطور کار می‌کند</a>
      <a class="th-nav-xl" href="#stars">Stars</a>
      <a class="th-nav-xl" href="#faq">پرسش‌ها</a>
    </nav>
    <form class="th-search" method="get" action="/search" role="search">
      <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
      <input type="search" name="q" placeholder="جستجوی تاجر، کالا یا فرصت…" aria-label="جستجو" enterkeyhint="search">
    </form>
    <div class="th-actions">
      <span class="th-lang" title="زبان سامانه"><?= $icon('m-globe') ?>FA</span>
      <a class="th-btn th-btn-ghost" href="/login">ورود</a>
      <a class="th-btn th-btn-gold" href="/register">ثبت‌نام</a>
      <details class="th-menu">
        <summary aria-label="منو"><?= $icon('m-menu') ?></summary>
        <div class="th-menu-panel">
          <form class="th-search th-search-m" method="get" action="/search" role="search">
            <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
            <input type="search" name="q" placeholder="جستجوی تاجر، کالا یا فرصت…" aria-label="جستجو" enterkeyhint="search">
          </form>
          <nav aria-label="منوی موبایل">
            <a href="/"><?= $icon('m-globe') ?>صفحه اصلی</a>
            <?php foreach ($modules as $m): ?><a href="<?= e($m['href']) ?>"><?= $icon($m['icon']) ?><?= e($m['title']) ?></a><?php endforeach; ?>
            <a href="#how"><?= $icon('m-chart') ?>چطور کار می‌کند</a>
            <a href="#faq"><?= $icon('m-letter') ?>پرسش‌های پرتکرار</a>
          </nav>
          <div class="th-menu-cta"><a class="th-btn th-btn-ghost" href="/login">ورود</a><a class="th-btn th-btn-gold" href="/register">عضویت رایگان</a></div>
        </div>
      </details>
    </div>
  </div>
</header>

<main id="main">
  <section class="th-hero" data-trade-globe data-tex="/assets/globe/" aria-labelledby="hero-title">
    <div class="th-hero-bg" aria-hidden="true"></div>
    <div class="tg-scene">
    <div class="tg-stage" aria-hidden="true">
      <div class="tg-poster"><div class="tg-poster-globe"></div></div>
    </div>
    <svg class="tg-lines" aria-hidden="true"></svg>
    <div class="tg-labels" aria-hidden="true"></div>
    <div class="tg-cards">
      <?php foreach ($markets as $code => $m): ?>
      <a class="tg-card<?= $m['secondary'] ? ' is-secondary' : '' ?>" href="<?= e($marketHref($code)) ?>" data-lat="<?= e($m['lat']) ?>" data-lon="<?= e($m['lon']) ?>" data-name="<?= e($m['name']) ?>" data-note="<?= e($marketNote($code)) ?>">
        <?= $flagUse($code) ?>
        <span class="tg-card-t"><b><?= e($m['name']) ?></b><small><?= e($marketNote($code)) ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="tg-tip" role="status" hidden></div>
    </div>

    <div class="th-hero-in">
      <div class="th-hero-copy">
        <p class="th-badge"><?= $icon('m-globe') ?>بستر ارتباطات تجارت جهانی</p>
        <h1 id="hero-title"><span class="th-h1-gold">تجارت جهانی</span><span class="th-h1-sub">همین حالا در دسترس شماست</span></h1>
        <p class="th-lead">دسترسی به بازارهای جهانی، فرصت‌های تجاری، تأمین‌کنندگان، خریداران و مسیرهای تجارت بین‌المللی در یک سامانه یکپارچه.</p>
        <div class="th-cta">
          <a class="th-btn th-btn-gold th-btn-lg" href="/register">شروع تجارت<?= $icon('m-arrow') ?></a>
          <a class="th-btn th-btn-glass th-btn-lg" href="/proposals"><span class="th-play"><?= $icon('m-play') ?></span>مشاهده فرصت‌ها</a>
        </div>
        <p class="th-note">بدون هزینه عضویت · صفحه تجاری چندزبانه · ارتباط مستقیم</p>
      </div>
    </div>

    <aside class="th-rail" aria-label="نمای زنده نقشه">
      <div class="th-rail-i"><?= $icon('m-globe') ?><span><small>پوشش جهانی</small><b>۲۴۳ کشور</b></span></div>
      <div class="th-rail-i"><?= $icon('m-ship') ?><span><small>مسیرهای دریایی روی نقشه</small><b><span data-live="sea">۷</span> مسیر</b></span></div>
      <div class="th-rail-i"><?= $icon('m-plane') ?><span><small>مسیرهای هوایی باری</small><b><span data-live="air">۱۰</span> مسیر</b></span></div>
      <div class="th-rail-i"><?= $icon('m-anchor') ?><span><small>بنادر و هاب‌های تجاری</small><b><span data-live="nodes">۱۹</span> گره</b></span></div>
    </aside>
    <p class="th-hint" aria-hidden="true"><span>بکشید تا بچرخد</span> · <span>دوبار کلیک برای بزرگنمایی</span> · نمایش تصویری مسیرهای تجارت جهانی</p>
  </section>

  <section class="th-stats" aria-label="<?= $stats !== null ? 'آمار سامانه' : 'سامانه در یک نگاه' ?>">
    <?php foreach ($strip as $i => $s): ?>
    <div class="th-stat">
      <span class="th-stat-ic"><?= $icon($s['icon']) ?></span>
      <span class="th-stat-t">
        <b<?= $s['value'] !== null ? ' data-count="' . (int) $s['value'] . '"' . ($s['plus'] ? ' data-plus' : '') : '' ?>><?= $s['value'] !== null ? ($s['plus'] ? '+' : '') . fa_int($s['value']) : e($s['text']) ?></b>
        <small><?= e($s['label']) ?></small>
      </span>
      <span class="th-spark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="th-discover" aria-labelledby="discover-title">
    <div class="th-finder th-glass">
      <div class="th-finder-h">
        <span class="th-ring"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-compass"/></svg></span>
        <div>
          <h2 id="discover-title">بازار بعدی خود را پیدا کنید</h2>
          <p>در میان تجار، صفحه‌های تجاری و فرصت‌های منتشرشده کشورهای مختلف جستجو کنید و بازار هدف محصول خود را بشناسید.</p>
        </div>
      </div>
      <form class="th-finder-f" method="get" action="/search">
        <label class="th-field"><span>محصول یا کالا</span><input type="search" name="q" placeholder="مثلاً زعفران، خرما، فولاد…" enterkeyhint="search"></label>
        <label class="th-field"><span>کشور</span>
          <select name="country">
            <option value="0">همه کشورها</option>
            <?php foreach ($markets + array_fill_keys(array_column($countries, 0), null) as $code => $_):
                if (!isset($countryIds[$code])) { continue; }
                $label = $markets[$code]['name'] ?? (array_column($countries, 1, 0)[$code] ?? $code); ?>
            <option value="<?= (int) $countryIds[$code] ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="th-field"><span>نوع نتیجه</span>
          <select name="type">
            <option value="proposals">فرصت‌های تجاری</option>
            <option value="traders">تجار</option>
            <option value="pages">صفحه‌های تجاری</option>
          </select>
        </label>
        <button class="th-btn th-btn-gold th-btn-block" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>جستجوی بازارها</button>
      </form>
    </div>

    <div class="th-markets" aria-label="بازارهای هدف">
      <?php foreach (['CN', 'IN', 'AE', 'TR'] as $code): $s = $marketStat($code); ?>
      <a class="th-market" href="<?= e($marketHref($code)) ?>">
        <svg class="th-market-art" viewBox="0 0 240 150" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#sky-<?= e($code) ?>"/></svg>
        <span class="th-market-b">
          <span class="th-market-n"><?= $flagUse($code) ?><b><?= e($markets[$code]['name']) ?></b></span>
          <small><?= $s !== null ? '+' . fa_int((int) $s['proposals']) . ' فرصت' : 'مشاهده تجار این کشور' ?></small>
          <span class="th-market-f">
            <?php if ($s !== null): ?><span class="th-bar"><i data-w="<?= (int) round(100 * (int) $s['proposals'] / $maxProposals) ?>"></i></span><?php else: ?><span class="th-bar th-bar-idle"><i></i></span><?php endif; ?>
            <span class="th-go"><?= $icon('m-arrow') ?></span>
          </span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="th-opps th-glass" aria-labelledby="opps-title">
      <div class="th-opps-h"><h2 id="opps-title"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-spark"/></svg>فرصت‌های تجاری</h2><a href="/proposals">مشاهده همه<?= $icon('m-arrow') ?></a></div>
      <p class="th-opps-s">جستجوی سریع در پیشنهادهای منتشرشده</p>
      <ul>
        <?php foreach ($shortcuts as $sc): ?>
        <li><a href="<?= e($searchHref($sc['product'], $sc['code'])) ?>">
          <svg class="th-opp-art" viewBox="0 0 80 56" aria-hidden="true"><use href="#<?= e($sc['art']) ?>"/></svg>
          <span class="th-opp-t"><b><?= e($sc['product']) ?> <?= $icon('m-arrow', 'th-ic th-ic-sm') ?> <?= e($sc['market']) ?></b><small><?= $flagUse($sc['code']) ?><?= e($sc['tag']) ?> · <?= $icon($sc['mode'], 'th-ic th-ic-sm') ?></small></span>
          <span class="th-go"><?= $icon('m-arrow') ?></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section class="th-modules" aria-label="بخش‌های سامانه">
    <div class="th-mod-grid">
      <?php foreach ($modules as $m): ?>
      <a class="th-mod" href="<?= e($m['href']) ?>"><?= $icon($m['icon'], 'th-ic th-ic-lg') ?><b><?= e($m['title']) ?></b><small><?= e($m['text']) ?></small></a>
      <?php endforeach; ?>
    </div>
    <a class="th-banner" href="#how">
      <svg class="th-banner-art" viewBox="0 0 600 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#banner-ship"/></svg>
      <span class="th-banner-t"><b>مسیر مطمئن<br>تجارت بین‌المللی</b><small>ببینید سامانه چطور کار می‌کند</small></span>
      <span class="th-banner-play"><?= $icon('m-play') ?></span>
    </a>
  </section>

  <section class="th-sec" id="how">
    <header class="th-sec-h"><p class="th-kicker">مسیر شروع</p><h2>چطور کار می‌کند</h2><p>سامانه توسعه تجارت جایی است که تولیدکننده، بازرگان و ارائه‌دهنده خدمات، خود را به بازارهای دیگر معرفی می‌کند و طرف معامله‌اش را پیدا می‌کند. هر گفتگو یک ارتباط تجاری می‌سازد و هر ارتباط، درِ بازار تازه‌ای را باز می‌کند.</p></header>
    <ol class="th-steps">
      <li><b>عضو شوید</b><span>در کمتر از یک دقیقه و رایگان.</span></li>
      <li><b>صفحه تجاری بسازید</b><span>به هر زبانی که مشتریانتان صحبت می‌کنند.</span></li>
      <li><b>فرصت‌ها را کشف کنید</b><span>پیشنهادهای خرید، فروش، مشارکت و سرمایه‌گذاری.</span></li>
      <li><b>ارتباط بگیرید</b><span>پیشنهاد و نامه بفرستید؛ هر پاسخ، یک ارتباط تجاری.</span></li>
    </ol>
  </section>

  <section class="th-sec" id="features">
    <header class="th-sec-h"><p class="th-kicker">ابزارهای سامانه</p><h2>امکانات</h2></header>
    <div class="th-features">
      <article class="th-glass"><?= $icon('m-page', 'th-ic th-ic-lg') ?><h3>صفحه تجاری چندزبانه</h3><p>یک وب‌سایت کوچک برای کسب‌وکار شما. هر بازدیدکننده صفحه هم‌زبان خودش را می‌بیند و در گوگل هم پیدا می‌شوید.</p></article>
      <article class="th-glass"><?= $icon('m-chart', 'th-ic th-ic-lg') ?><h3>پیشنهادهای تجاری</h3><p>فرصت خرید، فروش، مشارکت یا نمایندگی را با تصویر منتشر کنید و در فید تجار کشورهای دیگر دیده شوید.</p></article>
      <article class="th-glass"><?= $icon('m-letter', 'th-ic th-ic-lg') ?><h3>نامه اختصاصی و عمومی</h3><p>به یک تاجر نامه بزنید، یا با یک نامه تجار یک کشور، زبان یا حوزه را باخبر کنید. پاسخ‌دادن همیشه رایگان است.</p></article>
      <article class="th-glass"><?= $icon('m-people', 'th-ic th-ic-lg') ?><h3>شبکه ارتباطات</h3><p>هر پاسخ، یک ارتباط تجاری ثبت می‌کند. شبکه‌ای که با هر گفتگو بزرگ‌تر می‌شود.</p></article>
    </div>
  </section>

  <section class="th-sec th-stars" id="stars">
    <div>
      <p class="th-kicker">اعتبار داخلی</p>
      <h2>Stars؛ اعتبار داخلی، شفاف و منصفانه</h2>
      <p>عضویت، ساخت صفحه و پاسخ‌دادن رایگان است. برای <?= e($paid) ?>، از Stars استفاده می‌کنید. پیش از هر کسر، دقیقاً می‌بینید چقدر لازم است.</p>
    </div>
    <ul class="th-stars-list th-glass"><li><?= $icon('m-star') ?>هزینه داخلی و بین‌المللی جدا</li><li><?= $icon('m-star') ?>بسته‌های خرید با Star هدیه</li><li><?= $icon('m-star') ?>گردش حساب کامل و قابل پیگیری</li></ul>
  </section>

  <section class="th-sec" id="countries">
    <header class="th-sec-h"><p class="th-kicker">شبکه بین‌المللی</p><h2>تجار از کشورهای مختلف</h2></header>
    <div class="th-chips"><?php foreach ($countries as [$code, $name]): ?><a class="th-chip" href="<?= e($marketHref($code)) ?>"><span aria-hidden="true"><?= flag($code) ?></span><?= e($name) ?></a><?php endforeach; ?><span class="th-chip">و کشورهای دیگر…</span></div>
  </section>

  <section class="th-sec" id="faq">
    <header class="th-sec-h"><p class="th-kicker">پاسخ‌ها</p><h2>پرسش‌های پرتکرار</h2></header>
    <div class="th-faq"><?php foreach ($faq as [$q, $a]): ?><details class="th-glass"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
  </section>

  <section class="th-sec th-final">
    <div class="th-final-in th-glass">
      <h2>شبکه تجاری خود را از امروز بسازید</h2>
      <p>عضویت رایگان است و کمتر از یک دقیقه طول می‌کشد.</p>
      <div class="th-cta th-cta-center"><a class="th-btn th-btn-gold th-btn-lg" href="/register">عضویت رایگان</a><a class="th-btn th-btn-glass th-btn-lg" href="/login">ورود به سامانه</a></div>
    </div>
  </section>
</main>

<footer class="th-foot">
  <div class="th-foot-in">
    <a class="th-brand" href="/"><span class="brand-mark th-mark"><svg class="icon"><use href="#i-mark"/></svg></span><span class="th-brand-name">سامانه توسعه تجارت<small>© <bdi>aradbranding.app</bdi></small></span></a>
    <nav aria-label="پیوندهای پایین صفحه"><a href="/register">عضویت</a><a href="/login">ورود</a><a href="/discover">بازارهای هدف</a><a href="/proposals">فرصت‌ها</a><a href="#faq">پرسش‌ها</a></nav>
  </div>
</footer>
</body>
</html>
