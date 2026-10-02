<?php
/** @var string $content @var array $user @var string $title @var string $path */
$unreadPrivate = (int) ($user['unread_private'] ?? 0);
$letterType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$nav = [
    ['href' => '/dashboard', 'label' => 'خانه', 'icon' => 'home', 'ready' => true],
    ['href' => '/letters?type=private', 'label' => 'ارتباطات اختصاصی', 'icon' => 'lock', 'ready' => true, 'badge' => $unreadPrivate, 'key' => 'private', 'side' => true],
    ['href' => '/discover', 'label' => 'کشف', 'icon' => 'compass', 'ready' => true],
    ['href' => '/proposals', 'label' => 'پیشنهادات', 'icon' => 'spark', 'ready' => true],
    ['href' => '/letters', 'label' => 'ارتباطات', 'icon' => 'chat', 'ready' => true, 'badge' => (int) ($user['unread_letters'] ?? 0) + (int) ($user['unread_official'] ?? 0)],
    ['href' => '/pages', 'label' => 'پیج من', 'icon' => 'page', 'ready' => true],
];
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
    if ($href === '/letters' && (str_starts_with($path, '/connections') || str_starts_with($path, '/admin/letters') || $path === '/updates')) {
        return true;
    }
    if ($href === '/admin' && str_starts_with($path, '/admin/letters')) {
        return false;
    }
    return $path === $href || str_starts_with($path, $href . '/');
};
$avatarUrl = media($user['avatar_path'] ?? null);
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$unreadNotes = (int) ($user['unread_notifications'] ?? 0);
$badge = static fn (int $n, string $label = 'خوانده‌نشده'): string => $n > 0
    ? '<em class="badge" aria-label="' . e(fa_num($n)) . ' ' . e($label) . '">' . e(fa_num(min(99, $n))) . ($n > 99 ? '+' : '') . '</em>' : '';
$renderNav = static function () use ($nav, $isActive, $badge): string {
    $html = '';
    foreach ($nav as $item) {
        $current = $isActive($item['href']) ? ' aria-current="page"' : '';
        $html .= '<a href="' . e($item['href']) . '"' . $current . (isset($item['key']) ? ' class="nav-' . e($item['key']) . '"' : '') . '>'
            . '<svg class="icon"><use href="#i-' . e($item['icon']) . '"/></svg><span class="nav-label">' . e($item['label']) . '</span>'
            . $badge((int) ($item['badge'] ?? 0)) . '</a>';
    }
    return $html;
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
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
      <span class="brand-name">سامانه توسعه تجارت<small>Arad Branding · شبکه بین‌المللی تجار</small></span>
    </a>
    <nav class="side-nav"><?= $renderNav() ?></nav>
    <a class="side-user" href="/account">
      <?php if ($avatarUrl): ?><img class="avatar" src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($user['first_name'] ?? '', $user['last_name'] ?? '')) ?></span><?php endif; ?>
      <span class="side-user-text"><b><?= e($fullName) ?></b><small><?= flag($user['country_code'] ?? '') ?> <?= $user['handle'] ? '<bdi>/p/' . e($user['handle']) . '</bdi>' : 'حساب کاربری' ?></small></span>
    </a>
    <div class="side-foot side-nav">
      <?php if (!empty($isStaff)): ?><a href="/admin"<?= $isActive('/admin') ? ' aria-current="page"' : '' ?>><svg class="icon"><use href="#i-gear"/></svg><span class="nav-label">مدیریت سامانه</span></a><?php endif; ?>
      <a href="/wallet"<?= $isActive('/wallet') ? ' aria-current="page"' : '' ?>><svg class="icon"><use href="#i-star"/></svg><span class="nav-label">کیف پول Stars</span></a>
      <form method="post" action="/logout">
        <?= csrf_field() ?>
        <button class="btn btn-quiet btn-block btn-start" type="submit"><svg class="icon"><use href="#i-logout"/></svg>خروج</button>
      </form>
    </div>
  </aside>

  <div class="main-col">
    <header class="topbar">
      <details class="nav-drawer">
        <summary class="icon-btn" aria-label="منو"><svg class="icon" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg><?php if ($unreadPrivate > 0): ?><i class="dot-badge" aria-hidden="true"></i><?php endif; ?></summary>
        <div class="drawer-panel">
          <div class="drawer-head">
            <span class="brand-mark"><svg class="icon"><use href="#i-mark"/></svg></span>
            <span class="brand-name">سامانه توسعه تجارت<small><?= e($fullName) ?></small></span>
          </div>
          <nav class="side-nav"><?= $renderNav() ?></nav>
          <div class="side-nav drawer-foot">
            <?php if (!empty($isStaff)): ?><a href="/admin"<?= $isActive('/admin') ? ' aria-current="page"' : '' ?>><svg class="icon"><use href="#i-gear"/></svg><span class="nav-label">مدیریت سامانه</span></a><?php endif; ?>
            <a href="/wallet"<?= $isActive('/wallet') ? ' aria-current="page"' : '' ?>><svg class="icon"><use href="#i-star"/></svg><span class="nav-label">کیف پول Stars</span></a>
            <a href="/account"<?= $isActive('/account') ? ' aria-current="page"' : '' ?>><svg class="icon"><use href="#i-user"/></svg><span class="nav-label">حساب کاربری</span></a>
            <form method="post" action="/logout"><?= csrf_field() ?><button class="btn btn-quiet btn-block btn-start" type="submit"><svg class="icon"><use href="#i-logout"/></svg>خروج</button></form>
          </div>
        </div>
      </details>
      <span class="brand-mark" aria-hidden="true"><svg class="icon"><use href="#i-mark"/></svg></span>
      <h1><?= e($title ?? '') ?></h1>
      <form class="search-bar topbar-search" method="get" action="/search" role="search"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><input class="input" type="search" name="q" placeholder="جستجوی تاجر، کالا یا فرصت…" aria-label="جستجو" enterkeyhint="search"></form>
      <details class="notif" data-peek="/notifications/peek">
        <summary class="icon-btn bell" aria-label="اعلان‌ها<?= $unreadNotes > 0 ? ' (' . e(fa_num($unreadNotes)) . ' جدید)' : '' ?>"><svg class="icon"><use href="#i-bell"/></svg><?php if ($unreadNotes > 0): ?><em class="badge"><?= e(fa_num(min(99, $unreadNotes))) ?></em><?php endif; ?></summary>
        <div class="notif-panel" role="dialog" aria-label="اعلان‌ها">
          <div class="notif-head"><b>اعلان‌ها</b><a href="/notifications">مشاهده همه</a></div>
          <div class="notif-body" data-peek-body><div class="np-empty"><span class="np-spin" aria-hidden="true"></span><p>در حال دریافت…</p></div></div>
          <a class="notif-all" href="/notifications">همه اعلان‌ها</a>
        </div>
      </details>
      <a class="icon-btn wallet-btn" href="/wallet" aria-label="کیف پول Stars"><svg class="icon"><use href="#i-star"/></svg></a>
      <button class="icon-btn theme-btn" type="button" data-theme-toggle aria-label="تغییر حالت روشن و تاریک"><svg class="icon"><use href="#i-theme"/></svg></button>
      <a class="avatar" href="/account" aria-label="حساب کاربری">
        <?php if ($avatarUrl): ?><img class="avatar" src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><?= e(initials($user['first_name'] ?? '', $user['last_name'] ?? '')) ?><?php endif; ?>
      </a>
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
