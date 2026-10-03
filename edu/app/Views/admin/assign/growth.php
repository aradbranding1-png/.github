<?php $stageFields = function (?array $s) use ($group, $courses, $icons) { $req = array_filter(array_map('intval', explode(',', (string)($s['req_course_ids'] ?? '')))); ob_start(); ?>
    <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
    <div class="form-grid">
        <div class="field"><label>نام مرحله</label><input type="text" name="name" value="<?= e($s['name'] ?? '') ?>" required></div>
        <div class="field"><label>ترتیب</label><input type="number" name="sort" value="<?= (int)($s['sort'] ?? 1) ?>"></div>
        <div class="field"><label>رنگ</label><input class="ltr" type="text" name="color" value="<?= e($s['color'] ?? '#10b981') ?>"></div>
        <div class="field"><label>آیکون</label><select name="icon"><?php foreach (array_merge(['mountain', 'flag'], $icons) as $i): ?><option value="<?= $i ?>"<?= selected($i, $s['icon'] ?? 'mountain') ?>><?= $i ?></option><?php endforeach; ?></select></div>
        <div class="field full" style="grid-column:1/-1"><label>توضیحات</label><input type="text" name="description" value="<?= e($s['description'] ?? '') ?>"></div>
        <div class="field full" style="grid-column:1/-1"><label>دوره‌های الزامی برای رسیدن به این مرحله</label><select name="req_course_ids[]" multiple size="4"><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $req) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>حداقل میانگین پیشرفت (٪)</label><input type="number" name="req_min_progress" min="0" max="100" value="<?= e($s['req_min_progress'] ?? '') ?>"></div>
        <div class="field"><label>حداقل میانگین نمره آزمون</label><input type="number" name="req_min_avg_score" min="0" max="100" value="<?= e($s['req_min_avg_score'] ?? '') ?>"></div>
        <div class="field"><label>تعداد تمرین تأییدشده</label><input type="number" name="req_exercises" value="<?= e($s['req_exercises'] ?? '') ?>"></div>
    </div>
    <div class="flex flex-wrap gap-2 mb-1"><label class="switch"><input type="checkbox" name="req_evaluation" value="1"<?= checked($s['req_evaluation'] ?? 0) ?>> قبولی در ارزیابی عملی</label><label class="switch"><input type="checkbox" name="auto_promote" value="1"<?= checked($s['auto_promote'] ?? 1) ?>> ارتقای خودکار</label></div>
<?php return ob_get_clean(); }; ?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/growth') ?>">نظام رشد تاجر</a></div><h1>نظام رشد قبلی (آرشیو)</h1><div class="sub">مراحل و سوابق نظام رشد گروهی قبلی برای حفظ تاریخچه نگه داشته شده است. نظام رشد فعال سامانه، «نظام رشد تاجر» است.</div></div><a class="btn btn-outline" href="<?= url('/admin/growth') ?>"><?= icon('trending-up') ?> نظام رشد تاجر</a></div>
<div class="pill-nav"><?php foreach ($groups as $g): ?><a class="<?= $group && (int)$g['id'] === (int)$group['id'] ? 'active' : '' ?>" href="<?= url('/admin/growth/legacy', ['group' => $g['id']]) ?>"><?= e($g['name']) ?></a><?php endforeach; ?></div>
<?php if ($group): ?>
<div class="grid g-main mb-3">
    <div class="card">
        <div class="card-head"><h3><?= icon('mountain') ?> مراحل رشد «<?= e($group['name']) ?>»</h3><span class="faint small"><?= fa($members) ?> عضو</span></div>
        <?php $current = null; include APP_PATH . '/Views/partials/growth_ladder.php'; ?>
        <?php if (!$stages): ?><div class="empty"><?= icon('mountain') ?><div>مرحله‌ای تعریف نشده</div></div><?php endif; ?>
    </div>
    <div class="card"><h3><?= icon('chart-column') ?> توزیع افراد در مراحل</h3><div class="chart" data-chart='<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>' data-height="200"></div></div>
</div>
<div class="grid g-main">
    <div class="stack">
        <?php foreach ($stages as $s): ?>
            <details class="card" style="border-right:5px solid <?= e($s['color']) ?>">
                <summary class="flex between" style="cursor:pointer"><span class="flex"><span style="color:<?= e($s['color']) ?>"><?= icon($s['icon']) ?></span><b>مرحله <?= fa($s['sort']) ?>: <?= e($s['name']) ?></b></span><span class="badge badge-gray"><?= fa((int)($counts[$s['id']] ?? 0)) ?> نفر</span></summary>
                <?php if (can('growth.edit')): ?>
                <form method="post" action="<?= url('/admin/growth/legacy/stages/' . $s['id']) ?>" class="mt-2"><?= csrf_field() ?><?= $stageFields($s) ?><button class="btn btn-sm btn-primary"><?= icon('save') ?> ذخیره</button></form>
                <?php endif; ?>
                <?php if (can('growth.delete')): ?><form method="post" action="<?= url('/admin/growth/legacy/stages/' . $s['id'] . '/delete') ?>" data-confirm="مرحله حذف شود؟" class="mt-1"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف مرحله</button></form><?php endif; ?>
            </details>
        <?php endforeach; ?>
        <?php if (can('growth.create')): ?>
        <form class="card" method="post" action="<?= url('/admin/growth/legacy/stages') ?>"><?= csrf_field() ?><h3><?= icon('plus') ?> مرحله جدید</h3><?= $stageFields(['sort' => count($stages) + 1]) ?><button class="btn btn-primary"><?= icon('plus') ?> افزودن مرحله</button></form>
        <?php endif; ?>
    </div>
    <div class="stack">
        <?php if (can('growth.approve') && $stages): ?>
        <form class="card" method="post" action="<?= url('/admin/growth/legacy/promote') ?>"><?= csrf_field() ?>
            <h3><?= icon('trending-up') ?> تعیین دستی مرحله رشد</h3>
            <div class="field"><input type="search" data-filter-select="gr-user" placeholder="جست‌وجو…" class="mb-1"><select id="gr-user" name="user_id" size="6" required><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e(full_name($u)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>مرحله</label><select name="stage_id"><?php foreach ($stages as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>یادداشت</label><input type="text" name="note"></div>
            <button class="btn btn-primary w-100"><?= icon('check') ?> ثبت</button>
        </form>
        <?php endif; ?>
        <div class="card"><h3><?= icon('history') ?> آخرین ارتقاها</h3>
            <div class="timeline"><?php foreach ($recent as $h): ?><div class="tl-item success"><div class="t small"><?= e($h['first_name'] . ' ' . $h['last_name']) ?> ← <?= e($h['stage_name']) ?></div><div class="d"><?= time_ago($h['created_at']) ?> · <?= e($h['note']) ?></div></div><?php endforeach; ?></div>
            <?php if (!$recent): ?><div class="faint small">هنوز ارتقایی ثبت نشده</div><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
