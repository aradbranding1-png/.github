<?php
/** @var string $content @var array $page @var array $alternates @var string $handle @var string $baseUrl @var array $labels @var array|null $viewer */
$canonical = $baseUrl . '/p/' . $handle . '/' . $page['lang_code'];
$titleText = $page['title'] . ($page['company_name'] ? ' · ' . $page['company_name'] : '');
$desc = \App\Core\Support\Str::excerpt(plain_text($page['teaser']), 160);
$image = $page['cover_path'] ? $baseUrl . media($page['cover_path']) : ($page['avatar_path'] ? $baseUrl . media($page['avatar_path']) : null);
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => $page['company_name'] ?: $page['title'],
    'description' => $desc,
    'url' => $canonical,
    'address' => ['@type' => 'PostalAddress', 'addressCountry' => $page['country_code']],
];
if ($page['avatar_path']) { $schema['logo'] = $baseUrl . media($page['avatar_path']); }
// The site name follows the page's language when the site speaks it (this part is cached publicly, so not the visitor's).
$brandLang = isset(\App\Core\I18n\I18n::LOCALES[$page['lang_code']]) ? (string) $page['lang_code'] : 'fa';
?>
<!doctype html>
<html lang="<?= e($page['lang_code']) ?>" dir="<?= e($page['direction']) ?>">
<head>
<?= $this->partial('partials/head') ?>
<title><?= e($titleText) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<?php foreach ($alternates as $code): ?>
<link rel="alternate" hreflang="<?= e($code) ?>" href="<?= e($baseUrl . '/p/' . $handle . '/' . $code) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= e($baseUrl . '/p/' . $handle) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= e($titleText) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:locale" content="<?= e($page['lang_code']) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= e($image) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $image ? 'summary_large_image' : 'summary' ?>">
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="pub-teal">
<?= $this->partial('partials/icons') ?>
<header class="pub-top">
  <a class="brand" href="/" dir="<?= e(\App\Core\I18n\I18n::LOCALES[$brandLang]['dir']) ?>" lang="<?= e($brandLang) ?>">
    <span class="brand-mark has-logo"><img src="/assets/brand/logo-192.webp?v=4" alt="" width="48" height="48" decoding="async"></span>
    <span class="brand-name"><?= e(\App\Core\I18n\I18n::lookup('سامانه توسعه تجارت', $brandLang)) ?></span>
  </a>
  <div class="form-actions">
    <button class="icon-btn" type="button" data-theme-toggle aria-label="Theme"><svg class="icon"><use href="#i-theme"/></svg></button>
    <?php if ($viewer): ?>
      <a class="btn btn-ghost btn-sm" href="/dashboard" lang="<?= e(locale()) ?>"><?= te('داشبورد') ?></a>
    <?php else: ?>
      <a class="btn btn-sm" href="/login?next=<?= e(rawurlencode('/p/' . $handle . '/' . $page['lang_code'])) ?>"><?= e($labels['login']) ?></a>
    <?php endif; ?>
  </div>
</header>
<?= $content ?>
</body>
</html>
