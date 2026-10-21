<?php
/**
 * Report audience bar: segment pills + organizational group. Keeps every other query parameter.
 * $__audSeg = false hides the segment pills (pages that already have their own segment filter).
 */
use App\Services\Scope;
$__aud = Scope::audience();
$__audSeg = $audSegments ?? true;
$__audKeep = $_GET; unset($__audKeep['aseg'], $__audKeep['agrp'], $__audKeep['page'], $__audKeep['export'], $__audKeep['r']);
$__audPath = current_path();
$__audLink = fn(array $o) => url($__audPath, array_filter(array_merge($__audKeep, ['aseg' => $__aud['seg'], 'agrp' => $__aud['group'] ?: ''], $o), fn($v) => $v !== '' && $v !== null && $v !== 0));
$__audGroups = \App\Core\DB::all('SELECT id, name, segment, parent_id FROM `groups` ORDER BY segment, sort, name');
$__audBySeg = [];
foreach ($__audGroups as $__audG) $__audBySeg[(string)$__audG['segment']][] = $__audG;
?>
<div class="aud-bar">
    <span class="aud-label"><?= icon('users') ?> تفکیک بر اساس:</span>
    <?php if ($__audSeg): ?>
    <div class="pill-nav" style="margin:0">
        <a class="<?= $__aud['seg'] === '' ? 'active' : '' ?>" href="<?= $__audLink(['aseg' => '']) ?>">همه</a>
        <?php foreach (\App\Core\Labels::SEGMENT as $__audK => $__audT): if ($__audK === 'custom') continue; ?><a class="<?= $__aud['seg'] === $__audK ? 'active' : '' ?>" href="<?= $__audLink(['aseg' => $__audK]) ?>"><?= e($__audT) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="get" action="<?= url($__audPath) ?>" class="aud-form">
        <?php foreach (array_merge($__audKeep, ['aseg' => $__aud['seg']]) as $__audHk => $__audHv): if (is_scalar($__audHv) && (string)$__audHv !== ''): ?><input type="hidden" name="<?= e((string)$__audHk) ?>" value="<?= e((string)$__audHv) ?>"><?php endif; endforeach; ?>
        <select name="agrp" data-autosubmit aria-label="گروه">
            <option value="">همهٔ گروه‌ها</option>
            <?php foreach ($__audBySeg as $__audSg => $__audList): ?><optgroup label="<?= e(\App\Core\Labels::SEGMENT[$__audSg] ?? 'سایر') ?>"><?php foreach ($__audList as $__audG): ?><option value="<?= (int)$__audG['id'] ?>"<?= selected((int)$__audG['id'], $__aud['group']) ?>><?= $__audG['parent_id'] ? '— ' : '' ?><?= e($__audG['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
        </select>
    </form>
    <?php if ($__aud['seg'] !== '' || $__aud['group']): ?><a class="btn btn-ghost btn-sm" href="<?= $__audLink(['aseg' => '', 'agrp' => '']) ?>"><?= icon('x') ?> حذف فیلتر</a><?php endif; ?>
</div>
