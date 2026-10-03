<?php
/** @var array $ev  event row (+ optional reg_id / registered / joined_at) */
use App\Services\EventService;
use App\Services\Credit;
$et = EventService::TYPES[$ev['type']];
$ph = EventService::phase($ev);
$img = image_url($ev['image_file_id'] ?: $ev['banner_file_id'], 640);
$isReg = !empty($ev['reg_id']) || !empty($ev['registered']);
$ts = $ev['starts_at'] ? strtotime($ev['starts_at']) : null;
?>
<a class="ev-card tone-<?= e($et['tone']) ?><?= $ph === 'ended' ? ' is-ended' : '' ?>" href="<?= url('/learn/event/' . $ev['id']) ?>">
    <div class="ev-media">
        <?php if ($img): ?><img class="cc-bg" src="<?= e($img) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async"><img class="cc-fg" src="<?= e($img) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="ev-ph"><?= icon($et['icon']) ?></span><?php endif; ?>
    </div>
    <div class="ev-body">
        <div class="course-tags"><span class="ev-kind grow"><?= icon($et['icon']) ?> <?= e($et['label']) ?></span>
        <span>
            <?php if ($ph === 'live'): ?><span class="badge badge-danger"><span class="live-dot"></span> در حال برگزاری</span>
            <?php elseif ($isReg && $ev['type'] !== 'meeting'): ?><span class="badge badge-success"><?= icon('check') ?> ثبت‌نام شده</span>
            <?php elseif ($ph === 'ended'): ?><span class="badge badge-gray">برگزار شده</span><?php endif; ?>
        </span></div>
        <h3><?= e($ev['title']) ?></h3>
        <?php if (!empty($ev['summary'])): ?><p class="ev-sum"><?= e(str_limit($ev['summary'], 110)) ?></p><?php endif; ?>
        <div class="ev-meta">
            <?php if ($ts): ?><span><?= icon('calendar') ?> <?= jdate($ev['starts_at'], 'l j F') ?></span><span><?= icon('clock') ?> ساعت <?= fa(date('H:i', $ts)) ?></span><?php endif; ?>
            <span><?= icon('hourglass') ?> <?= Credit::format((int)$ev['duration_minutes']) ?></span>
        </div>
        <div class="ev-foot">
            <?php if ($ev['type'] === 'webinar'): ?><span class="ev-cost"><?= icon('zap') ?> <?= Credit::format((int)$ev['duration_minutes']) ?> اعتبار</span>
            <?php elseif ($ev['type'] === 'workshop'): ?><span class="ev-cost"><?= icon('zap') ?> ۱ اعتبار کارگاه</span>
            <?php else: ?><span class="ev-cost"><?= icon('badge-check') ?> با اشتراک میتینگ</span><?php endif; ?>
            <span class="ev-go"><?= $isReg || $ev['type'] === 'meeting' ? 'جزئیات و لینک ورود' : 'جزئیات و ثبت‌نام' ?> <?= icon('chevron-left') ?></span>
        </div>
    </div>
</a>
