<?php
/** Roadmap: seasons with their stages. @var array $ev */
$bySeason = [];
foreach ($ev['stages'] as $o) {
    $k = (int)($o['stage']['season_id'] ?? 0);
    $bySeason[$k]['title'] ??= $o['stage']['season_title'] ?: 'سایر مراحل';
    $bySeason[$k]['color'] ??= $o['stage']['season_color'] ?: '#64748b';
    $bySeason[$k]['sort'] ??= (int)($o['stage']['season_sort'] ?? 99);
    $bySeason[$k]['stages'][] = $o;
}
uasort($bySeason, fn($a, $b) => $a['sort'] <=> $b['sort']);
$si = 0;
$stateIcon = ['done' => 'check', 'reopened' => 'triangle-alert', 'locked' => 'lock'];
?>
<div class="tg-road">
    <?php foreach ($bySeason as $sea): $si++; ?>
        <div class="tg-season">
            <div class="tg-season-h" style="--sc:<?= e($sea['color']) ?>"><span>فصل <?= fa($si) ?>: <?= e($sea['title']) ?></span><small><?= fa(count(array_filter($sea['stages'], fn($o) => $o['complete']))) ?>/<?= fa(count($sea['stages'])) ?></small></div>
            <div class="tg-season-b">
                <?php foreach ($sea['stages'] as $o): $st = $o['state']; ?>
                    <a class="tg-node <?= $st ?>" href="#stage-<?= $o['no'] ?>" data-open-stage="<?= $o['no'] ?>">
                        <span class="ico"><span class="no"><?= fa($o['no']) ?></span><?= icon($stateIcon[$st] ?? $o['stage']['icon']) ?></span>
                        <span class="grow"><span class="nm"><?= e($o['stage']['title']) ?></span>
                            <?php if ($st === 'current' || $st === 'reopened'): ?><?= progress_bar($o['shown'], $st === 'reopened' ? 'warning' : '') ?><span class="small faint"><?= fa(round($o['shown'])) ?>٪</span>
                            <?php elseif ($st === 'done'): ?><span class="small" style="color:var(--success-ink)"><?= $o['waived'] ? 'معاف (تعیین مدیر)' : 'تکمیل شده' ?></span>
                            <?php else: ?><span class="small faint">قفل</span><?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
