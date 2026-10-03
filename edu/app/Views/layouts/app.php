<?php
use App\Core\Auth;
use App\Core\DB;
use App\Core\Notify;

$me = auth();
$menu = require APP_PATH . '/Config/menu.php';
$cur = current_path();
$siteName = setting('site_name');
$unread = $me ? Notify::unreadCount((int)$me['id']) : 0;
$notifs = $me ? Notify::recent((int)$me['id'], 7) : [];
$roleNames = $me ? DB::column('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.is_learner, r.sort', [(int)$me['id']]) : [];
$badgeCounts = [];
if ($me && can('reviews.view')) {
    $badgeCounts['reviews'] = (int)DB::value("SELECT (SELECT COUNT(*) FROM exercise_submissions WHERE status = 'submitted') + (SELECT COUNT(*) FROM exam_attempts WHERE status = 'pending_review')");
}
if ($me && can('growth.approve')) {
    try { $badgeCounts['growth_reviews'] = (int)DB::value("SELECT (SELECT COUNT(*) FROM tg_deals WHERE status = 'pending') + (SELECT COUNT(*) FROM tg_requests WHERE status = 'pending')"); } catch (\Throwable) {}
}
if ($me && can('users.approve')) {
    $badgeCounts['users_pending'] = (int)DB::value("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'pending'");
}
$isActive = function (string $href) use ($cur): bool {
    if ($href === '/') return $cur === '/';
    if ($href === '/admin') return $cur === '/admin';
    if ($href === '/admin/growth') return $cur === '/admin/growth' || preg_match('~^/admin/growth/(stages|settings|legacy|user)~', $cur) === 1;
    if ($href === '/learn') return $cur === '/learn' || preg_match('~^/learn/(course|lesson)~', $cur) === 1;
    return $cur === $href || str_starts_with($cur, $href . '/');
};
$imp = Auth::isImpersonating() ? Auth::impersonator() : null;
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="upload-max" content="<?= upload_limit_bytes() ?>">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') !== '' ? $title . ' | ' . $siteName : $siteName) ?></title>
<?php include APP_PATH . '/Views/partials/pwa_head.php'; ?>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<?php if ($imp): ?>
<div class="imp-bar">
    <div class="flex"><?= icon('venetian-mask') ?> شما با حساب <b><?= e(full_name($me)) ?></b> وارد شده‌اید (ورود مدیر کل: <?= e(full_name($imp)) ?>). همه اقدامات ثبت می‌شود.</div>
    <form method="post" action="<?= url('/impersonate/stop') ?>"><?= csrf_field() ?><button class="btn btn-sm"><?= icon('log-out') ?> بازگشت به حساب مدیر کل</button></form>
