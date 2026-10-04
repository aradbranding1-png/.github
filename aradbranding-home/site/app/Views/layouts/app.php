<?php
/** @var string $content @var array $user @var string $title @var string $path @var array $perms */
$perms = $perms ?? [];
$unreadPrivate = (int) ($user['unread_private'] ?? 0);
$letterType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$nav = [
    ['href' => '/dashboard', 'label' => t('خانه'), 'icon' => 'home'],
    ['href' => '/letters?type=private', 'label' => t('ارتباطات اختصاصی'), 'icon' => 'lock', 'badge' => $unreadPrivate, 'key' => 'private', 'side' => true],
    ['href' => '/discover', 'label' => t('کشف'), 'icon' => 'compass'],
    ['href' => '/proposals', 'label' => t('پیشنهادات'), 'icon' => 'spark'],
    ['href' => '/letters', 'label' => t('ارتباطات'), 'icon' => 'chat', 'badge' => (int) ($user['unread_letters'] ?? 0) + (int) ($user['unread_official'] ?? 0)],
    ['href' => '/pages', 'label' => t('پیج من'), 'icon' => 'page'],
];
// Same items and permissions as admin/_nav.php (the in-page admin menu, now shown here in the sidebar).
$has = static fn (string ...$codes): bool => array_intersect($codes, array_keys($perms)) !== [];
$adminNav = array_values(array_filter([
    ['href' => '/admin', 'label' => t('داشبورد مدیریت'), 'icon' => 'home', 'ok' => $has('reports.view', 'settings.manage', 'users.view')],
    ['href' => '/admin/users', 'label' => t('کاربران'), 'icon' => 'user', 'ok' => $has('users.view')],
    ['href' => '/admin/content', 'label' => t('محتوا و بررسی'), 'icon' => 'page', 'ok' => $has('pages.view', 'pages.approve', 'proposals.view', 'proposals.moderate')],
    ['href' => '/admin/trust', 'label' => t('گزارش‌های تخلف'), 'icon' => 'lock', 'ok' => $has('letters.moderate', 'proposals.moderate', 'pages.approve', 'users.edit')],
    ['href' => '/admin/finance', 'label' => t('مالی'), 'icon' => 'star', 'ok' => $has('payments.view', 'wallet.view')],
    ['href' => '/admin/wallet', 'label' => t('کیف پول مشتریان'), 'icon' => 'star', 'ok' => $has('wallet.credit', 'wallet.debit')],
    ['href' => '/admin/reports', 'label' => t('گزارش‌ها'), 'icon' => 'spark', 'ok' => $has('reports.view')],
    ['href' => '/admin/exports', 'label' => t('خروجی Excel'), 'icon' => 'archive', 'ok' => $has('reports.export')],
    ['href' => '/admin/roles', 'label' => t('نقش‌ها و دسترسی‌ها'), 'icon' => 'lock', 'ok' => $has('roles.manage')],
    ['href' => '/admin/audit', 'label' => t('رویدادهای امنیتی'), 'icon' => 'eye', 'ok' => $has('audit.view')],
    ['href' => '/admin/settings', 'label' => t('تنظیمات'), 'icon' => 'gear', 'ok' => $has('settings.manage')],
    ['href' => '/admin/home', 'label' => t('صفحه اصلی سایت'), 'icon' => 'link', 'ok' => $has('settings.manage')],
    ['href' => '/admin/languages', 'label' => t('زبان‌ها'), 'icon' => 'compass', 'ok' => $has('i18n.manage')],
    ['href' => '/admin/api', 'label' => t('اتصال API'), 'icon' => 'route', 'ok' => $has('settings.manage')],
    ['href' => '/admin/backups', 'label' => t('نسخه‌های پشتیبان'), 'icon' => 'archive', 'ok' => $has('backup.manage')],
    ['href' => '/admin/system-update', 'label' => t('بروزرسانی سامانه'), 'icon' => 'route', 'ok' => $has('updates.manage')],
], static fn (array $i): bool => $i['ok']));

