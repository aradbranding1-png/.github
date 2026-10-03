<?php
/** @var array $stages @var ?array $current */
$curSort = (int)($current['sort'] ?? 0);
$n = max(1, count($stages));
?>
<div class="ladder">
    <?php foreach ($stages as $i => $s):
        $reached = (int)$s['sort'] <= $curSort;
        $isCur = $current && (int)$s['id'] === (int)$current['id'];
        $h = 60 + (int)round(($i + 1) * 110 / $n); ?>
        <div class="rung<?= $reached ? ' reached' : '' ?><?= $isCur ? ' current' : '' ?>" style="--c:<?= e($s['color']) ?>">
            <div class="bar" style="height:<?= $h ?>px"><?= icon($reached ? $s['icon'] : 'lock') ?></div>
            <div class="nm"><?= e($s['name']) ?></div>
            <span class="faint small">مرحله <?= fa($i + 1) ?></span>
        </div>
    <?php endforeach; ?>
</div>