</div>
<?php endif; ?>
<div class="shell">
    <aside class="sidebar" aria-label="منو">
        <div class="sb-brand">
            <div class="sb-logo is-img"><img src="<?= asset('img/icons/icon-96.png') ?>" alt="آراد برندینگ" width="42" height="42"></div>
            <div><div class="sb-title"><?= e($siteName) ?></div><div class="sb-sub">edu.aradbranding.me</div></div>
        </div>
        <nav class="sb-nav">
            <?php foreach ($menu as $sec):
                $items = array_filter($sec['items'], fn($it) => $it[3] === null || (is_array($it[3]) ? can_any($it[3]) : can($it[3])));
                if (!$items) continue; ?>
                <div class="sb-sec"><?= e($sec['section']) ?></div>
                <?php foreach ($items as $it): $cnt = isset($it[4]) ? ($badgeCounts[$it[4]] ?? 0) : 0; ?>
                    <a class="sb-link<?= $isActive($it[0]) ? ' active' : '' ?>" href="<?= url($it[0]) ?>"><?= icon($it[2]) ?><span><?= e($it[1]) ?></span><?php if ($cnt): ?><span class="count"><?= fa($cnt) ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sb-foot"><span>نسخه <?= e(app_version()) ?></span><span>آراد برندینگ</span></div>
    </aside>
    <div class="overlay"></div>
    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" type="button" data-nav-toggle aria-label="منو"><?= icon('menu') ?></button>
            <form class="search" action="<?= can('users.view') ? url('/admin/users') : url('/learn/catalog') ?>" method="get" role="search">
                <?= icon('search') ?>
                <input type="search" name="q" placeholder="<?= can('users.view') ? 'جست‌وجوی کاربر (نام، موبایل، شناسه)…' : 'جست‌وجوی دوره…' ?>" aria-label="جست‌وجو">
            </form>
            <div class="tb-actions">
                <?php if ($me && App\Services\Credit::applies($me)): $bal = (int)($me['minute_balance'] ?? 0); ?>
                    <a class="credit-chip<?= $bal < 30 ? ' is-low' : '' ?>" href="<?= url('/me/credits') ?>" title="اعتبار زمانی شما"><?= icon('clock') ?> <span class="cc-l">اعتبار:</span> <?= App\Services\Credit::format($bal) ?></a>
                <?php endif; ?>
                <button class="icon-btn" type="button" data-theme-toggle title="حالت روشن/تاریک"><?= icon('moon') ?></button>
                <details class="dropdown" data-notif data-unread="<?= $unread ?>" data-read-url="<?= url('/notifications/read-all') ?>">
                    <summary class="icon-btn" title="اعلان‌ها"><?= icon('bell') ?><?php if ($unread): ?><span class="dot"><?= fa(min($unread, 99)) ?></span><?php endif; ?></summary>
                    <div class="dd-backdrop" data-dd-close aria-hidden="true"></div>
                    <div class="dd-menu dd-notif" style="min-width:330px" role="dialog" aria-label="اعلان‌ها">
                        <div class="dd-head flex between"><b>اعلان‌ها</b><span class="flex" style="gap:.4rem"><a class="small" href="<?= url('/notifications') ?>" style="width:auto;padding:0">مشاهده همه</a><button type="button" class="dd-close" data-dd-close aria-label="بستن"><?= icon('x') ?></button></span></div>
                        <?php if (!$notifs): ?><div class="empty" style="padding:1.2rem"><?= icon('bell') ?><div class="small">اعلانی ندارید</div></div><?php endif; ?>
                        <?php foreach ($notifs as $n): $t = App\Core\Notify::TYPES[$n['type']] ?? App\Core\Notify::TYPES['system']; ?>
                            <a class="notif-item<?= $n['read_at'] ? '' : ' unread' ?>" href="<?= e($n['link'] ?: url('/notifications')) ?>">
                                <span class="badge badge-<?= $t[2] ?>"><?= icon($t[1]) ?></span>
                                <span><span class="t"><?= e($n['title']) ?></span><br><span class="b"><?= e(str_limit($n['body'], 70)) ?></span><br><span class="faint small"><?= time_ago($n['created_at']) ?></span></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
                <details class="dropdown dd-user-wrap">
                    <summary class="user-chip" aria-label="منوی حساب کاربری">
                        <?= avatar_html($me, 'sm') ?>
                        <span><span class="nm"><?= user_name_html($me) ?></span><br><span class="rl"><?= e($roleNames[0] ?? '') ?></span></span>
                        <?= icon('chevron-down', 'faint uc-chev') ?>
                    </summary>
                    <div class="dd-backdrop" data-dd-close aria-hidden="true"></div>
                    <div class="dd-menu dd-user" role="dialog" aria-label="حساب کاربری">
                        <div class="dd-profile">
                            <button type="button" class="dd-close" data-dd-close aria-label="بستن"><?= icon('x') ?></button>
                            <?= avatar_html($me, 'md') ?>
                            <div class="dp-txt"><b><?= user_name_html($me) ?></b><div class="small faint ltr"><?= e($me['mobile'] ?? $me['email'] ?? '') ?></div></div>
                            <?php if ($roleNames): ?><div class="dp-roles"><?php foreach ($roleNames as $rn): ?><span class="badge <?= !empty($me['is_root']) && $rn === 'مدیر کل' ? 'badge-root' : 'badge-gray' ?>"><?= e($rn) ?></span><?php endforeach; ?></div><?php endif; ?>
                        </div>
                        <nav class="dd-list">
                            <a href="<?= url('/profile') ?>"><span class="mi-ic"><?= icon('user') ?></span><span class="grow">پروفایل من</span><?= icon('chevron-left', 'mi-go') ?></a>
                            <a href="<?= url('/me/report') ?>"><span class="mi-ic"><?= icon('activity') ?></span><span class="grow">گزارش فعالیت من</span><?= icon('chevron-left', 'mi-go') ?></a>
                            <a href="<?= url('/learn/certificates') ?>"><span class="mi-ic"><?= icon('award') ?></span><span class="grow">گواهی‌های من</span><?= icon('chevron-left', 'mi-go') ?></a>
                            <a href="<?= url('/me/credits') ?>"><span class="mi-ic"><?= icon('clock') ?></span><span class="grow">اعتبارها و اشتراک‌های من</span><?= icon('chevron-left', 'mi-go') ?></a>
                            <button type="button" data-pwa-install hidden><span class="mi-ic"><?= icon('download') ?></span><span class="grow">نصب اپلیکیشن</span></button>
                        </nav>
                        <?php $ddAdmin = [];
                        if (can('users.view')) $ddAdmin[] = ['/admin/users', 'users', 'مدیریت کاربران'];
                        if (is_root()) $ddAdmin[] = ['/admin/system/updates', 'refresh-cw', 'بروزرسانی سامانه'];
                        if ($ddAdmin): ?>
                        <div class="dd-sec">مدیریت</div>
                        <nav class="dd-list dd-admin">
                            <?php foreach ($ddAdmin as [$h, $ic, $tx]): ?><a href="<?= url($h) ?>"><span class="mi-ic"><?= icon($ic) ?></span><span class="grow"><?= e($tx) ?></span><?= icon('chevron-left', 'mi-go') ?></a><?php endforeach; ?>
                        </nav>
                        <?php endif; ?>
                        <form method="post" action="<?= url('/logout') ?>" class="dd-logout"><?= csrf_field() ?><button type="submit"><span class="mi-ic"><?= icon('log-out') ?></span><span class="grow">خروج از حساب</span></button></form>
                    </div>
                </details>
            </div>
        </header>
        <main class="content">
            <?php foreach (flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>" data-autohide><?= icon($f['type'] === 'success' ? 'circle-check' : ($f['type'] === 'danger' ? 'circle-x' : 'info')) ?><div><?= $f['msg'] /* already escaped by producer */ ?></div></div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
        <footer class="footer">© <?= fa(App\Core\Jalali::format('Y', time())) ?> آراد برندینگ — سامانه جامع آموزش</footer>
    </div>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
