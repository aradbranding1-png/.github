<?php
use App\Services\Credit;
use App\Services\EventService;
$tabs = [
    'courses' => ['دوره‌های من', 'graduation-cap', $counts['courses']],
    'webinar' => ['وبینارهای من', 'video', $counts['webinar']],
    'workshop' => ['کارگاه‌های من', 'briefcase', $counts['workshop']],
    'meeting' => ['میتینگ‌های من', 'users', null],
];
?>
<div class="page-head"><div><h1>خدمات و جلسات من</h1><div class="sub">همه دوره‌ها، وبینارها، کارگاه‌ها و میتینگ‌های شما با اعتبار باقی‌مانده، در یک جا</div></div>
    <a class="btn btn-outline" href="<?= url('/me/credits') ?>"><?= icon('history') ?> تاریخچه اعتبار</a></div>

<div class="svc-credits">
    <a class="svc-c tone-primary" href="<?= url('/me/services', ['tab' => 'courses']) ?>"><span class="ic"><?= icon('graduation-cap') ?></span><div><span class="l">اعتبار دوره‌ها</span><b><?= Credit::format($bal['course']) ?></b><small>مصرف‌شده: <?= Credit::format((int)($used['course'] ?? 0)) ?></small></div></a>
    <a class="svc-c tone-purple" href="<?= url('/me/services', ['tab' => 'webinar']) ?>"><span class="ic"><?= icon('video') ?></span><div><span class="l">اعتبار وبینار</span><b><?= Credit::format($bal['webinar']) ?></b><small>مصرف‌شده: <?= Credit::format((int)($used['webinar'] ?? 0)) ?></small></div></a>
    <a class="svc-c tone-warning" href="<?= url('/me/services', ['tab' => 'workshop']) ?>"><span class="ic"><?= icon('briefcase') ?></span><div><span class="l">اعتبار کارگاه آنلاین</span><b><?= fa($bal['workshop']) ?> عدد</b><small>استفاده‌شده: <?= fa((int)($used['workshop'] ?? 0)) ?> عدد</small></div></a>
    <a class="svc-c tone-info" href="<?= url('/me/services', ['tab' => 'meeting']) ?>"><span class="ic"><?= icon('users') ?></span><div><span class="l">اشتراک میتینگ آنلاین</span>
        <?php if ($bal['meeting_active']): ?><b>فعال تا <?= jdate($bal['meeting_until']) ?></b><small><?= fa($bal['meeting_days']) ?> روز باقی‌مانده</small><?php else: ?><b>غیرفعال</b><small><?= $bal['meeting_until'] ? 'پایان: ' . jdate($bal['meeting_until']) : 'اشتراکی ندارید' ?></small><?php endif; ?>
    </div></a>
</div>

<div class="tabs mt-3">
    <?php foreach ($tabs as $k => [$l, $ic, $n]): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= url('/me/services', ['tab' => $k]) ?>"><?= icon($ic) ?> <?= $l ?><?= $n !== null ? ' (' . fa($n) . ')' : '' ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'courses'): ?>
    <?php if (!$rows): ?><div class="card empty"><?= icon('graduation-cap') ?><div>هنوز در دوره‌ای ثبت‌نام نشده‌اید.</div><a class="btn btn-primary" href="<?= url('/learn/catalog') ?>">کاتالوگ دوره‌ها</a></div><?php endif; ?>
    <div class="svc-list">
    <?php foreach ($rows as $r): $img = image_url($r['image_file_id'], 320); ?>
        <a class="svc-row" href="<?= url('/learn/course/' . $r['course_id']) ?>">
            <span class="svc-thumb tone-primary"><?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><?= icon('graduation-cap') ?><?php endif; ?></span>
            <span class="grow"><b><?= e($r['title']) ?></b><span class="svc-meta"><?= status_badge($r['status']) ?> <?= $r['duration_minutes'] ? icon('clock') . ' ' . Credit::format((int)$r['duration_minutes']) : '' ?> · آخرین فعالیت: <?= $r['last_activity_at'] ? time_ago($r['last_activity_at']) : '—' ?></span>
                <span class="svc-prog"><?= progress_bar((float)$r['progress_pct']) ?><small><?= fa((int)$r['progress_pct']) ?>٪</small></span></span>
            <span class="btn btn-sm btn-outline">ادامه</span>
        </a>
    <?php endforeach; ?>
    </div>