$isActive = static function (string $href) use ($path, $letterType): bool {
    if ($href === '/letters?type=private') {
        return $path === '/letters' && $letterType === 'private';
    }
    if ($href === '/letters' && $path === '/letters' && $letterType === 'private') {
        return false;
    }
    if ($href === '/discover' && $path === '/search') {
        return true;
    }
    if ($href === '/letters' && (str_starts_with($path, '/connections') || $path === '/updates')) {
        return true;
    }
    if ($href === '/admin') {
        return $path === '/admin';
    }
    if ($href === '/admin/settings' || $href === '/admin/system-update') {
        return $path === $href || ($href === '/admin/system-update' && $path === '/updates');
    }
    return $path === $href || str_starts_with($path, $href . '/');
};
$avatarUrl = media($user['avatar_path'] ?? null);
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$roleName = t(\App\Modules\Users\TradeRoles::title($user['role_slug'] ?? null, $user['trade_role'] ?? null));
$unreadNotes = (int) ($user['unread_notifications'] ?? 0);
$today = '';
if (!\App\Core\I18n\I18n::isSource()) {
    $today = \App\Core\I18n\I18n::today();
} elseif (class_exists(\IntlDateFormatter::class)) {
    $tz = new \DateTimeZone((string) \App\Core\Env::get('DISPLAY_TIMEZONE', 'Asia/Tehran'));
    $fmt = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, \IntlDateFormatter::TRADITIONAL, 'EEEE d MMMM y');
    $today = (string) $fmt->format(new \DateTimeImmutable('now', $tz));
}
$badge = static fn (int $n): string => $n > 0
    ? '<em class="badge" aria-label="' . te(':n خوانده‌نشده', ['n' => fa_num($n)]) . '">' . e(fa_num(min(99, $n))) . ($n > 99 ? '+' : '') . '</em>' : '';
