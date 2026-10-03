<?php /** Dashboard card @var array $ev */ $c = $ev['cur']; ?>
<div class="card tg-mini">
    <div class="card-head"><h3><?= icon('mountain') ?> نظام رشد تاجر</h3><a class="small" href="<?= url('/learn/growth') ?>">مشاهده مسیر</a></div>
    <div class="flex between flex-wrap gap-2">
        <div><div class="small faint">مرحله <?= fa($ev['current']) ?> از <?= fa($ev['count']) ?></div><b><?= e($c['stage']['title'] ?? '') ?></b></div>
        <?= App\Services\TraderGrowth::badge($ev['current'], true, 'lg') ?>
    </div>
    <div class="dots"><?php foreach ($ev['stages'] as $o): ?><i class="<?= $o['state'] === 'done' ? 'done' : ($o['state'] === 'current' ? 'cur' : ($o['state'] === 'reopened' ? 're' : '')) ?>" title="<?= e(fa($o['no']) . '. ' . $o['stage']['title']) ?>"></i><?php endforeach; ?></div>
    <?php if ($c && !$ev['all_done']): ?><div class="flex gap-2 mb-2"><div class="grow"><?= progress_bar($c['shown']) ?></div><span class="small fw-b"><?= fa(round($c['shown'])) ?>٪</span></div><?php endif; ?>
    <?php $max = 3; include APP_PATH . '/Views/partials/growth_remaining.php'; ?>
</div>
