<?php /** @var array $old @var string $next @var string|null $error @var bool $googleEnabled */ ?>
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
<?php if (!empty($googleEnabled)): ?>
<div class="auth-sep"><span><?= te('یا') ?></span></div>
<a class="auth-google" href="/auth/google<?= $next !== '/dashboard' ? '?next=' . e(rawurlencode($next)) : '' ?>">
  <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
  <span><?= te('ورود با گوگل') ?></span>
</a>
<p class="auth-hint"><?= te('رمز عبور را فراموش کرده‌اید؟ اگر جیمیل خود را در پروفایل ثبت کرده‌اید، با «ورود با گوگل» وارد شوید و رمز تازه بگذارید.') ?></p>
<?php endif; ?>
<div class="auth-sep"><span><?= te('حساب کاربری ندارید؟') ?></span></div>
<a class="auth-outline" href="/register"><svg class="th-ic" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-user"/></svg><?= te('عضویت در سامانه') ?></a>
