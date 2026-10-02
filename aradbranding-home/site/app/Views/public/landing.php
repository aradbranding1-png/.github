<?php
/**
 * Public home page. Every text, link, list and section switch comes from $home (App\Modules\System\HomeContent,
 * edited by the admin at /admin/home). Numbers are real cached totals only when public stats are switched on.
 *
 * @var array $home @var array|null $stats @var string $baseUrl @var int $publishFee
 * @var array<string, int> $countryIds ISO code → countries.id (links to /discover/country/{id})
 * @var array<string, string> $countryNames ISO code → Persian name
 * @var array<int, array> $countryStats country id → users/pages/proposals (only when public stats are switched on)
 */
use App\Modules\System\HomeContent;

$home = $home ?? HomeContent::defaults();
$countryIds = $countryIds ?? [];
$countryNames = $countryNames ?? [];
$countryStats = $countryStats ?? [];
$show = $home['show'];
// Paid features are listed from the live settings: if publishing a proposal costs Stars, it is never described as free.
$paid = $publishFee > 0
    ? 'انتشار پیشنهاد در فید، مشاهده کامل صفحه تجار دیگر و ارسال نامه و پیشنهاد'
    : 'مشاهده کامل صفحه تجار دیگر و ارسال نامه و پیشنهاد';
$fill = static fn (string $t): string => HomeContent::fill($t, $paid);
$faq = array_map(static fn (array $f): array => [$f['q'], $fill($f['a'])], $home['faq']);

$hasSky = ['CN' => true, 'IN' => true, 'AE' => true, 'TR' => true, 'DE' => true, 'RU' => true, 'IQ' => true];
$markets = array_values(array_filter($home['markets'], static fn (array $m): bool => $m['code'] !== ''));
$marketHref = static fn (string $code): string => isset($countryIds[$code]) ? '/discover/country/' . $countryIds[$code] : '/discover';
$marketStat = static function (string $code) use ($countryIds, $countryStats): ?array {
    $id = $countryIds[$code] ?? null;
    return $id !== null && isset($countryStats[$id]) ? $countryStats[$id] : null;
};
$maxProposals = 1;
foreach ($markets as $m) {
    $maxProposals = max($maxProposals, (int) ($marketStat($m['code'])['proposals'] ?? 0));
}
$marketNote = static function (string $code) use ($marketStat): string {
    $s = $marketStat($code);
    return $s === null ? 'تجار و فرصت‌های این بازار' : '+' . fa_int((int) $s['proposals']) . ' فرصت · ' . fa_int((int) $s['users']) . ' عضو';
};
$cardMarkets = array_values(array_filter($markets, static fn (array $m): bool => $m['card']));
$globeMarkets = array_values(array_filter($markets, static fn (array $m): bool => $m['globe']));
// Every country reached by a route drawn on the globe also gets a flag card (unless the admin already listed it).
$routeCountries = [
    ['IQ', 'عراق', 33.0, 43.5, 'مسیر زمینی از ایران'], ['AF', 'افغانستان', 34.0, 66.0, 'مسیر زمینی از ایران'],
    ['KE', 'کنیا', 0.3, 37.9, 'دریایی + زمینی از ایران'], ['TZ', 'تانزانیا', -6.4, 34.9, 'دریایی + زمینی از ایران'],
    ['ZA', 'آفریقای جنوبی', -28.5, 25.5, 'دریایی + زمینی از ایران'], ['NG', 'نیجریه', 9.1, 8.7, 'دریایی + زمینی از ایران'],
    ['US', 'آمریکا', 39.5, -98.0, 'مسیر دریایی از آفریقا'], ['CA', 'کانادا', 56.0, -106.0, 'مسیر دریایی از آفریقا'],
    ['GB', 'بریتانیا', 53.5, -1.8, 'مسیر هوایی · لندن'], ['NL', 'هلند', 52.4, 5.6, 'بندر روتردام'],
];
$listed = array_column($globeMarkets, 'code');
$routeCards = array_values(array_filter($routeCountries, static fn (array $c): bool => !in_array($c[0], $listed, true)));

