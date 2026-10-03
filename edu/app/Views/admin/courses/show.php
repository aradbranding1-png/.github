<?php $n = (int)$stats['n']; ?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/courses') ?>">دوره‌ها</a></div><h1><?= e($c['title']) ?></h1>
        <div class="sub flex flex-wrap"><?= status_badge($c['status']) ?><span class="badge badge-gray"><?= e(label('training_type', $c['training_type'])) ?></span><?= $c['category_name'] ? '<span class="badge badge-primary">' . e($c['category_name']) . '</span>' : '' ?><?= $c['target_segment'] ? '<span class="badge badge-gray">' . e(label('segment', $c['target_segment'])) . '</span>' : '' ?><?= $instructor ? '<span class="badge badge-gray">' . icon('user') . ' ' . e(full_name($instructor)) . '</span>' : '' ?></div></div>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= url('/learn/course/' . $c['id']) ?>" target="_blank"><?= icon('eye') ?> پیش‌نمایش</a>
        <?php if (can('courses.edit')): ?><a class="btn btn-outline" href="<?= url('/admin/courses/' . $c['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش</a><?php endif; ?>
        <?php if (can('courses.publish')): ?>
            <?php if ($c['status'] !== 'published'): ?>
                <form class="inline" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/publish') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="published"><label class="check small" style="display:inline-flex"><input type="checkbox" name="notify" value="1" checked> اعلان به مخاطبان</label> <button class="btn btn-success"><?= icon('rocket') ?> انتشار</button></form>
            <?php else: ?>
                <form class="inline" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/publish') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="draft"><button class="btn btn-outline"><?= icon('eye-off') ?> لغو انتشار</button></form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (can('courses.delete')): ?><form class="inline" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/delete') ?>" data-confirm="دوره حذف شود؟ سوابق آموزشی کاربران حفظ می‌شود."><?= csrf_field() ?><button class="btn btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
    </div>
</div>

<div class="grid g-5 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= nf($n) ?></div><div class="l">فراگیر</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= nf($stats['done']) ?></div><div class="l">تکمیل (<?= fa($n ? round($stats['done'] * 100 / $n) : 0) ?>٪)</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('circle-play') ?></div><div><div class="v"><?= nf($stats['active']) ?></div><div class="l">در حال انجام</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('gauge') ?></div><div><div class="v"><?= fa(round((float)$stats['prog'])) ?>٪</div><div class="l">میانگین پیشرفت</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('award') ?></div><div><div class="v"><?= $stats['score'] !== null ? fa(round((float)$stats['score'])) : '—' ?></div><div class="l">میانگین نمره</div></div></div>
</div>

<?php if ((int)$c['has_certificate'] === 1 && !$exams): ?>
<div class="alert alert-warning"><?= icon('triangle-alert') ?><div><b>این دوره گواهی دارد ولی آزمونی ندارد.</b> گواهی با تکمیل همه درس‌ها<?= $exercises ? ' و تمرین‌های الزامی' : '' ?> صادر می‌شود و «حداقل نمره قبولی» (<?= fa((float)$c['pass_score']) ?>٪) اعمال نمی‌شود. برای سنجش، از تب «آزمون‌ها» یک آزمون الزامی اضافه کنید.</div></div>
<?php endif; ?>
<div class="tabs">
    <a class="<?= $tab === 'lessons' ? 'active' : '' ?>" href="<?= url('/admin/courses/' . $c['id']) ?>"><?= icon('list') ?> درس‌ها (<?= fa(count($lessons)) ?>)</a>
    <a class="<?= $tab === 'exams' ? 'active' : '' ?>" href="<?= url('/admin/courses/' . $c['id'], ['tab' => 'exams']) ?>"><?= icon('clipboard-check') ?> آزمون‌ها (<?= fa(count($exams)) ?>)</a>
    <a class="<?= $tab === 'exercises' ? 'active' : '' ?>" href="<?= url('/admin/courses/' . $c['id'], ['tab' => 'exercises']) ?>"><?= icon('notebook-pen') ?> تمرین‌ها (<?= fa(count($exercises)) ?>)</a>
    <a class="<?= $tab === 'learners' ? 'active' : '' ?>" href="<?= url('/admin/courses/' . $c['id'], ['tab' => 'learners']) ?>"><?= icon('users') ?> فراگیران</a>
