<?php
/**
 * Sign-in / sign-up layout: "the gateway to global trade". A calm WebGL globe (trade-globe.js, data-mode="login")
 * on the visual left and a glass card on the right. The forms inside $content are the existing ones (same fields,
 * same routes); only the presentation changed.
 * @var string $content @var string $title
 */
$isRegister = str_contains($content, 'action="/register"');
// Same globe cards as the home page: the admin's globe markets plus every country reached by a drawn route.
$cards = \App\Modules\System\GlobeCards::build(\App\Modules\System\GlobeCards::globeMarkets(
    (new \App\Modules\System\HomeContent(\App\Core\Container::instance()->get(\App\Core\Settings\Settings::class)))->get()
));
?>
<!doctype html>
<html <?= \App\Core\I18n\I18n::htmlAttrs() ?> data-theme="dark">
<head>
<?= \App\Core\I18n\I18n::headScript() ?><?= $this->partial('partials/head') ?>
<link rel="stylesheet" href="<?= e(asset('trade-home.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('auth.css')) ?>">
<script src="<?= e(asset('auth.js')) ?>" defer></script>
<script type="module" src="<?= e(asset('trade-globe.js')) ?>"></script>
<title><?= e($title ?? '') ?> · <?= te('سامانه توسعه تجارت') ?></title>
<meta name="robots" content="noindex">
</head>
<body class="th auth<?= $isRegister ? ' auth-register' : '' ?>">
<?= $this->partial('partials/icons') ?>
<?= $this->partial('public/_trade_sprite') ?>
<a class="skip" href="#auth-card"><?= te('پرش به فرم') ?></a>

<div class="auth-bg" aria-hidden="true"></div>

<section class="auth-globe" data-trade-globe data-mode="login" data-tex="/assets/globe/" aria-hidden="true">
  <div class="tg-scene">
    <div class="tg-stage"><div class="tg-poster"><div class="tg-poster-globe"></div></div></div>
    <svg class="tg-lines"></svg>
    <div class="tg-labels"></div>
    <div class="tg-cards">
<?= $this->partial('public/_globe_cards', ['cards' => $cards]) ?>
    </div>
    <div class="tg-tip" hidden></div>
  </div>
</section>

<header class="auth-top">
  <a class="auth-brand" href="/" aria-label="<?= te('سامانه توسعه تجارت · صفحه اصلی') ?>">
    <span class="brand-mark th-mark has-logo"><img src="/assets/brand/logo-192.webp?v=6" alt="" width="48" height="48" decoding="async"></span>
    <span class="th-brand-name"><?= te('سامانه توسعه تجارت') ?><small><?= te('شبکه بین‌المللی تجار') ?></small></span>
  </a>
  <?= $this->partial('partials/lang_switch', ['variant' => 'auth']) ?>
  <a class="auth-back" href="/"><?= te('بازگشت به سایت') ?><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-arrow"/></svg></a>
</header>

<main class="auth-main">
  <div class="auth-card" id="auth-card">
    <div class="auth-card-brand">
      <span class="brand-mark th-mark has-logo"><img src="/assets/brand/logo-192.webp?v=6" alt="" width="48" height="48" decoding="async"></span>
      <span class="th-brand-name"><?= te('سامانه توسعه تجارت') ?><small><?= te('شبکه بین‌المللی تجار') ?></small></span>
    </div>
    <?= $this->partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
    <?= $content ?>
  </div>
</main>

<footer class="auth-foot">
  <p class="auth-slogan"><span><?= te('تجارت جهانی،') ?></span><b><?= te('یک قدم نزدیک‌تر') ?></b></p>
</footer>
</body>
</html>
