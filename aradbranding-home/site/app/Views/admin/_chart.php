<?php
/** Simple server-rendered bar chart (inline SVG, no JS, CSP-safe). @var list<array{label: string, value: int}> $series @var string $title @var bool|null $money */
$max = max(1, ...array_map(static fn ($p) => (int) $p['value'], $series ?: [['value' => 1]]));
$n = max(1, count($series));
$w = 600; $h = 160; $gap = 4; $bw = max(2, ($w - $gap * ($n - 1)) / $n);
$total = array_sum(array_column($series, 'value'));
$fmt = static fn (int $v): string => !empty($money) ? toman($v) : fa_int($v);
?>
<figure class="chart">
  <figcaption><span><?= e($title) ?></span><b><?= e($fmt($total)) ?></b></figcaption>
  <?php if ($series === []): ?>
    <p class="muted chart-empty">هنوز داده‌ای ثبت نشده است. آمار هر ساعت به‌روز می‌شود.</p>
  <?php else: ?>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="<?= e($title) ?>" preserveAspectRatio="none" dir="ltr">
      <?php foreach ($series as $i => $p): $bh = (int) round(((int) $p['value'] / $max) * ($h - 6)); ?>
        <rect x="<?= round($i * ($bw + $gap), 2) ?>" y="<?= $h - $bh ?>" width="<?= round($bw, 2) ?>" height="<?= max(1, $bh) ?>" rx="2"><title><?= e($p['label'] . ': ' . $fmt((int) $p['value'])) ?></title></rect>
      <?php endforeach; ?>
    </svg>
    <div class="chart-axis" dir="ltr"><span><?= e($series[0]['label']) ?></span><span><?= e($series[$n - 1]['label']) ?></span></div>
  <?php endif; ?>
</figure>