$link = static function (array $item) use ($isActive, $badge): string {
    $current = $isActive($item['href']) ? ' aria-current="page"' : '';
    return '<a href="' . e($item['href']) . '"' . $current . (isset($item['key']) ? ' class="nav-' . e($item['key']) . '"' : '') . '>'
        . '<svg class="icon"><use href="#i-' . e($item['icon']) . '"/></svg><span class="nav-label">' . e($item['label']) . '</span>'
        . $badge((int) ($item['badge'] ?? 0)) . '</a>';
};
$renderNav = static function () use ($nav, $adminNav, $link): string {
    $html = '<nav class="side-nav" aria-label="' . te('منوی اصلی') . '">' . implode('', array_map($link, $nav)) . '</nav>';
    if ($adminNav !== []) {
        $html .= '<p class="side-group">' . te('مدیریت سامانه') . '</p><nav class="side-nav" aria-label="' . te('مدیریت سامانه') . '">' . implode('', array_map($link, $adminNav)) . '</nav>';
    }
    return $html;
};
$renderFoot = static function () use ($isActive): string {
    return '<a href="/reports"' . ($isActive('/reports') ? ' aria-current="page"' : '') . '><svg class="icon"><use href="#i-chart"/></svg><span class="nav-label">' . te('گزارش‌های من') . '</span></a>'
        . '<a href="/wallet"' . ($isActive('/wallet') ? ' aria-current="page"' : '') . '><svg class="icon"><use href="#i-star"/></svg><span class="nav-label">' . te('کیف پول Stars') . '</span></a>'
        . '<form method="post" action="/logout">' . csrf_field() . '<button class="side-logout" type="submit"><svg class="icon"><use href="#i-logout"/></svg><span class="nav-label">' . te('خروج') . '</span></button></form>';
};
$avatar = static function (string $cls) use ($avatarUrl, $user): string {
    return $avatarUrl
        ? '<img class="' . e($cls) . '" src="' . e($avatarUrl) . '" alt="">'
        : '<span class="' . e($cls) . '">' . e(initials($user['first_name'] ?? '', $user['last_name'] ?? '')) . '</span>';
};
?>
<!doctype html>
<html <?= \App\Core\I18n\I18n::htmlAttrs() ?> data-default-theme="system">
<head>
<?= \App\Core\I18n\I18n::headScript() ?><?= $this->partial('partials/head') ?>
<link rel="stylesheet" href="<?= e(asset('panel-theme.css')) ?>">
<script src="<?= e(asset('panel.js')) ?>" defer></script>
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? '') ?> · <?= te('سامانه توسعه تجارت') ?></title>
</head>
<body class="panel-ui">
<?= $this->partial('partials/icons') ?>
<a class="skip" href="#main"><?= te('پرش به محتوای اصلی') ?></a>
<div class="app-shell">
  <aside class="sidebar" aria-label="<?= te('منوی اصلی') ?>">
    <a class="brand" href="/dashboard">
      <span class="brand-mark has-logo"><img src="/assets/brand/logo-192.webp?v=4" alt="" width="48" height="48" decoding="async"></span>
      <span class="brand-name"><?= te('آراد برندینگ') ?><small><?= te('سامانه توسعه تجارت') ?></small></span>
    </a>
    <div class="side-scroll"><?= $renderNav() ?></div>
    <div class="side-foot side-nav"><?= $renderFoot() ?></div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <details class="nav-drawer">
        <summary class="icon-btn" aria-label="<?= te('منو') ?>"><svg class="icon" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg><?php if ($unreadPrivate > 0): ?><i class="dot-badge" aria-hidden="true"></i><?php endif; ?></summary>
        <div class="drawer-panel">
          <div class="drawer-head">
            <span class="brand-mark has-logo"><img src="/assets/brand/logo-192.webp?v=4" alt="" width="48" height="48" decoding="async"></span>
            <span class="brand-name"><?= te('آراد برندینگ') ?><small><?= e($fullName) ?></small></span>
            <button class="drawer-close" type="button" aria-label="<?= te('بستن منو') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
          </div>
          <div class="side-scroll"><?= $renderNav() ?></div>
          <div class="side-nav drawer-foot"><?= $renderFoot() ?></div>
        </div>
      </details>
      <div class="topbar-title">
        <h1><?= e($title ?? '') ?></h1>
        <?php if ($today !== ''): ?><span class="topbar-date"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5.5" width="16" height="14" rx="3"/><path d="M4 10h16M9 3.5v4M15 3.5v4"/></svg><?= e($today) ?></span><?php endif; ?>
      </div>
      <form class="search-bar topbar-search" method="get" action="/search" role="search"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><input class="input" type="search" name="q" placeholder="<?= te('جستجوی تاجر، کشور، محصول یا فرصت…') ?>" aria-label="<?= te('جستجو') ?>" enterkeyhint="search"></form>
      <div class="topbar-tools">
        <details class="notif" data-peek="/notifications/peek">
          <summary class="icon-btn bell" aria-label="<?= $unreadNotes > 0 ? te('اعلان‌ها (:n جدید)', ['n' => fa_num($unreadNotes)]) : te('اعلان‌ها') ?>"><svg class="icon"><use href="#i-bell"/></svg><?php if ($unreadNotes > 0): ?><em class="badge"><?= e(fa_num(min(99, $unreadNotes))) ?></em><?php endif; ?></summary>
          <div class="notif-panel" role="dialog" aria-label="<?= te('اعلان‌ها') ?>">
            <div class="notif-head"><b><?= te('اعلان‌ها') ?></b><a href="/notifications"><?= te('مشاهده همه') ?></a></div>
            <div class="notif-body" data-peek-body><div class="np-empty"><span class="np-spin" aria-hidden="true"></span><p><?= te('در حال دریافت…') ?></p></div></div>
            <a class="notif-all" href="/notifications"><?= te('همه اعلان‌ها') ?></a>
          </div>
        </details>
        <?= $this->partial('partials/lang_switch', ['variant' => 'panel']) ?>
        <details class="theme-pick">
          <summary class="icon-btn theme-btn" aria-label="<?= te('حالت نمایش') ?>" title="<?= te('حالت نمایش') ?>"><svg class="icon"><use href="#i-theme"/></svg><i class="theme-auto" aria-hidden="true">A</i></summary>
          <div class="theme-panel" role="menu" aria-label="<?= te('حالت نمایش') ?>">
            <b class="theme-panel-h"><?= te('حالت نمایش') ?></b>
            <button type="button" role="menuitemradio" data-theme-set="system"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z"/></svg><span><?= te('خودکار') ?><small><?= te('روز روشن، شب تیره') ?></small></span></button>
            <button type="button" role="menuitemradio" data-theme-set="light"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/></svg><span><?= te('روشن') ?></span></button>
            <button type="button" role="menuitemradio" data-theme-set="dark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg><span><?= te('تیره') ?></span></button>
          </div>
        </details>
        <details class="profile">
          <summary aria-label="<?= te('منوی حساب کاربری') ?>">
            <?= $avatar('avatar') ?>
            <span class="profile-text"><b><?= e($fullName) ?></b><small><?= e($roleName) ?></small></span>
            <svg class="profile-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="profile-panel" role="menu">
            <div class="profile-card"><?= $avatar('avatar avatar-lg') ?><span><b><?= e($fullName) ?></b><small><?= $user['handle'] ? '<bdi>/p/' . e($user['handle']) . '</bdi>' : e($roleName) ?></small></span></div>
            <a href="/account" role="menuitem"><svg class="icon"><use href="#i-user"/></svg><?= te('حساب کاربری') ?></a>
            <a href="/wallet" role="menuitem"><svg class="icon"><use href="#i-star"/></svg><?= te('کیف پول Stars') ?></a>
            <a href="/account/safety" role="menuitem"><svg class="icon"><use href="#i-lock"/></svg><?= te('حریم و امنیت') ?></a>
            <?php if (($user['role_slug'] ?? '') === 'super_admin'): ?><a href="/account/api" role="menuitem"><svg class="icon"><use href="#i-route"/></svg><?= te('API و کلید دسترسی') ?></a><?php endif; ?>
            <?php if (!empty($isStaff)): ?><a href="/admin" role="menuitem"><svg class="icon"><use href="#i-gear"/></svg><?= te('مدیریت سامانه') ?></a><?php endif; ?>
            <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" role="menuitem"><svg class="icon"><use href="#i-logout"/></svg><?= te('خروج') ?></button></form>
          </div>
        </details>
      </div>
    </header>
    <main id="main" class="content">
      <?php if (!empty($impersonating)): ?>
        <form class="alert alert-error impersonation-bar" method="post" action="/admin/impersonate/stop" role="status">
          <?= csrf_field() ?>
          <span>⚠️ <?= th('شما با حساب «:name» وارد شده‌اید (حالت مدیر). همه کارها ثبت می‌شود.', ['name' => e($fullName)]) ?></span>
          <button class="btn btn-sm" type="submit"><?= te('بازگشت به حساب مدیر') ?></button>
        </form>
      <?php endif; ?>
      <?= $this->partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
      <?= $content ?>
    </main>
  </div>
