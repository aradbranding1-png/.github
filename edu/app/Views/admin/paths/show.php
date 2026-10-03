<?php $edit = can('paths.edit'); ?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/paths') ?>">مسیرهای آموزشی</a></div><h1><?= e($p['title']) ?></h1><div class="sub"><?= status_badge($p['status']) ?></div></div>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= url('/learn/path/' . $p['id']) ?>" target="_blank"><?= icon('eye') ?> نمای فراگیر</a>
        <?php if (can('assignments.assign')): ?><a class="btn btn-primary" href="<?= url('/admin/assignments', ['path_id' => $p['id']]) ?>"><?= icon('send') ?> تخصیص مسیر</a><?php endif; ?>
        <?php if (can('paths.delete')): ?><form class="inline" method="post" action="<?= url('/admin/paths/' . $p['id'] . '/delete') ?>" data-confirm="مسیر حذف شود؟"><?= csrf_field() ?><button class="btn btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
    </div>
</div>
<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <h3><?= icon('route') ?> مراحل مسیر</h3>
            <?php $sortSteps = $edit && count($steps) > 1; ?>
            <?php if ($sortSteps): ?><div class="small muted mb-1"><?= icon('grip-vertical') ?> برای تغییر ترتیب مراحل، دستگیره را بکشید.</div><?php endif; ?>
            <div class="path-map"<?= $sortSteps ? ' data-sortable="' . url('/admin/paths/' . $p['id'] . '/steps/order') . '"' : '' ?>>
            <?php $n = count($steps); foreach ($steps as $i => $s): ?>
                <div class="pm-step current" style="--x:1" data-id="<?= (int)$s['id'] ?>">
                    <div class="pm-rail"><div class="pm-node" style="animation:none" data-row-no><?= fa($i + 1) ?></div><div class="pm-line"></div></div>
                    <div class="pm-card card">
                        <div class="flex between"><div><b><?= e($s['title'] ?: $s['course_title']) ?></b><div class="small faint">دوره: <a href="<?= url('/admin/courses/' . $s['course_id']) ?>"><?= e($s['course_title']) ?></a> · <?= fa((int)($dist[$i + 1] ?? 0)) ?> نفر در این مرحله</div></div>
                            <?php if ($edit): ?><div class="flex">
                                <?php if ($sortSteps): ?><span class="drag-h" title="جابه‌جایی"><?= icon('grip-vertical') ?></span><?php endif; ?>
                                <form method="post" action="<?= url('/admin/path-steps/' . $s['id'] . '/delete') ?>" data-confirm="مرحله حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form>
                            </div><?php endif; ?>
                        </div>
                        <?php if ($edit): ?>
                        <details class="mt-1"><summary class="small" style="cursor:pointer;color:var(--primary)">شرایط عبور از این مرحله</summary>
                            <form method="post" action="<?= url('/admin/path-steps/' . $s['id']) ?>" class="mt-1"><?= csrf_field() ?>
                                <div class="form-grid">
                                    <div class="field"><label>عنوان مرحله</label><input type="text" name="title" value="<?= e($s['title']) ?>"></div>
                                    <div class="field"><label>حداقل درصد پیشرفت دوره</label><input type="number" name="min_progress" min="0" max="100" value="<?= (int)$s['min_progress'] ?>"></div>
                                    <div class="field"><label>حداقل نمره (اختیاری)</label><input type="number" name="min_score" min="0" max="100" value="<?= e($s['min_score']) ?>"></div>
                                </div>
                                <div class="flex gap-2 mb-1"><label class="switch"><input type="checkbox" name="require_exercises" value="1"<?= checked($s['require_exercises']) ?>> تأیید همه تمرین‌ها</label><label class="switch"><input type="checkbox" name="require_evaluation" value="1"<?= checked($s['require_evaluation']) ?>> قبولی در ارزیابی عملی</label></div>
                                <button class="btn btn-sm btn-primary"><?= icon('save') ?> ذخیره</button>
                            </form>
                        </details>
                        <?php else: ?>
                            <div class="flex flex-wrap small mt-1"><span class="badge badge-gray">پیشرفت ≥ <?= fa((int)$s['min_progress']) ?>٪</span><?= $s['min_score'] !== null ? '<span class="badge badge-gray">نمره ≥ ' . fa((float)$s['min_score']) . '</span>' : '' ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if (!$steps): ?><div class="empty"><?= icon('route') ?><div>مرحله‌ای ندارد. اولین دوره را اضافه کنید.</div></div><?php endif; ?>
            <?php if ($edit): ?>
            <form class="card mt-2" method="post" action="<?= url('/admin/paths/' . $p['id'] . '/steps') ?>" style="background:var(--surface-2)"><?= csrf_field() ?>
                <h4><?= icon('plus') ?> افزودن مرحله</h4>
                <div class="form-grid">
                    <div class="field"><label>دوره</label><select name="course_id" required><option value="">—</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?><?= $c['status'] !== 'published' ? ' (پیش‌نویس)' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>عنوان مرحله (اختیاری)</label><input type="text" name="title" placeholder="مثلاً: مرحله اول — مبانی تجارت"></div>
                    <div class="field"><label>حداقل پیشرفت</label><input type="number" name="min_progress" value="100" min="0" max="100"></div>
                    <div class="field"><label>حداقل نمره</label><input type="number" name="min_score" min="0" max="100"></div>
                </div>
                <div class="flex gap-2 mb-1"><label class="switch"><input type="checkbox" name="require_exercises" value="1"> تأیید تمرین‌ها</label><label class="switch"><input type="checkbox" name="require_evaluation" value="1"> ارزیابی عملی</label></div>
                <button class="btn btn-primary"><?= icon('plus') ?> افزودن</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="stack">
        <?php if ($edit): ?>
        <form class="card" method="post" action="<?= url('/admin/paths/' . $p['id']) ?>"><?= csrf_field() ?>
            <h3><?= icon('pencil') ?> مشخصات مسیر</h3>
            <div class="field"><label>عنوان</label><input type="text" name="title" value="<?= e($p['title']) ?>" required></div>
            <div class="field"><label>توضیحات</label><textarea name="description" rows="3"><?= e($p['description']) ?></textarea></div>
            <div class="field"><label>گروه هدف</label><select name="target_segment"><option value="">همه</option><?php foreach (['merchant', 'employee', 'agent'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $p['target_segment']) ?>><?= e(label('segment', $s)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>گروه خاص</label><select name="group_id"><option value="">—</option><?php foreach ($groups as $k => $v): ?><option value="<?= (int)$k ?>"<?= selected($k, $p['group_id']) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>رنگ</label><input class="ltr" type="text" name="color" value="<?= e($p['color']) ?>"></div>
            <?php if (can('paths.publish')): ?><div class="field"><label>وضعیت انتشار</label><select name="status"><?php foreach (['draft', 'published', 'archived'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $p['status']) ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <button class="btn btn-primary"><?= icon('save') ?> ذخیره</button>
        </form>
        <?php endif; ?>
        <div class="card"><h3><?= icon('users') ?> فراگیران مسیر (<?= fa(count($learners)) ?>)</h3>
            <?php foreach ($learners as $l): ?><div class="list-item"><?= avatar_html(['id' => $l['user_id'], 'first_name' => $l['first_name'], 'last_name' => $l['last_name'], 'avatar_path' => $l['avatar_path']], 'sm') ?><div class="grow"><a class="small fw-b" href="<?= url('/admin/reports/user/' . $l['user_id']) ?>"><?= e($l['first_name'] . ' ' . $l['last_name']) ?></a><?= progress_bar((float)$l['progress_pct']) ?></div><span class="small">مرحله <?= fa($l['current_step']) ?></span></div><?php endforeach; ?>
            <?php if (!$learners): ?><div class="faint small">هنوز کسی در این مسیر نیست.</div><?php endif; ?>
        </div>
    </div>
</div>
