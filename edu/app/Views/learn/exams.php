<div class="page-head"><div><h1>آزمون‌های من</h1><div class="sub">آزمون‌های دوره‌های شما، نمرات و وضعیت قبولی</div></div></div>
<div class="grid g-auto mb-3">
<?php foreach ($rows as $x): $state = $x['passed'] ? 'passed' : ($x['pending'] ? 'pending_review' : ($x['used'] ? 'failed' : 'not_started')); ?>
    <div class="card" style="border-top:4px solid var(--<?= $state === 'passed' ? 'success' : ($state === 'failed' ? 'danger' : ($state === 'pending_review' ? 'warning' : 'purple')) ?>)">
        <div class="flex between"><span class="flex"><?= status_badge($state) ?><?php if (!$x['passed'] && !App\Services\Credit::examAccess($x)['ok']): ?><span class="badge badge-danger"><?= icon('lock') ?> نیازمند اعتبار</span><?php endif; ?></span><?php if ($x['best'] !== null): ?><?= progress_ring((float)$x['best'], 54, $x['passed'] ? 'success' : 'danger') ?><?php endif; ?></div>
        <h3 class="mt-1"><?= e($x['title']) ?></h3>
        <div class="small muted"><?= e($x['course_title']) ?></div>
        <div class="course-meta mt-1">
            <?php if ($x['time_limit_minutes']): ?><span><?= icon('clock') ?> <?= fa($x['time_limit_minutes']) ?> دقیقه</span><?php endif; ?>
            <span><?= icon('target') ?> حد قبولی <?= fa((float)$x['pass_score']) ?>٪</span>
            <span><?= icon('refresh-cw') ?> <?= (int)$x['max_attempts'] ? 'امروز ' . fa(min((int)$x['used_today'], (int)$x['max_attempts'])) . ' از ' . fa($x['max_attempts']) : fa($x['used']) . ' بار · نامحدود' ?></span>
        </div>
        <a class="btn btn-sm btn-primary mt-2" href="<?= url('/learn/exam/' . $x['id']) ?>"><?= $x['used'] ? 'جزئیات' : 'شروع آزمون' ?></a>
    </div>
<?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="card empty"><?= icon('clipboard-check') ?><h3>آزمونی ندارید</h3></div><?php endif; ?>
<?php if ($attempts): ?>
<div class="card flush"><div class="card-head"><h3><?= icon('history') ?> تاریخچه شرکت در آزمون‌ها</h3></div>
<div class="table-wrap"><table class="table"><thead><tr><th>آزمون</th><th>دفعه</th><th>تاریخ</th><th>نمره</th><th>نتیجه</th><th></th></tr></thead><tbody>
<?php foreach ($attempts as $a): ?>
    <tr><td><?= e($a['title']) ?></td><td><?= fa($a['attempt_no']) ?></td><td class="num"><?= jdatetime($a['submitted_at']) ?></td><td class="num"><?= $a['percent'] !== null ? fa((float)$a['percent']) . '٪' : '—' ?></td>
    <td><?= $a['status'] === 'pending_review' ? status_badge('pending_review') : ((int)$a['passed'] ? status_badge('passed') : status_badge('failed')) ?></td>
    <td class="actions"><a class="btn btn-sm btn-ghost" href="<?= url('/learn/attempt/' . $a['id'] . '/result') ?>">کارنامه</a></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
