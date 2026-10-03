<?php /** @var array $ev */ $rem = $ev['remaining']; $max = $max ?? 50; ?>
<?php if ($ev['all_done']): ?>
    <div class="alert alert-success mb-0"><?= icon('crown') ?><div>همه مراحل نظام رشد تاجر کامل شده است. تبریک!</div></div>
<?php elseif (!$rem): ?>
    <div class="small muted">همه موارد این مرحله انجام شده؛ به‌زودی به مرحله بعد می‌روید.</div>
<?php else: ?>
    <ul class="tg-remain">
        <?php foreach (array_slice($rem, 0, $max) as $r): ?>
            <li class="t-<?= e($r['tone']) ?>"><?= icon($r['icon']) ?><span><?php if (!empty($r['url'])): ?><a href="<?= e(str_starts_with($r['url'], 'http') ? $r['url'] : url($r['url'])) ?>"<?= str_starts_with($r['url'], 'http') ? ' target="_blank" rel="noopener"' : '' ?>><?= e($r['text']) ?></a><?php else: ?><?= e($r['text']) ?><?php endif; ?></span></li>
        <?php endforeach; ?>
        <?php if (count($rem) > $max): ?><li class="t-gray"><?= icon('ellipsis-vertical') ?><span class="faint">و <?= fa(count($rem) - $max) ?> مورد دیگر</span></li><?php endif; ?>
    </ul>
<?php endif; ?>