</div>

<?php if ($tab === 'lessons'): ?>
<div class="card flush">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('list') ?> درس‌ها و سرفصل‌ها</h3><?php if (can('lessons.create')): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/courses/' . $c['id'] . '/lessons/create') ?>"><?= icon('plus') ?> درس جدید</a><?php endif; ?></div>
    <?php if (can('lessons.edit') && count($lessons) > 1): ?><div class="drag-hint"><?= icon('grip-vertical') ?> برای تغییر ترتیب، ردیف درس را با ماوس بگیرید و بالا یا پایین بکشید — ترتیب خودکار ذخیره می‌شود.</div><?php endif; ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>#</th><th>عنوان</th><th>سرفصل</th><th>نوع</th><th>مشاهده / تکمیل</th><th>وضعیت</th><th></th></tr></thead>
        <tbody<?= can('lessons.edit') && count($lessons) > 1 ? ' data-sortable="' . url('/admin/courses/' . $c['id'] . '/lessons/order') . '" data-row-drag' : '' ?>>
        <?php foreach ($lessons as $i => $l): ?>
            <tr data-id="<?= (int)$l['id'] ?>">
                <td class="num nowrap"><?php if (can('lessons.edit') && count($lessons) > 1): ?><span class="drag-h" title="برای جابه‌جایی بکشید"><?= icon('grip-vertical') ?></span><?php endif; ?><span data-row-no><?= fa($i + 1) ?></span></td>
                <td class="fw-b"><?= e($l['title']) ?><?= $l['is_preview'] ? ' <span class="badge badge-info">پیش‌نمایش</span>' : '' ?><?= $l['prerequisite_lesson_id'] ? ' <span class="badge badge-gray">' . icon('lock') . ' پیش‌نیاز</span>' : '' ?></td>
                <td class="small"><?= e($l['section_title'] ?? '—') ?></td>
                <td><span class="badge badge-gray"><?= e(label('content_type', $l['content_type'])) ?></span></td>
                <td class="num"><?= fa($l['viewers']) ?> / <?= fa($l['completers']) ?></td>
                <td><?= status_badge($l['status']) ?></td>
                <td class="actions">
                    <?php if (can('lessons.edit')): ?>
                        <form class="inline" method="post" action="<?= url('/admin/lessons/' . $l['id'] . '/move') ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn btn-xs btn-ghost" title="انتقال به پایین"><?= icon('chevron-right') ?></button></form>
                        <form class="inline" method="post" action="<?= url('/admin/lessons/' . $l['id'] . '/move') ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn btn-xs btn-ghost" title="انتقال به بالا"><?= icon('chevron-left') ?></button></form>
                        <a class="btn btn-xs btn-ghost" href="<?= url('/admin/lessons/' . $l['id'] . '/edit') ?>"><?= icon('pencil') ?></a>
                        <?php if ($moveTargets): ?><button type="button" class="btn btn-xs btn-ghost" title="انتقال به دوره دیگر" data-move-lesson="<?= (int)$l['id'] ?>" data-title="<?= e($l['title']) ?>" data-section="<?= e($l['section_title'] ?? '') ?>"><?= icon('arrow-left-right') ?></button><?php endif; ?>
                    <?php endif; ?>
                    <a class="btn btn-xs btn-ghost" href="<?= url('/learn/lesson/' . $l['id']) ?>" target="_blank"><?= icon('eye') ?></a>
                    <?php if (can('lessons.delete')): ?><form class="inline" method="post" action="<?= url('/admin/lessons/' . $l['id'] . '/delete') ?>" data-confirm="درس حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$lessons): ?><tr><td colspan="7"><div class="empty"><?= icon('list') ?><div>هنوز درسی اضافه نشده</div></div></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php if ($moveTargets): ?>
