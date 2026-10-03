<?php
use App\Core\Gate;
$bySection = [];
foreach ($reg['modules'] as $m => $def) $bySection[$def['section']][$m] = $def;
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/roles') ?>">نقش‌ها</a></div><h1>ماتریس کلی دسترسی‌ها</h1><div class="sub">مقایسه همه نقش‌های مدیریتی در یک نگاه</div></div></div>
<div class="card flush">
    <div class="table-wrap" style="max-height:78vh">
        <table class="matrix">
            <thead><tr><th>مجوز</th><?php foreach ($roles as $r): ?><th><a href="<?= url('/admin/roles/' . $r['id']) ?>"><?= e($r['name']) ?></a></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($bySection as $sec => $mods): ?>
                <tr class="sec-row"><td colspan="<?= count($roles) + 1 ?>"><?= e($reg['sections'][$sec]['label']) ?></td></tr>
                <?php foreach ($mods as $m => $def) foreach ($def['actions'] as $a): $key = "$m.$a"; ?>
                    <tr><td><?= e($def['label']) ?> — <b><?= e($reg['actions'][$a]) ?></b></td>
                        <?php foreach ($roles as $r): ?>
                            <td><?php if ($r['is_root']): ?><span style="color:#1d9bf0"><?= icon('check') ?></span><?php elseif (Gate::isRootOnly($key)): ?><span class="lock"><?= icon('crown') ?></span><?php elseif (isset($map[$r['id']][$key])): ?><span style="color:var(--success)"><?= icon('check') ?></span><?php else: ?><span class="na">—</span><?php endif; ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
