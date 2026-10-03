<?php $fieldLabels = App\Services\Targeting::FIELDS; ?>
<div class="page-head"><div><h1>تخصیص خودکار (Rule Engine)</h1><div class="sub">مثال: اگر «نوع کاربر = کارمند» و «واحد = مالی» و «سمت = کارشناس» ← دوره‌های مرتبط به صورت خودکار تخصیص داده شوند</div></div>
<?php if (can('rules.create')): ?><a class="btn btn-grad" href="<?= url('/admin/rules/create') ?>"><?= icon('plus') ?> قانون جدید</a><?php endif; ?></div>
<?php if (!$rows): ?><div class="card empty"><?= icon('wand-sparkles') ?><h3>هنوز قانونی تعریف نشده</h3></div><?php endif; ?>
<div class="grid g-auto" style="grid-template-columns:repeat(auto-fill,minmax(360px,1fr))">
<?php foreach ($rows as $r): ?>
    <div class="card" style="border-top:4px solid var(--<?= $r['is_active'] ? 'purple' : 'border-2' ?>)">
        <div class="flex between"><h3 class="mb-0"><?= e($r['name']) ?></h3><?= $r['is_active'] ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>' ?></div>
        <div class="mt-1 small"><b>اگر <?= $r['match_type'] === 'any' ? 'یکی از' : 'همه' ?> شرایط برقرار باشد:</b>
            <ul class="req-list"><?php foreach ($r['conds'] as $c): ?><li><?= icon('check') ?> <?= e($fieldLabels[$c['field']] ?? $c['field']) ?> <?= $c['op'] === 'is_not' ? '≠' : '=' ?> <b><?= e($c['field'] === 'segment' ? label('segment_one', $c['value']) : App\Services\Targeting::targetLabel($c['field'], (int)$c['value'])) ?></b></li><?php endforeach; ?></ul>
        </div>
        <div class="small"><b>آنگاه:</b> <?= $r['path_id'] ? 'مسیر «' . e($r['path_title']) . '»' : 'دوره «' . e($r['course_title']) . '»' ?> — <?= e(label('training_type', $r['training_type'])) ?><?= $r['due_days'] ? ' با مهلت ' . fa($r['due_days']) . ' روز' : '' ?></div>
        <div class="mini-stats mt-1"><div><b><?= fa($r['matches']) ?></b><span>نفر مطابق</span></div><div><b><?= fa($r['last_run_count']) ?></b><span>آخرین اجرا</span></div><div><b class="small"><?= $r['last_run_at'] ? time_ago($r['last_run_at']) : '—' ?></b><span>زمان اجرا</span></div></div>
        <div class="form-actions mt-1">
            <a class="btn btn-sm btn-outline" href="<?= url('/admin/rules/' . $r['id']) ?>"><?= icon('pencil') ?> ویرایش</a>
            <?php if (can('rules.run') && $r['is_active']): ?><form class="inline" method="post" action="<?= url('/admin/rules/' . $r['id'] . '/run') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><?= icon('play') ?> اجرا اکنون</button></form><?php endif; ?>
            <?php if (can('rules.delete')): ?><form class="inline" method="post" action="<?= url('/admin/rules/' . $r['id'] . '/delete') ?>" data-confirm="قانون حذف شود؟"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