<?php else: $et = EventService::TYPES[$tab]; ?>
    <?php if ($tab === 'meeting' && !$bal['meeting_active'] && !EventService::isStaff($u)): ?>
        <div class="alert alert-warning"><?= icon('info') ?><div>اشتراک میتینگ شما فعال نیست<?= $rows ? '؛ فهرست زیر میتینگ‌هایی است که قبلاً در آن‌ها حضور داشته‌اید' : '' ?>. <?= e(setting('minutes_charge_text')) ?></div></div>
    <?php endif; ?>
    <?php if (!$rows): ?><div class="card empty"><?= icon($et['icon']) ?><div><?= $tab === 'meeting' ? 'میتینگی برای نمایش وجود ندارد.' : 'هنوز در ' . e($et['label']) . 'ی ثبت‌نام نکرده‌اید.' ?></div><a class="btn btn-primary" href="<?= url('/learn/events/' . $tab) ?>">مشاهده <?= e($et['plural']) ?></a></div><?php endif; ?>
    <div class="svc-list">
    <?php foreach ($rows as $r): $ph = EventService::phase($r); $img = image_url($r['image_file_id'] ?: $r['banner_file_id'], 320); ?>
        <div class="svc-row<?= $ph === 'ended' ? ' is-ended' : '' ?>">
            <a class="svc-thumb tone-<?= e($et['tone']) ?>" href="<?= url('/learn/event/' . $r['id']) ?>"><?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><?= icon($et['icon']) ?><?php endif; ?></a>
            <span class="grow"><a href="<?= url('/learn/event/' . $r['id']) ?>"><b><?= e($r['title']) ?></b></a>
                <span class="svc-meta">
                    <span class="badge badge-<?= ['upcoming' => 'info', 'live' => 'danger', 'ended' => 'gray', 'unscheduled' => 'gray'][$ph] ?>"><?= ['upcoming' => 'پیش رو', 'live' => 'در حال برگزاری', 'ended' => 'برگزار شده', 'unscheduled' => 'بدون زمان'][$ph] ?></span>
                    <?= icon('calendar') ?> <?= jdate($r['starts_at'], 'l j F Y') ?> · <?= icon('clock') ?> <?= $r['starts_at'] ? fa(date('H:i', strtotime($r['starts_at']))) : '—' ?> · <?= Credit::format((int)$r['duration_minutes']) ?>
                    <?php if (!empty($r['cost'])): ?> · <?= icon('zap') ?> <?= Credit::amount($tab, (int)$r['cost']) ?> کسر شد<?php endif; ?>
                    <?php if (!empty($r['joined_at'])): ?> · <span style="color:var(--success)"><?= icon('check') ?> وارد شده‌اید</span><?php endif; ?>
                </span>
                <?php if ($r['join_url'] && $ph !== 'ended'): ?><span class="svc-link ltr"><?= icon('link') ?> <?= e($r['join_url']) ?></span><?php endif; ?>
            </span>
            <?php if ($r['join_url'] && $ph !== 'ended' && ($tab !== 'meeting' || $bal['meeting_active'] || EventService::isStaff($u))): ?>
                <a class="btn btn-sm btn-grad" href="<?= url('/learn/event/' . $r['id'] . '/join') ?>" target="_blank" rel="noopener"><?= icon('log-in') ?> ورود</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline" href="<?= url('/learn/event/' . $r['id']) ?>">جزئیات</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