// Stats strip: real cached totals when the admin switches them on, otherwise the admin's fixed facts.
$strip = [];
if ($stats !== null) {
    foreach ($home['stats_live'] as $s) {
        $strip[] = ['value' => (int) ($stats[$s['metric']] ?? 0), 'plus' => $s['metric'] !== 'countries', 'label' => $s['label'], 'icon' => $s['icon']];
    }
} else {
    foreach ($home['stats_static'] as $s) {
        $digits = strtr($s['value'], ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٬' => '', ',' => '']);
        $plus = str_starts_with($digits, '+');
        $digits = ltrim($digits, '+');
        $strip[] = ctype_digit($digits) && $digits !== ''
            ? ['value' => (int) $digits, 'plus' => $plus, 'label' => $s['label'], 'icon' => $s['icon']]
            : ['value' => null, 'text' => $s['value'], 'label' => $s['label'], 'icon' => $s['icon']];
    }
}

$searchHref = static function (string $q, string $code) use ($countryIds): string {
    $params = ['type' => 'proposals', 'q' => $q];
    if (isset($countryIds[$code])) {
        $params['country'] = $countryIds[$code];
    }
    return '/search?' . http_build_query($params);
};
$chipCodes = array_values(array_filter(explode(',', $home['countries']['codes'])));
$finderCodes = array_values(array_unique(array_merge(array_column($markets, 'code'), $chipCodes)));
$nameOf = static function (string $code) use ($markets, $countryNames): string {
    foreach ($markets as $m) {
        if ($m['code'] === $code && $m['name'] !== '') {
            return $m['name'];
        }
    }
    return $countryNames[$code] ?? $code;
};

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', 'name' => 'سامانه توسعه تجارت', 'alternateName' => 'Arad Branding', 'url' => $baseUrl . '/', 'logo' => $baseUrl . '/icons/icon-512.png'],
        ['@type' => 'WebSite', 'name' => 'سامانه توسعه تجارت', 'url' => $baseUrl . '/', 'inLanguage' => 'fa',
            'potentialAction' => ['@type' => 'SearchAction', 'target' => $baseUrl . '/search?q={q}', 'query-input' => 'required name=q']],
    ],
];
if ($show['faq'] && $faq !== []) {
    $schema['@graph'][] = ['@type' => 'FAQPage', 'mainEntity' => array_map(static fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)];
}
$desc = 'شبکه بین‌المللی تجار و فعالان اقتصادی: صفحه تجاری چندزبانه بسازید، فرصت‌های تجاری را کشف کنید و مستقیم با تجار کشورهای مختلف ارتباط بگیرید. عضویت رایگان است.';
$flagUse = static fn (string $code): string => '<svg class="th-flag" viewBox="0 0 30 20" aria-hidden="true"><use href="#flag-' . e($code) . '"/></svg>';
$flagOf = static fn (string $code): string => in_array($code, ['CN', 'IN', 'AE', 'TR', 'DE', 'RU', 'BR', 'IR', 'IQ', 'AF', 'KE', 'TZ', 'ZA', 'NG', 'US', 'CA', 'GB', 'NL'], true)
    ? $flagUse($code) : '<span class="th-flag th-flag-emoji" aria-hidden="true">' . flag($code) . '</span>';