<dialog class="modal" id="move-dialog">
    <form method="post" class="modal-body" data-move-form data-base="<?= url('/admin/lessons/') ?>"><?= csrf_field() ?>
        <h3><?= icon('arrow-left-right') ?> انتقال درس به دوره دیگر</h3>
        <p class="muted small mb-2">درس «<b data-move-title></b>» همراه با فایل‌ها، آزمون و تمرین همین درس و سوابق پیشرفت فراگیران به دوره مقصد منتقل می‌شود.</p>
        <div class="field"><label>دوره مقصد <span class="req">*</span></label>
            <input type="search" data-filter-select="move-course" placeholder="جست‌وجوی دوره…" class="mb-1">
            <select id="move-course" name="course_id" size="6" required><?php foreach ($moveTargets as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['title']) ?><?= $t['status'] !== 'published' ? ' (' . e(label('status', $t['status'])) . ')' : '' ?></option><?php endforeach; ?></select>
        </div>
        <div class="field"><label>سرفصل در دوره مقصد</label>
            <select name="section_mode" data-section-mode><option value="keep">همان سرفصل فعلی: «<span data-move-section></span>»</option><option value="pick">انتخاب از سرفصل‌های دوره مقصد</option><option value="none">بدون سرفصل</option></select>
            <select name="section" class="mt-1 hide" data-section-pick></select>
        </div>
        <label class="check small mb-2"><input type="checkbox" name="after" value="target"> بعد از انتقال، دوره مقصد را باز کن</label>
        <div class="flex between"><button class="btn btn-primary"><?= icon('check') ?> انتقال درس</button><button type="button" class="btn btn-ghost" data-close-dialog>انصراف</button></div>
    </form>
</dialog>
<script type="application/json" id="move-sections"><?= json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php if ($prereq): ?><div class="card mt-2"><b><?= icon('git-branch') ?> پیش‌نیازها:</b> <?php foreach ($prereq as $p): ?><a class="badge badge-gray" href="<?= url('/admin/courses/' . $p['id']) ?>"><?= e($p['title']) ?></a> <?php endforeach; ?></div><?php endif; ?>

