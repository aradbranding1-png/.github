<?php
/**
 * Sign-in / sign-up layout: "the gateway to global trade". A calm WebGL globe (trade-globe.js, data-mode="login")
 * on the visual left and a glass card on the right. The forms inside $content are the existing ones (same fields,
 * same routes); only the presentation changed.
 * @var string $content @var string $title
 */
$isRegister = str_contains($content, 'action="/register"');
$cards = [
    ['IR', 'ایران', 32.5, 53.7], ['AE', 'امارات', 24.0, 54.5], ['IN', 'هند', 22.0, 78.5],
    ['CN', 'چین', 33.5, 106.0], ['TR', 'ترکیه', 39.0, 35.0],
    ['IQ', 'عراق', 33.0, 43.5, true], ['AF', 'افغانستان', 34.0, 66.0, true],
];
?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="dark">
<head>
<?= $this->partial('partials/head') ?>
<link rel="stylesheet" href="<?= e(asset('trade-home.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('auth.css')) ?>">
<script src="<?= e(asset('auth.js')) ?>" defer></script>
<script type="module" src="<?= e(asset('trade-globe.js')) ?>"></script>
<title><?= e($title ?? '') ?> · سامانه توسعه تجارت</title>
<meta name="robots" content="noindex">
</head>
<body class="th auth<?= $isRegister ? ' auth-register' : '' ?>">
<?= $this->partial('partials/icons') ?>
<?= $this->partial('public/_trade_sprite') ?>
<a class="skip" href="#auth-card">پرش به فرم</a>

<div class="auth-bg" aria-hidden="true"></div>

<section class="auth-globe" data-trade-globe data-mode="login" data-tex="/assets/globe/" aria-hidden="true">
  <div class="tg-scene">
    <div class="tg-stage"><div class="tg-poster"><div class="tg-poster-globe"></div></div></div>
    <svg class="tg-lines"></svg>
    <div class="tg-labels"></div>
    <div class="tg-cards">
      <?php foreach ($cards as $card): [$code, $name, $lat, $lon] = $card; $secondary = !empty($card[4]); ?>
      <span class="tg-card<?= $code === 'IR' ? ' is-home' : '' ?><?= $secondary ? ' is-secondary is-land' : '' ?>" data-lat="<?= e($lat) ?>" data-lon="<?= e($lon) ?>" data-name="<?= e($name) ?>" data-note="<?= $secondary ? 'مسیر حمل زمینی از ایران' : 'بازار هدف' ?>">
        <svg class="th-flag" viewBox="0 0 30 20" aria-hidden="true"><use href="#flag-<?= e($code) ?>"/></svg>
        <span class="tg-card-t"><b><?= e($name) ?></b></span>
      </span>
      <?php endforeach; ?>
    </div>
    <div class="tg-tip" hidden></div>
  </div>
</section>

<header class="auth-top">
  <a class="auth-brand" href="/" aria-label="سامانه توسعه تجارت · صفحه اصلی">
    <span class="brand-mark th-mark"><svg class="icon"><use href="#i-mark"/></svg></span>
    <span class="th-brand-name">سامانه توسعه تجارت<small>شبکه بین‌المللی تجار</small></span>
  </a>
  <a class="auth-back" href="/">بازگشت به سایت<svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-arrow"/></svg></a>
</header>

<main class="auth-main">
  <div class="auth-card" id="auth-card">
    <div class="auth-card-brand">
      <span class="brand-mark th-mark"><svg class="icon"><use href="#i-mark"/></svg></span>
      <span class="th-brand-name">سامانه توسعه تجارت<small>شبکه بین‌المللی تجار</small></span>
    </div>
    <?= $this->partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
    <?= $content ?>
  </div>
</main>

<footer class="auth-foot">
  <p class="auth-slogan"><span>تجارت جهانی،</span><b>یک قدم نزدیک‌تر</b></p>
</footer>
</body>
</html>