</div>

<nav class="bottom-nav" aria-label="<?= te('منوی پایین') ?>">
  <?php foreach ($nav as $item): if (!empty($item['side'])) { continue; } $b = (int) ($item['badge'] ?? 0); if ($item['href'] === '/letters') { $b += $unreadPrivate; } ?>
    <a href="<?= e($item['href']) ?>"<?= $isActive($item['href']) ? ' aria-current="page"' : '' ?>><span class="bn-icon"><svg class="icon"><use href="#i-<?= e($item['icon']) ?>"/></svg><?php if ($b > 0): ?><em class="dot-badge"></em><?php endif; ?></span><?= e($item['label']) ?></a>
  <?php endforeach; ?>
</nav>

<details class="fab">
  <summary aria-label="<?= te('ساختن') ?>"><svg class="icon"><use href="#i-plus"/></svg></summary>
  <div class="fab-sheet" role="menu">
    <a href="/pages/new" role="menuitem"><svg class="icon"><use href="#i-page"/></svg><?= te('ساخت صفحه تجاری') ?></a>
    <a href="/proposals/new" role="menuitem"><svg class="icon"><use href="#i-spark"/></svg><?= te('ساخت پیشنهاد تجاری') ?></a>
    <a href="/letters/send" role="menuitem"><svg class="icon"><use href="#i-letter"/></svg><?= te('ارسال نامه') ?></a>
  </div>
</details>
</body>
</html>
