<?php
/** @var string $content @var array $user @var string $title @var string $path @var array $perms */
$perms = $perms ?? [];
$unreadPrivate = (int) ($user['unread_private'] ?? 0);
$letterType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$nav = [
    ['href' => '/dashboard', 'label' => 'خانه', 'icon' => 'home'],
    ['href' => '/letters?type=private', 'label' => 'ارتباطات اختصاصی', 'icon' => 'lock', 'badge' => $unreadPrivate, 'key' => 'private', 'side' => true],
    ['href' => '/discover', 'label' => 'کشف', 'icon' => 'compass'],
    ['href' => '/proposals', 'label' => 'پیشنهادات', 'icon' => 'spark'],
    ['href' => '/letters', 'label' => 'ارتباطات', 'icon' => 'chat', 'badge' => (int) ($user['unread_letters'] ?? 0) + (int) ($user['unread_official'] ?? 0)],
    ['href' => '/pages', 'label' => 'پیج من', 'icon' => 'page'],
];
// Same items and permissions as admin/_nav.php (the in-page admin menu, now shown here in the sidebar).
$has = static fn (string ...$codes): bool => array_intersect($codes, array_keys($perms)) !== [];
$adminNav = array_values(array_filter([
    ['href' => '/admin', 'label' => 'داشبورد مدیریت', 'icon' => 'home', 'ok' => $has('reports.view', 'settings.manage', 'users.view')],
    ['href' => '/admin/users', 'label' => 'کاربران', 'icon' => 'user', 'ok' => $has('users.view')],
    ['href' => '/admin/content', 'label' => 'محتوا و بررسی', 'icon' => 'page', 'ok' => $has('pages.view', 'pages.approve', 'proposals.view', 'proposals.moderate')],
    ['href' => '/admin/finance', 'label' => 'مالی', 'icon' => 'star', 'ok' => $has('payments.view', 'wallet.view')],
    ['href' => '/admin/reports', 'label' => 'گزارش‌ها', 'icon' => 'spark', 'ok' => $has('reports.view')],
    ['href' => '/admin/exports', 'label' => 'خروجی Excel', 'icon' => 'archive', 'ok' => $has('reports.export')],
    ['href' => '/admin/roles', 'label' => 'نقش‌ها و دسترسی‌ها', 'icon' => 'lock', 'ok' => $has('roles.manage')],
    ['href' => '/admin/audit', 'label' => 'رویدادهای امنیتی', 'icon' => 'eye', 'ok' => $has('audit.view')],
    ['href' => '/admin/settings', 'label' => 'تنظیمات', 'icon' => 'gear', 'ok' => $has('settings.manage')],
    ['href' => '/admin/home', 'label' => 'صفحه اصلی سایت', 'icon' => 'link', 'ok' => $has('settings.manage')],
    ['href' => '/admin/backups', 'label' => 'نسخه‌های پشتیبان', 'icon' => 'archive', 'ok' => $has('backup.manage')],
    ['href' => '/admin/system-update', 'label' => 'بروزرسانی سامانه', 'icon' => 'route', 'ok' => $has('updates.manage')],
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
$roleName = (string) ($user['role_name'] ?? '') !== '' ? (string) $user['role_name'] : (!empty($isStaff) ? 'کارشناس سامانه' : 'عضو سامانه');
$unreadNotes = (int) ($user['unread_notifications'] ?? 0);
$today = '';
if (class_exists(\IntlDateFormatter::class)) {
    $tz = new \DateTimeZone((string) \App\Core\Env::get('DISPLAY_TIMEZONE', 'Asia/Tehran'));
    $fmt = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, \IntlDateFormatter::TRADITIONAL, 'EEEE d MMMM y');
    $today = (string) $fmt->format(new \DateTimeImmutable('now', $tz));
}
$badge = static fn (int $n): string => $n > 0
    ? '<em class="badge" aria-label="' . e(fa_num($n)) . ' خوانده‌نشده">' . e(fa_num(min(99, $n))) . ($n > 99 ? '+' : '') . '</em>' : '';
$link = static function (array $item) use ($isActive, $badge): string {
    $current = $isActive($item['href']) ? ' aria-current="page"' : '';
    return '<a href="' . e($item['href']) . '"' . $current . (isset($item['key']) ? ' class="nav-' . e($item['key']) . '"' : '') . '>'
        . '<svg class="icon"><use href="#i-' . e($item['icon']) . '"/></svg><span class="nav-label">' . e($item['label']) . '</span>'
        . $badge((int) ($item['badge'] ?? 0)) . '</a>';
};
$renderNav = static function () use ($nav, $adminNav, $link): string {
    $html = '<nav class="side-nav" aria-label="منوی اصلی">' . implode('', array_map($link, $nav)) . '</nav>';
    if ($adminNav !== []) {
        $html .= '<p class="side-group">مدیریت سامانه</p><nav class="side-nav" aria-label="مدیریت سامانه">' . implode('', array_map($link, $adminNav)) . '</nav>';
    }
    return $html;
};
$renderFoot = static function () use ($isActive): string {
    return '<a href="/" target="_blank" rel="noopener"><svg class="icon"><use href="#i-link"/></svg><span class="nav-label">مشاهده سایت</span></a>'
        . '<a href="/wallet"' . ($isActive('/wallet') ? ' aria-current="page"' : '') . '><svg class="icon"><use href="#i-star"/></svg><span class="nav-label">کیف پول Stars</span></a>'
        . '<form method="post" action="/logout">' . csrf_field() . '<button class="side-logout" type="submit"><svg class="icon"><use href="#i-logout"/></svg><span class="nav-label">خروج</span></button></form>';
};
$avatar = static function (string $cls) use ($avatarUrl, $user): string {
    return $avatarUrl
        ? '<img class="' . e($cls) . '" src="' . e($avatarUrl) . '" alt="">'
        : '<span class="' . e($cls) . '">' . e(initials($user['first_name'] ?? '', $user['last_name'] ?? '')) . '</span>';
};
?>
<!doctype html>
<html lang="fa" dir="rtl" data-default-theme="system">
<head>
<?= $this->partial('partials/head') ?>
<link rel="stylesheet" href="<?= e(asset('panel-theme.css')) ?>">
<script src="<?= e(asset('panel.js')) ?>" defer></script>
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? '') ?> · سامانه توسعه تجارت</title>
</head>
<body class="panel-ui">
<?= $this->partial('partials/icons') ?>
<a class="skip" href="#main">پرش به محتوای اصلی</a>
<div class="app-shell">
  <aside class="sidebar" aria-label="منوی اصلی">
    <a class="brand" href="/dashboard">
      <span class="brand-mark"><svg class="icon"><use href="#i-mark"/></svg></span>
      <span class="brand-name">آراد برندینگ<small>سامانه توسعه تجارت</small></span>
    </a>
    <div class="side-scroll"><?= $renderNav() ?></div>
    <div class="side-foot side-nav"><?= $renderFoot() ?></div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <details class="nav-drawer">
        <summary class="icon-btn" aria-label="منو"><svg class="icon" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg><?php if ($unreadPrivate > 0): ?><i class="dot-badge" aria-hidden="true"></i><?php endif; ?></summary>
        <div class="drawer-panel">
          <div class="drawer-head">
            <span class="brand-mark"><svg class="icon"><use href="#i-mark"/></svg></span>
            <span class="brand-name">آراد برندینگ<small><?= e($fullName) ?></small></span>
          </div>
          <div class="side-scroll"><?= $renderNav() ?></div>
          <div class="side-nav drawer-foot"><?= $renderFoot() ?></div>
        </div>
      </details>
      <div class="topbar-title">
        <h1><?= e($title ?? '') ?></h1>
        <?php if ($today !== ''): ?><span class="topbar-date"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5.5" width="16" height="14" rx="3"/><path d="M4 10h16M9 3.5v4M15 3.5v4"/></svg><?= e($today) ?></span><?php endif; ?>
      </div>
      <form class="search-bar topbar-search" method="get" action="/search" role="search"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><input class="input" type="search" name="q" placeholder="جستجوی تاجر، کشور، محصول یا فرصت…" aria-label="جستجو" enterkeyhint="search"></form>
      <div class="topbar-tools">
        <details class="notif" data-peek="/notifications/peek">
          <summary class="icon-btn bell" aria-label="اعلان‌ها<?= $unreadNotes > 0 ? ' (' . e(fa_num($unreadNotes)) . ' جدید)' : '' ?>"><svg class="icon"><use href="#i-bell"/></svg><?php if ($unreadNotes > 0): ?><em class="badge"><?= e(fa_num(min(99, $unreadNotes))) ?></em><?php endif; ?></summary>
          <div class="notif-panel" role="dialog" aria-label="اعلان‌ها">
            <div class="notif-head"><b>اعلان‌ها</b><a href="/notifications">مشاهده همه</a></div>
            <div class="notif-body" data-peek-body><div class="np-empty"><span class="np-spin" aria-hidden="true"></span><p>در حال دریافت…</p></div></div>
            <a class="notif-all" href="/notifications">همه اعلان‌ها</a>
          </div>
        </details>
        <button class="icon-btn theme-btn" type="button" data-theme-cycle aria-label="حالت نمایش: خودکار (مطابق دستگاه)" title="حالت نمایش: خودکار (مطابق دستگاه)"><svg class="icon"><use href="#i-theme"/></svg><i class="theme-auto" aria-hidden="true">A</i></button>
        <details class="profile">
          <summary aria-label="منوی حساب کاربری">
            <?= $avatar('avatar') ?>
            <span class="profile-text"><b><?= e($fullName) ?></b><small><?= e($roleName) ?></small></span>
            <svg class="profile-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="profile-panel" role="menu">
            <div class="profile-card"><?= $avatar('avatar avatar-lg') ?><span><b><?= e($fullName) ?></b><small><?= $user['handle'] ? '<bdi>/p/' . e($user['handle']) . '</bdi>' : e($roleName) ?></small></span></div>
            <a href="/account" role="menuitem"><svg class="icon"><use href="#i-user"/></svg>حساب کاربری</a>
            <a href="/wallet" role="menuitem"><svg class="icon"><use href="#i-star"/></svg>کیف پول Stars</a>
            <?php if (!empty($isStaff)): ?><a href="/admin" role="menuitem"><svg class="icon"><use href="#i-gear"/></svg>مدیریت سامانه</a><?php endif; ?>
            <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" role="menuitem"><svg class="icon"><use href="#i-logout"/></svg>خروج</button></form>
          </div>
        </details>
      </div>
    </header>
    <main id="main" class="content">
      <?php if (!empty($impersonating)): ?>
        <form class="alert alert-error impersonation-bar" method="post" action="/admin/impersonate/stop" role="status">
          <?= csrf_field() ?>
          <span>⚠️ شما با حساب «<?= e($fullName) ?>» وارد شده‌اید (حالت مدیر). همه کارها ثبت می‌شود.</span>
          <button class="btn btn-sm" type="submit">بازگشت به حساب مدیر</button>
        </form>
      <?php endif; ?>
      <?= $this->partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
      <?= $content ?>
    </main>
  </div>
</div>

<nav class="bottom-nav" aria-label="منوی پایین">
  <?php foreach ($nav as $item): if (!empty($item['side'])) { continue; } $b = (int) ($item['badge'] ?? 0); if ($item['href'] === '/letters') { $b += $unreadPrivate; } ?>
    <a href="<?= e($item['href']) ?>"<?= $isActive($item['href']) ? ' aria-current="page"' : '' ?>><span class="bn-icon"><svg class="icon"><use href="#i-<?= e($item['icon']) ?>"/></svg><?php if ($b > 0): ?><em class="dot-badge"></em><?php endif; ?></span><?= e($item['label']) ?></a>
  <?php endforeach; ?>
</nav>

<details class="fab">
  <summary aria-label="ساختن"><svg class="icon"><use href="#i-plus"/></svg></summary>
  <div class="fab-sheet" role="menu">
    <a href="/pages/new" role="menuitem"><svg class="icon"><use href="#i-page"/></svg>ساخت صفحه تجاری</a>
    <a href="/proposals/new" role="menuitem"><svg class="icon"><use href="#i-spark"/></svg>ساخت پیشنهاد تجاری</a>
    <a href="/letters/send" role="menuitem"><svg class="icon"><use href="#i-letter"/></svg>ارسال نامه</a>
  </div>
</details>
</body>
</html>
