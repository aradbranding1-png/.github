<?php /** @var array $old @var string $next @var string|null $error */ ?>
<h1 class="auth-title"><?= te('ورود به سامانه توسعه تجارت') ?></h1>
<p class="auth-lead"><?= te('به مرکز تجارت جهانی آراد خوش آمدید') ?></p>
<?php if ($error): ?><div class="auth-alert" role="alert" id="login-error"><?= e($error) ?></div><?php endif; ?>
<form class="auth-form" method="post" action="/login" novalidate data-auth-form>
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <label class="auth-field<?= $error ? ' has-error' : '' ?>" for="f-email">
    <svg class="auth-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-letter"/></svg>
    <span class="auth-field-body">
      <span class="auth-label"><?= te('ایمیل یا شماره موبایل') ?></span>
      <input id="f-email" name="email" type="text" inputmode="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required dir="ltr" autofocus placeholder="name@company.com · 0912…"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>>
    </span>
  </label>
  <label class="auth-field<?= $error ? ' has-error' : '' ?>" for="f-password">
    <svg class="auth-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-lock"/></svg>
    <span class="auth-field-body">
      <span class="auth-label"><?= te('رمز عبور') ?></span>
      <input id="f-password" name="password" type="password" autocomplete="current-password" required dir="ltr" placeholder="••••••••"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>>
    </span>
    <button class="auth-eye" type="button" data-toggle-password="f-password" aria-label="<?= te('نمایش رمز عبور') ?>" aria-pressed="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
  </label>
  <label class="auth-check"><input type="checkbox" name="remember" value="1" checked><span class="auth-box" aria-hidden="true"></span><?= te('مرا به خاطر بسپار') ?></label>
  <button class="auth-submit" type="submit" data-busy="<?= te('در حال ورود…') ?>"><span><?= te('ورود به سامانه') ?></span><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#m-arrow"/></svg></button>
</form>
<div class="auth-sep"><span><?= te('حساب کاربری ندارید؟') ?></span></div>
<a class="auth-outline" href="/register"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-user"/></svg><?= te('عضویت در سامانه') ?></a>
