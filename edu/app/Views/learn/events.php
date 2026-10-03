<?php use App\Services\Credit; ?>
<div class="ev-hero tone-<?= e($t['tone']) ?>">
    <div class="grow">
        <span class="ev-hero-ic"><?= icon($t['icon']) ?></span>
        <h1><?= e($t['plural']) ?></h1>
        <p><?= match ($type) {
            'webinar' => 'وبینارهای تخصصی تجارت؛ با ثبت‌نام، به اندازه مدت هر وبینار از اعتبار وبینار شما کم می‌شود و لینک ورود فوراً نمایش داده می‌شود.',
            'workshop' => 'کارگاه‌های عملی و تعاملی تجاری؛ ثبت‌نام در هر کارگاه یک عدد از اعتبار کارگاه شما کم می‌کند.',
            default => 'جلسات آنلاین منظم با تیم آراد برندینگ؛ با اشتراک فعال، لینک همه میتینگ‌های گروه شما در دسترس است.',
        } ?></p>
    </div>
    <div class="ev-balance">
        <?php if ($staff): ?>
            <span class="l">دسترسی مدیریتی</span><b>بدون کسر اعتبار</b>
        <?php elseif ($type === 'webinar'): ?>
            <span class="l">اعتبار وبینار شما</span><b><?= Credit::format($bal['webinar']) ?></b>
        <?php elseif ($type === 'workshop'): ?>
            <span class="l">اعتبار کارگاه شما</span><b><?= fa($bal['workshop']) ?> عدد</b>
        <?php else: ?>
            <span class="l">اشتراک میتینگ</span>
            <?php if ($bal['meeting_active']): ?><b>فعال تا <?= jdate($bal['meeting_until']) ?></b><small><?= fa($bal['meeting_days']) ?> روز باقی‌مانده</small>
            <?php else: ?><b>غیرفعال</b><small><?= $bal['meeting_until'] ? 'پایان: ' . jdate($bal['meeting_until']) : 'اشتراک ندارید' ?></small><?php endif; ?>
        <?php endif; ?>
        <a href="<?= url('/me/services', ['tab' => $type]) ?>"><?= icon('bookmark') ?> <?= e($t['mine']) ?></a>
    </div>
</div>

<?php if (!$staff && $type === 'meeting' && !$bal['meeting_active']): ?>
    <div class="alert alert-warning"><?= icon('info') ?><div>برای ورود به میتینگ‌ها اشتراک فعال لازم است. <?= e(setting('minutes_charge_text')) ?></div></div>
<?php endif; ?>

<h2 class="ev-section"><?= icon('calendar') ?> پیش رو</h2>
<?php if (!$upcoming): ?><div class="card empty"><?= icon($t['icon']) ?><div>در حال حاضر <?= e($t['label']) ?> پیش رویی ثبت نشده است.</div></div><?php endif; ?>
<div class="ev-grid">
    <?php foreach ($upcoming as $ev) include APP_PATH . '/Views/partials/event_card.php'; ?>
</div>

<?php if ($past): ?>
    <h2 class="ev-section mt-3"><?= icon('history') ?> برگزار شده</h2>
    <div class="ev-grid">
        <?php foreach ($past as $ev) include APP_PATH . '/Views/partials/event_card.php'; ?>
    </div>
<?php endif; ?>