$icon = static fn (string $id, string $cls = 'th-ic'): string => '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true"><use href="#' . e($id) . '"/></svg>';
$live = static fn (string $text): string => strtr(e($text), [
    '{sea}' => '<span data-live="sea">۷</span>', '{air}' => '<span data-live="air">۱۰</span>', '{nodes}' => '<span data-live="nodes">۱۹</span>',
]);
$h = $home['hero'];
?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="dark">
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
      <?php foreach ($home['nav'] as $i => $n): if ($n['href'] === '') { continue; } $isHome = $n['href'] === '/'; ?>
      <a class="<?= $isHome ? 'is-active' : '' ?><?= $i >= 5 ? ' th-nav-xl' : '' ?>" href="<?= e($n['href']) ?>"<?= $isHome ? ' aria-current="page"' : '' ?>><?= e($n['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="th-search" method="get" action="/search" role="search">
      <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
      <input type="search" name="q" placeholder="<?= e($home['header']['search_placeholder']) ?>" aria-label="جستجو" enterkeyhint="search">
    </form>
    <div class="th-actions">
      <span class="th-lang" title="زبان سامانه"><?= $icon('m-globe') ?>FA</span>
      <a class="th-btn th-btn-ghost" href="/login"><?= e($home['header']['login_label']) ?></a>
      <a class="th-btn th-btn-gold" href="/register"><?= e($home['header']['register_label']) ?></a>
      <details class="th-menu">
        <summary aria-label="منو"><?= $icon('m-menu') ?></summary>
        <div class="th-menu-panel">
          <form class="th-search th-search-m" method="get" action="/search" role="search">
            <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
            <input type="search" name="q" placeholder="<?= e($home['header']['search_placeholder']) ?>" aria-label="جستجو" enterkeyhint="search">
          </form>
          <nav aria-label="منوی موبایل">
            <?php foreach ($home['nav'] as $n): if ($n['href'] === '') { continue; } ?><a href="<?= e($n['href']) ?>"><?= $icon('m-arrow') ?><?= e($n['label']) ?></a><?php endforeach; ?>
          </nav>
          <div class="th-menu-cta"><a class="th-btn th-btn-ghost" href="/login"><?= e($home['header']['login_label']) ?></a><a class="th-btn th-btn-gold" href="/register"><?= e($home['header']['register_label']) ?></a></div>
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
    <?php if ($show['cards']): ?>
    <div class="tg-cards">
      <?php foreach ($globeMarkets as $m): ?>
      <a class="tg-card<?= $m['secondary'] ? ' is-secondary' : '' ?>" href="<?= e($marketHref($m['code'])) ?>" data-lat="<?= e($m['lat']) ?>" data-lon="<?= e($m['lon']) ?>" data-name="<?= e($m['name']) ?>" data-note="<?= e($marketNote($m['code'])) ?>">
        <?= $flagOf($m['code']) ?>
        <span class="tg-card-t"><b><?= e($m['name']) ?></b><small><?= e($marketNote($m['code'])) ?></small></span>
      </a>
      <?php endforeach; ?>
      <?php foreach ($routeCards as [$code, $name, $lat, $lon, $note]): ?>
      <a class="tg-card is-secondary is-route" href="<?= e($marketHref($code)) ?>" data-lat="<?= e($lat) ?>" data-lon="<?= e($lon) ?>" data-name="<?= e($name) ?>" data-note="<?= e($note) ?>"<?= in_array($code, ['GB', 'NL'], true) ? ' data-priority="0.5"' : '' ?>>
        <?= $flagOf($code) ?>
        <span class="tg-card-t"><b><?= e($name) ?></b><small><?= e($note) ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="tg-tip" role="status" hidden></div>
    </div>

    <div class="th-hero-in">
      <div class="th-hero-copy" data-tg-avoid>
        <?php if ($h['badge'] !== ''): ?><p class="th-badge"><?= $icon('m-globe') ?><?= e($h['badge']) ?></p><?php endif; ?>
        <h1 id="hero-title"><span class="th-h1-gold"><?= e($h['title']) ?></span><?php if ($h['subtitle'] !== ''): ?><span class="th-h1-sub"><?= e($h['subtitle']) ?></span><?php endif; ?></h1>
        <?php if ($h['lead'] !== ''): ?><p class="th-lead"><?= e($h['lead']) ?></p><?php endif; ?>
        <div class="th-cta">
          <?php if ($h['cta1_label'] !== '' && $h['cta1_href'] !== ''): ?><a class="th-btn th-btn-gold th-btn-lg" href="<?= e($h['cta1_href']) ?>"><?= e($h['cta1_label']) ?><?= $icon('m-arrow') ?></a><?php endif; ?>
          <?php if ($h['cta2_label'] !== '' && $h['cta2_href'] !== ''): ?><a class="th-btn th-btn-glass th-btn-lg" href="<?= e($h['cta2_href']) ?>"><span class="th-play"><?= $icon('m-play') ?></span><?= e($h['cta2_label']) ?></a><?php endif; ?>
        </div>
        <?php if ($h['note'] !== ''): ?><p class="th-note"><?= e($h['note']) ?></p><?php endif; ?>
      </div>
    </div>

    <?php if ($show['rail'] && $home['rail'] !== []): ?>
    <aside class="th-rail" data-tg-avoid aria-label="نمای زنده نقشه">
      <?php foreach ($home['rail'] as $r): ?>
      <div class="th-rail-i"><?= $icon($r['icon']) ?><span><small><?= e($r['label']) ?></small><b><?= $live($r['value']) ?></b></span></div>
      <?php endforeach; ?>
    </aside>
    <?php endif; ?>
    <?php if ($h['hint'] !== ''): ?><p class="th-hint" aria-hidden="true"><?= e($h['hint']) ?></p><?php endif; ?>
  </section>

  <?php if ($show['stats'] && $strip !== []): ?>
  <section class="th-stats" aria-label="<?= $stats !== null ? 'آمار سامانه' : 'سامانه در یک نگاه' ?>">
    <?php foreach ($strip as $s): ?>
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
  <?php endif; ?>

  <?php if ($show['finder'] || ($show['markets'] && $cardMarkets !== []) || ($show['opps'] && $home['opps'] !== [])): ?>
  <section class="th-discover<?= !$show['finder'] ? ' no-finder' : '' ?><?= !($show['markets'] && $cardMarkets !== []) ? ' no-markets' : '' ?><?= !($show['opps'] && $home['opps'] !== []) ? ' no-opps' : '' ?>" aria-label="کشف بازار">
    <?php if ($show['finder']): $f = $home['finder']; ?>
    <div class="th-finder th-glass">
      <div class="th-finder-h">
        <span class="th-ring"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-compass"/></svg></span>
        <div>
          <h2><?= e($f['title']) ?></h2>
          <?php if ($f['text'] !== ''): ?><p><?= e($f['text']) ?></p><?php endif; ?>
        </div>
      </div>
      <form class="th-finder-f" method="get" action="/search">
        <label class="th-field"><span>محصول یا کالا</span><input type="search" name="q" placeholder="مثلاً زعفران، خرما، فولاد…" enterkeyhint="search"></label>
        <div class="th-field"><span aria-hidden="true">کشور</span>
          <select name="country" aria-label="کشور">
            <option value="0">همه کشورها</option>
            <?php foreach ($finderCodes as $code): if (!isset($countryIds[$code])) { continue; } ?>
            <option value="<?= (int) $countryIds[$code] ?>"><?= e($nameOf($code)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <fieldset class="th-field th-seg">
          <legend>نوع نتیجه</legend>
          <div class="th-seg-in">
            <label><input type="radio" name="type" value="proposals" checked><span><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-chart"/></svg>فرصت‌ها</span></label>
            <label><input type="radio" name="type" value="traders"><span><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-people"/></svg>تجار</span></label>
            <label><input type="radio" name="type" value="pages"><span><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-page"/></svg>صفحه‌ها</span></label>
          </div>
        </fieldset>
        <button class="th-btn th-btn-gold th-btn-block" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><?= e($f['button']) ?></button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($show['markets'] && $cardMarkets !== []): ?>
    <div class="th-mk<?= count($cardMarkets) > 4 ? ' is-carousel' : '' ?>">
    <?php if (count($cardMarkets) > 4): ?>
    <button class="th-mk-nav th-mk-prev" type="button" data-mk="prev" aria-label="بازارهای قبلی"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-arrow"/></svg></button>
    <button class="th-mk-nav th-mk-next" type="button" data-mk="next" aria-label="بازارهای بعدی"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-arrow"/></svg></button>
    <?php endif; ?>
    <div class="th-markets n-<?= min(4, count($cardMarkets)) ?>" aria-label="بازارهای هدف" tabindex="0">
      <?php foreach ($cardMarkets as $m): $s = $marketStat($m['code']); ?>
      <a class="th-market" href="<?= e($marketHref($m['code'])) ?>">
        <span class="th-market-art">
          <?php if ($m['image'] !== ''): ?>
          <img src="<?= e(media($m['image'])) ?>" alt="" loading="lazy" decoding="async">
          <?php else: ?>
          <svg viewBox="0 0 240 150" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#sky-<?= isset($hasSky[$m['code']]) ? e($m['code']) : 'GEN' ?>"/></svg>
          <?php endif; ?>
          <span class="th-market-n"><?= $flagOf($m['code']) ?><b><?= e($m['name']) ?></b></span>
        </span>
        <span class="th-market-b">
          <small class="th-market-note"><?= $s !== null ? '+' . fa_int((int) $s['proposals']) . ' فرصت · ' . fa_int((int) $s['users']) . ' عضو' : 'تجار و فرصت‌های این بازار' ?></small>
          <span class="th-facts">
            <?php if ($m['port'] !== ''): ?><span><?= $icon('m-anchor', 'th-ic th-ic-sm') ?><i>بندر اصلی</i><b><?= e($m['port']) ?></b></span><?php endif; ?>
            <?php if ($m['currency'] !== ''): ?><span><?= $icon('m-coin', 'th-ic th-ic-sm') ?><i>واحد پول</i><b><?= e($m['currency']) ?></b></span><?php endif; ?>
            <?php if ($m['timezone'] !== ''): ?><span><?= $icon('m-clock', 'th-ic th-ic-sm') ?><i>منطقه زمانی</i><b dir="ltr"><?= e($m['timezone']) ?></b></span><?php endif; ?>
          </span>
          <span class="th-market-f">
            <?php if ($s !== null): ?><span class="th-bar"><i data-w="<?= (int) round(100 * (int) $s['proposals'] / $maxProposals) ?>"></i></span><?php else: ?><span class="th-bar th-bar-idle"><i></i></span><?php endif; ?>
            <span class="th-market-cta">مشاهده تجار</span>
            <span class="th-go"><?= $icon('m-arrow') ?></span>
          </span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($show['opps'] && $home['opps'] !== []): $oh = $home['opps_head']; ?>
    <div class="th-opps th-glass">
      <div class="th-opps-h"><h2><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-spark"/></svg><?= e($oh['title']) ?></h2><?php if ($oh['link_label'] !== '' && $oh['link_href'] !== ''): ?><a href="<?= e($oh['link_href']) ?>"><?= e($oh['link_label']) ?><?= $icon('m-arrow') ?></a><?php endif; ?></div>
      <?php if ($oh['subtitle'] !== ''): ?><p class="th-opps-s"><?= e($oh['subtitle']) ?></p><?php endif; ?>
      <ul>
        <?php foreach ($home['opps'] as $o): ?>
        <li><a href="<?= e($searchHref($o['product'], $o['code'])) ?>">
          <?php if ($o['image'] !== ''): ?><img class="th-opp-art" src="<?= e(media($o['image'])) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><svg class="th-opp-art" viewBox="0 0 80 56" aria-hidden="true"><use href="#<?= e($o['art']) ?>"/></svg><?php endif; ?>
          <span class="th-opp-t"><b><?= e($o['product']) ?><?php if ($o['market'] !== ''): ?> <?= $icon('m-arrow', 'th-ic th-ic-sm') ?> <?= e($o['market']) ?><?php endif; ?></b><small><?php if ($o['code'] !== ''): ?><?= $flagOf($o['code']) ?><?php endif; ?><?= e($o['tag']) ?> · <?= $icon($o['mode'] === 'air' ? 'm-plane' : 'm-ship', 'th-ic th-ic-sm') ?></small></span>
          <span class="th-go"><?= $icon('m-arrow') ?></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if (($show['modules'] && $home['modules'] !== []) || $show['banner']): ?>
  <section class="th-modules<?= !($show['modules'] && $home['modules'] !== []) ? ' no-modules' : '' ?><?= !$show['banner'] ? ' no-banner' : '' ?>" aria-label="بخش‌های سامانه">
    <?php if ($show['modules'] && $home['modules'] !== []): ?>
    <div class="th-mod-grid">
      <?php foreach ($home['modules'] as $m): if ($m['href'] === '') { continue; } ?>
      <a class="th-mod" href="<?= e($m['href']) ?>"><?= $icon($m['icon'], 'th-ic th-ic-lg') ?><b><?= e($m['title']) ?></b><?php if ($m['text'] !== ''): ?><small><?= e($m['text']) ?></small><?php endif; ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($show['banner']): $b = $home['banner']; ?>
    <a class="th-banner" href="<?= e($b['href'] !== '' ? $b['href'] : '#how') ?>">
      <?php if ($b['image'] !== ''): ?>
      <img class="th-banner-art" src="<?= e(media($b['image'])) ?>" alt="" loading="lazy" decoding="async">
      <?php else: ?>
      <svg class="th-banner-art" viewBox="0 0 600 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#banner-ship"/></svg>
      <?php endif; ?>
      <span class="th-banner-t"><b><?= e($b['title']) ?></b><?php if ($b['subtitle'] !== ''): ?><small><?= e($b['subtitle']) ?></small><?php endif; ?></span>
      <span class="th-banner-play"><?= $icon('m-play') ?></span>
    </a>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($show['how']): $hw = $home['how']; ?>
  <section class="th-sec" id="how">
    <header class="th-sec-h"><p class="th-kicker"><?= e($hw['kicker']) ?></p><h2><?= e($hw['title']) ?></h2><?php if ($hw['intro'] !== ''): ?><p><?= e($hw['intro']) ?></p><?php endif; ?></header>
    <?php if ($home['steps'] !== []): ?>
    <ol class="th-steps">
      <?php foreach ($home['steps'] as $st): ?><li><b><?= e($st['title']) ?></b><span><?= e($st['text']) ?></span></li><?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($show['features'] && $home['features'] !== []): $fh = $home['features_head']; ?>
  <section class="th-sec" id="features">
    <header class="th-sec-h"><p class="th-kicker"><?= e($fh['kicker']) ?></p><h2><?= e($fh['title']) ?></h2></header>
    <div class="th-features">
      <?php foreach ($home['features'] as $ft): ?>
      <article class="th-glass"><?= $icon($ft['icon'], 'th-ic th-ic-lg') ?><h3><?= e($ft['title']) ?></h3><p><?= e($ft['text']) ?></p></article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($show['stars']): $sr = $home['stars']; ?>
  <section class="th-sec th-stars" id="stars">
    <div>
      <p class="th-kicker"><?= e($sr['kicker']) ?></p>
      <h2><?= e($sr['title']) ?></h2>
      <?php if ($sr['text'] !== ''): ?><p><?= e($fill($sr['text'])) ?></p><?php endif; ?>
    </div>
    <?php if ($home['stars_items'] !== []): ?>
    <ul class="th-stars-list th-glass"><?php foreach ($home['stars_items'] as $it): ?><li><?= $icon('m-star') ?><?= e($it['text']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($show['countries'] && $chipCodes !== []): $cc = $home['countries']; ?>
  <section class="th-sec" id="countries">
    <header class="th-sec-h"><p class="th-kicker"><?= e($cc['kicker']) ?></p><h2><?= e($cc['title']) ?></h2></header>
    <div class="th-chips"><?php foreach ($chipCodes as $code): ?><a class="th-chip" href="<?= e($marketHref($code)) ?>"><span aria-hidden="true"><?= flag($code) ?></span><?= e($nameOf($code)) ?></a><?php endforeach; ?><?php if ($cc['more'] !== ''): ?><span class="th-chip"><?= e($cc['more']) ?></span><?php endif; ?></div>
  </section>
  <?php endif; ?>

  <?php if ($show['faq'] && $faq !== []): $fq = $home['faq_head']; ?>
  <section class="th-sec" id="faq">
    <header class="th-sec-h"><p class="th-kicker"><?= e($fq['kicker']) ?></p><h2><?= e($fq['title']) ?></h2></header>
    <div class="th-faq"><?php foreach ($faq as [$q, $a]): ?><details class="th-glass"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <?php if ($show['final']): $fn = $home['final']; ?>
  <section class="th-sec th-final">
    <div class="th-final-in th-glass">
      <h2><?= e($fn['title']) ?></h2>
      <?php if ($fn['text'] !== ''): ?><p><?= e($fn['text']) ?></p><?php endif; ?>
      <div class="th-cta th-cta-center">
        <?php if ($fn['cta1_label'] !== '' && $fn['cta1_href'] !== ''): ?><a class="th-btn th-btn-gold th-btn-lg" href="<?= e($fn['cta1_href']) ?>"><?= e($fn['cta1_label']) ?></a><?php endif; ?>
        <?php if ($fn['cta2_label'] !== '' && $fn['cta2_href'] !== ''): ?><a class="th-btn th-btn-glass th-btn-lg" href="<?= e($fn['cta2_href']) ?>"><?= e($fn['cta2_label']) ?></a><?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</main>

<footer class="th-foot">
  <div class="th-foot-in">
    <a class="th-brand" href="/"><span class="brand-mark th-mark"><svg class="icon"><use href="#i-mark"/></svg></span><span class="th-brand-name">سامانه توسعه تجارت<small>© <bdi>aradbranding.app</bdi></small></span></a>
    <nav aria-label="پیوندهای پایین صفحه"><a href="/register">عضویت</a><a href="/login">ورود</a><a href="/discover">بازارهای هدف</a><a href="/proposals">فرصت‌ها</a><?php if ($show['faq']): ?><a href="#faq">پرسش‌ها</a><?php endif; ?></nav>
  </div>
</footer>
</body>
</html>