<?php elseif ($tab === 'exams'): ?>
<div class="card flush">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('clipboard-check') ?> آزمون‌های دوره</h3><?php if (can('exams.create')): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/exams/create', ['course_id' => $c['id']]) ?>"><?= icon('plus') ?> آزمون جدید</a><?php endif; ?></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>عنوان</th><th>سؤال</th><th>زمان</th><th>قبولی</th><th>دفعات در روز</th><th>شرکت‌کننده</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($exams as $x): ?><tr><td class="fw-b"><?= e($x['title']) ?> <?= $x['is_required'] ? '<span class="badge badge-danger">الزامی</span>' : '' ?></td><td class="num"><?= fa($x['random_count'] ?: $x['qn']) ?></td><td class="num"><?= $x['time_limit_minutes'] ? fa($x['time_limit_minutes']) . ' دقیقه' : '—' ?></td><td class="num"><?= fa((float)$x['pass_score']) ?>٪</td><td class="num"><?= $x['max_attempts'] ? fa($x['max_attempts']) : '∞' ?></td><td class="num"><?= fa($x['attempts']) ?></td><td><?= status_badge($x['status']) ?></td>
        <td class="actions"><a class="btn btn-xs btn-ghost" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= icon('pencil') ?></a><?php if (can('exams.report')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/exams/' . $x['id'] . '/report') ?>"><?= icon('chart-column') ?></a><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$exams): ?><tr><td colspan="8" class="faint text-center">آزمونی تعریف نشده</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<?php elseif ($tab === 'exercises'): ?>
<div class="grid g-main">
    <div class="card flush">
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('notebook-pen') ?> تمرین‌ها</h3></div>
        <?php foreach ($exercises as $x): ?>
            <details class="list-item" style="display:block;padding:.8rem 1.25rem">
                <summary class="flex between" style="cursor:pointer"><b><?= e($x['title']) ?></b><span class="flex"><?= $x['is_required'] ? '<span class="badge badge-danger">الزامی</span>' : '' ?><span class="badge badge-gray"><?= fa($x['subs']) ?> ارسال</span><?php if ($x['pending']): ?><span class="badge badge-warning"><?= fa($x['pending']) ?> منتظر بررسی</span><?php endif; ?></span></summary>
                <?php if (can('lessons.edit')): ?>
                <form method="post" action="<?= url('/admin/exercises/' . $x['id']) ?>" class="mt-1"><?= csrf_field() ?>
                    <?php $ex = $x; include __DIR__ . '/_exercise_fields.php'; ?>
                    <div class="flex"><button class="btn btn-sm btn-primary"><?= icon('save') ?> ذخیره</button></div>
                </form>
                <?php endif; ?>
                <?php if (can('lessons.delete')): ?><form method="post" action="<?= url('/admin/exercises/' . $x['id'] . '/delete') ?>" data-confirm="تمرین حذف شود؟" class="mt-1"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف</button></form><?php endif; ?>
            </details>
        <?php endforeach; ?>
        <?php if (!$exercises): ?><div class="empty"><?= icon('notebook-pen') ?><div>تمرینی تعریف نشده</div></div><?php endif; ?>
    </div>
    <?php if (can('lessons.create')): ?>
    <form class="card" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/exercises') ?>"><?= csrf_field() ?>
        <h3><?= icon('plus') ?> تمرین جدید</h3>
        <?php $ex = null; include __DIR__ . '/_exercise_fields.php'; ?>
        <button class="btn btn-primary"><?= icon('plus') ?> افزودن تمرین</button>
    </form>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="card flush">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('users') ?> فراگیران دوره</h3>
        <div class="btn-group"><?php if (can('courses.export')): ?><a class="btn btn-sm btn-outline" href="<?= url('/admin/courses/' . $c['id'] . '/export') ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?><?php if (can('assignments.assign')): ?><a class="btn btn-sm btn-primary" href="<?= url('/admin/assignments', ['course_id' => $c['id']]) ?>"><?= icon('send') ?> تخصیص</a><?php endif; ?></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>فراگیر</th><th>منبع</th><th>پیشرفت</th><th>نمره</th><th>وضعیت</th><th>مهلت</th><th>آخرین فعالیت</th></tr></thead><tbody>
    <?php foreach ($learners['rows'] as $e): ?><tr><td><a class="person" href="<?= url('/admin/reports/user/' . $e['user_id']) ?>" style="color:var(--text)"><?= avatar_html(['id' => $e['user_id'], 'first_name' => $e['first_name'], 'last_name' => $e['last_name'], 'avatar_path' => $e['avatar_path']], 'sm') ?><span class="nm"><?= e($e['first_name'] . ' ' . $e['last_name']) ?></span></a></td>
        <td class="small"><?= e(['self' => 'ثبت‌نام خود', 'assignment' => 'تخصیص', 'rule' => 'قانون خودکار', 'path' => 'مسیر', 'api' => 'API'][$e['source']] ?? $e['source']) ?></td>
        <td style="min-width:130px"><div class="flex"><div class="grow"><?= progress_bar((float)$e['progress_pct']) ?></div><span class="small"><?= fa((int)$e['progress_pct']) ?>٪</span></div></td>
        <td class="num"><?= $e['score'] !== null ? fa((float)$e['score']) : '—' ?></td><td><?= status_badge($e['status']) ?></td><td class="num small"><?= $e['due_at'] ? jdate($e['due_at']) : '—' ?></td><td class="small"><?= time_ago($e['last_activity_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$learners['rows']): ?><tr><td colspan="7" class="faint text-center">فراگیری ندارد</td></tr><?php endif; ?>
    </tbody></table></div>
    <div style="padding:0 1rem 1rem"><?= paginate_links($learners) ?></div>
</div>
<?php endif; ?>
