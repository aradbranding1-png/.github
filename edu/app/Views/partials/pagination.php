<?php
if (($p['pages'] ?? 1) <= 1) return;
$q = $_GET;
unset($q['r']);
$link = function (int $n) use ($q) { $q['page'] = $n; return url(current_path(), $q); };
$from = max(1, $p['page'] - 2);
$to = min($p['pages'], $p['page'] + 2);
?>
<nav class="pagination" aria-label="صفحه‌بندی">
    <?php if ($p['page'] > 1): ?><a href="<?= e($link($p['page'] - 1)) ?>"><?= icon('chevron-right') ?></a><?php endif; ?>
    <?php if ($from > 1): ?><a href="<?= e($link(1)) ?>"><?= fa(1) ?></a><?php if ($from > 2): ?><span>…</span><?php endif; endif; ?>
    <?php for ($i = $from; $i <= $to; $i++): ?>
        <?php if ($i === $p['page']): ?><span class="cur"><?= fa($i) ?></span><?php else: ?><a href="<?= e($link($i)) ?>"><?= fa($i) ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($to < $p['pages']): ?><?php if ($to < $p['pages'] - 1): ?><span>…</span><?php endif; ?><a href="<?= e($link($p['pages'])) ?>"><?= fa($p['pages']) ?></a><?php endif; ?>
    <?php if ($p['page'] < $p['pages']): ?><a href="<?= e($link($p['page'] + 1)) ?>"><?= icon('chevron-left') ?></a><?php endif; ?>
    <span class="faint" style="border:0;background:none">مجموع: <?= nf($p['total']) ?></span>
</nav>
