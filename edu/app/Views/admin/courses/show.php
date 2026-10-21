<?php
$n = (int)$stats['n'];
$donePct = $n ? (int)round($stats['done'] * 100 / $n) : 0;
$cColor = $c['category_color'] ?: '#6366f1';
$cCover = image_url($c['image_file_id'] ?? null, 320);
?>
<div class="crumbs mb-1"><a href="<?= url('/admin/courses') ?>">دوره‌ها</a> / <?= e($c['title']) ?></div>
<div class="card chero" style="--cc:<?= e($cColor) ?>">
    <div class="chero-top">
        <div class="chero-cover"><?php if ($cCover): ?><img src="<?= e($cCover) ?>" alt=""><?php else: ?><?= icon('graduation-cap') ?><?php endif; ?></div>
        <div class="chero-main">
            <h1><?= e($c['title']) ?></h1>
            <div class="chero-tags">
                <?= status_badge($c['status']) ?>
                <span class="badge badge-<?= $c['training_type'] === 'mandatory' ? 'danger' : 'gray' ?>"><?= e(label('training_type', $c['training_type'])) ?></span>
                <?php if ($c['category_name']): ?><span class="chero-cat"><?= e($c['category_name']) ?></span><?php endif; ?>
                <span class="chero-meta"><?= icon('users') ?> مخاطبان: <?= e(App\Services\CourseAudience::label((int)$c['id'])) ?></span>
                <?php if ($instructor): ?><span class="chero-meta"><?= icon('user') ?> <?= e(full_name($instructor)) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="chero-acts">
            <?php if (can('reports.report') || can('courses.report')): ?><a class="btn btn-primary" href="<?= url('/admin/reports/course/' . $c['id']) ?>"><?= icon('chart-column') ?> گزارش مدیریتی</a><?php endif; ?>
            <a class="btn btn-outline" href="<?= url('/learn/course/' . $c['id']) ?>" target="_blank"><?= icon('eye') ?> پیش‌نمایش</a>
            <?php if (can('courses.edit')): ?><a class="btn btn-outline" href="<?= url('/admin/courses/' . $c['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش</a><?php endif; ?>
            <?php if (can('courses.publish')): ?>
                <?php if ($c['status'] !== 'published'): ?>
                    <form class="inline chero-pub" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/publish') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="published"><label class="check small"><input type="checkbox" name="notify" value="1" checked> اعلان به مخاطبان</label><button class="btn btn-success"><?= icon('rocket') ?> انتشار</button></form>
                <?php else: ?>
                    <form class="inline" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/publish') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="draft"><button class="btn btn-ghost" title="لغو انتشار"><?= icon('eye-off') ?> لغو انتشار</button></form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (can('courses.delete')): ?><form class="inline" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/delete') ?>" data-confirm="دوره حذف شود؟ سوابق آموزشی کاربران حفظ می‌شود."><?= csrf_field() ?><button class="btn btn-icon btn-ghost chero-del" title="حذف دوره"><?= icon('trash-2') ?></button></form><?php endif; ?>
        </div>
    </div>
    <div class="chero-stats">
        <div class="cs" style="--tc:var(--primary)"><span class="cs-ic"><?= icon('users') ?></span><span><b><?= nf($n) ?></b><small>فراگیر</small></span></div>
        <div class="cs" style="--tc:var(--success)"><span class="cs-ic"><?= icon('circle-check') ?></span><span><b><?= nf($stats['done']) ?> <i><?= fa($donePct) ?>٪</i></b><small>تکمیل کرده</small></span></div>
        <div class="cs" style="--tc:#0ea5e9"><span class="cs-ic"><?= icon('circle-play') ?></span><span><b><?= nf($stats['active']) ?></b><small>در حال یادگیری</small></span></div>
        <div class="cs" style="--tc:var(--warning)"><span class="cs-ic"><?= icon('gauge') ?></span><span><b><?= fa(round((float)$stats['prog'])) ?>٪</b><small>میانگین پیشرفت</small></span></div>
        <div class="cs" style="--tc:#8b5cf6"><span class="cs-ic"><?= icon('award') ?></span><span><b><?= $stats['score'] !== null ? fa(round((float)$stats['score'])) : '—' ?></b><small>میانگین نمره</small></span></div>
    </div>
</div>

<?php if ((int)$c['has_certificate'] === 1 && !$exams): ?>
<div class="alert alert-warning"><?= icon('triangle-alert') ?><div><b>این دوره گواهی دارد ولی آزمونی ندارد.</b> گواهی با تکمیل همه درس‌ها<?= $exercises ? ' و تمرین‌های الزامی' : '' ?> صادر می‌شود و «حداقل نمره قبولی» (<?= fa((float)$c['pass_score']) ?>٪) اعمال نمی‌شود. برای سنجش، از تب «آزمون‌ها» یک آزمون الزامی اضافه کنید.</div></div>
<?php endif; ?>
<?php $tabList = [
    'lessons' => ['درس‌ها', 'list', count($lessons), url('/admin/courses/' . $c['id'])],
    'exams' => ['آزمون‌ها', 'clipboard-check', count($exams), url('/admin/courses/' . $c['id'], ['tab' => 'exams'])],
    'exercises' => ['تمرین‌ها', 'notebook-pen', count($exercises), url('/admin/courses/' . $c['id'], ['tab' => 'exercises'])],
    'learners' => ['فراگیران', 'users', $n, url('/admin/courses/' . $c['id'], ['tab' => 'learners'])],
]; ?>
<nav class="ctabs" aria-label="بخش‌های دوره">
    <?php foreach ($tabList as $k => [$t, $ti, $cnt, $u]): ?>
        <a class="<?= $tab === $k || ($k === 'learners' && !isset($tabList[$tab])) ? 'active' : '' ?>" href="<?= $u ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($ti) ?><span><?= $t ?></span><em><?= fa($cnt) ?></em></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'lessons'): ?>
<?php $sortOn = can('lessons.edit') && count($lessons) > 1; $ctIc = ['video' => 'video', 'audio' => 'music', 'pdf' => 'file-text', 'image' => 'image', 'file' => 'file', 'link' => 'link', 'text' => 'file-text']; ?>
<div class="card flush">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('list') ?> درس‌ها و سرفصل‌ها</h3><?php if (can('lessons.create')): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/courses/' . $c['id'] . '/lessons/create') ?>"><?= icon('plus') ?> درس جدید</a><?php endif; ?></div>
    <?php if ($sortOn): ?><div class="drag-hint"><?= icon('grip-vertical') ?> برای تغییر ترتیب، کارت درس را با ماوس بگیرید و بالا یا پایین بکشید — ترتیب خودکار ذخیره می‌شود.</div><?php endif; ?>
    <?php if ($lessons): ?>
    <div class="crows"<?= $sortOn ? ' data-sortable="' . url('/admin/courses/' . $c['id'] . '/lessons/order') . '" data-row-drag' : '' ?>>
    <?php foreach ($lessons as $i => $l): ?>
        <div class="crow" data-id="<?= (int)$l['id'] ?>">
            <?php if ($sortOn): ?><span class="drag-h" title="برای جابه‌جایی بکشید"><?= icon('grip-vertical') ?></span><?php endif; ?>
            <span class="crow-no" data-row-no><?= fa($i + 1) ?></span>
            <span class="crow-ic" style="--tc:var(--info,#0ea5e9)"><?= icon($ctIc[$l['content_type']] ?? 'file') ?></span>
            <span class="crow-main">
                <?php if (can('lessons.edit')): ?><a class="crow-title" href="<?= url('/admin/lessons/' . $l['id'] . '/edit') ?>"><?= e($l['title']) ?></a><?php else: ?><span class="crow-title"><?= e($l['title']) ?></span><?php endif; ?>
                <span class="crow-meta">
                    <span class="crow-tag"><?= icon($ctIc[$l['content_type']] ?? 'file') ?> <?= e(label('content_type', $l['content_type'])) ?></span>
                    <?php if ($l['duration_minutes']): ?><span><?= icon('clock') ?> <?= fa($l['duration_minutes']) ?> دقیقه</span><?php endif; ?>
                    <?php if ($l['section_title']): ?><span><?= icon('layers') ?> <?= e($l['section_title']) ?></span><?php endif; ?>
                    <span title="مشاهده / تکمیل"><?= icon('eye') ?> <?= fa($l['viewers']) ?> مشاهده · <?= fa($l['completers']) ?> تکمیل</span>
                    <?php if ($l['is_preview']): ?><span class="crow-info">پیش‌نمایش رایگان</span><?php endif; ?>
                    <?php if ($l['prerequisite_lesson_id']): ?><span><?= icon('lock') ?> پیش‌نیاز دارد</span><?php endif; ?>
                </span>
            </span>
            <span class="crow-side">
                <?= status_badge($l['status']) ?>
                <span class="crow-acts">
                    <?php if (can('lessons.edit')): ?>
                        <a class="btn btn-sm btn-outline" href="<?= url('/admin/lessons/' . $l['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش</a>
                        <?php if ($moveTargets): ?><button type="button" class="btn btn-icon btn-ghost" title="انتقال به دوره دیگر" data-move-lesson="<?= (int)$l['id'] ?>" data-title="<?= e($l['title']) ?>" data-section="<?= e($l['section_title'] ?? '') ?>"><?= icon('arrow-left-right') ?></button><?php endif; ?>
                    <?php endif; ?>
                    <a class="btn btn-icon btn-ghost" title="نمایش برای فراگیر" href="<?= url('/learn/lesson/' . $l['id']) ?>" target="_blank"><?= icon('eye') ?></a>
                    <?php if (can('lessons.delete')): ?><form class="inline" method="post" action="<?= url('/admin/lessons/' . $l['id'] . '/delete') ?>" data-confirm="درس حذف شود؟"><?= csrf_field() ?><button class="btn btn-icon btn-ghost crow-del" title="حذف"><?= icon('trash-2') ?></button></form><?php endif; ?>
                </span>
            </span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?><div class="empty"><?= icon('list') ?><div>هنوز درسی اضافه نشده</div></div><?php endif; ?>
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
    <?php if ($exams): ?><div class="crows">
    <?php foreach ($exams as $x): ?>
        <div class="crow is-link">
            <span class="crow-ic" style="--tc:var(--purple,#8b5cf6)"><?= icon('clipboard-check') ?></span>
            <span class="crow-main">
                <a class="crow-title stretch" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= e($x['title']) ?></a>
                <span class="crow-meta">
                    <span class="crow-tag"><?= icon('circle-help') ?> <?= fa($x['random_count'] ?: $x['qn']) ?> سؤال<?= $x['random_count'] ? ' (تصادفی)' : '' ?></span>
                    <span><?= icon('clock') ?> <?= $x['time_limit_minutes'] ? fa($x['time_limit_minutes']) . ' دقیقه' : 'بدون محدودیت' ?></span>
                    <span><?= icon('target') ?> قبولی <?= fa((float)$x['pass_score']) ?>٪</span>
                    <span><?= icon('repeat') ?> <?= $x['max_attempts'] ? fa($x['max_attempts']) . ' بار در روز' : 'دفعات نامحدود' ?></span>
                    <span><?= icon('users') ?> <?= fa($x['attempts']) ?> شرکت</span>
                    <?php if ($x['is_required']): ?><span class="crow-req">الزامی</span><?php endif; ?>
                    <?php if (!(int)$x['qn'] && !$x['random_count']): ?><span class="crow-warn"><?= icon('triangle-alert') ?> بدون سؤال</span><?php endif; ?>
                </span>
            </span>
            <span class="crow-side">
                <?= status_badge($x['status']) ?>
                <span class="crow-acts">
                    <?php if (can('exams.report')): ?><a class="btn btn-icon btn-ghost" title="گزارش آزمون" href="<?= url('/admin/exams/' . $x['id'] . '/report') ?>"><?= icon('chart-column') ?></a><?php endif; ?>
                    <a class="btn btn-sm btn-outline" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= icon('pencil') ?> ویرایش</a>
                </span>
            </span>
        </div>
    <?php endforeach; ?>
    </div><?php else: ?><div class="empty"><?= icon('clipboard-check') ?><div>آزمونی تعریف نشده</div></div><?php endif; ?>
</div>

<?php elseif ($tab === 'exercises'): ?>
<div class="card flush">
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('notebook-pen') ?> تمرین‌های دوره</h3><?php if (can('lessons.create')): ?><button type="button" class="btn btn-primary btn-sm" data-open="dlg-new-ex"><?= icon('plus') ?> تمرین جدید</button><?php endif; ?></div>
        <?php $fmtNames = ['text' => ['متن', 'message-square'], 'audio' => ['صوت', 'music'], 'file' => ['Word/PDF', 'file-text'], 'image' => ['تصویر', 'image'], 'video' => ['ویدیو', 'video']]; $lessonTitles = array_column($lessons, 'title', 'id'); ?>
        <?php if ($exercises): ?><div class="crows">
        <?php foreach ($exercises as $x): $canEx = can_edit_exercise($x); $exUrl = $canEx ? url('/admin/exercises/' . $x['id'] . '/edit') : url('/learn/exercise/' . $x['id']); ?>
            <div class="crow is-link" id="ex-<?= (int)$x['id'] ?>">
                <span class="crow-ic" style="--tc:var(--warning)"><?= icon('notebook-pen') ?></span>
                <span class="crow-main">
                    <a class="crow-title stretch" href="<?= $exUrl ?>"><?= e($x['title']) ?></a>
                    <span class="crow-meta">
                        <?php foreach (array_intersect_key($fmtNames, array_flip(explode(',', (string)$x['formats']))) as [$fn, $fi]): ?><span class="crow-tag"><?= icon($fi) ?> <?= e($fn) ?></span><?php endforeach; ?>
                        <?php if (!empty($lessonTitles[$x['lesson_id']])): ?><span><?= icon('book-open') ?> <?= e($lessonTitles[$x['lesson_id']]) ?></span><?php endif; ?>
                        <span><?= icon('send') ?> <?= fa($x['subs']) ?> ارسال</span>
                        <?php if ($x['pending']): ?><span class="crow-warn"><?= fa($x['pending']) ?> منتظر بررسی</span><?php endif; ?>
                        <?php if ($x['is_required']): ?><span class="crow-req">الزامی</span><?php endif; ?>
                    </span>
                </span>
                <span class="crow-side">
                    <?= status_badge($x['status']) ?>
                    <span class="crow-acts"><a class="btn btn-sm btn-outline" href="<?= $exUrl ?>"><?= icon($canEx ? 'pencil' : 'eye') ?> <?= $canEx ? 'ویرایش' : 'مشاهده' ?></a></span>
                </span>
            </div>
        <?php endforeach; ?>
        </div><?php endif; ?>
        <?php if (!$exercises): ?><div class="empty"><?= icon('notebook-pen') ?><div>تمرینی تعریف نشده</div></div><?php endif; ?>
</div>
<?php if (can('lessons.create')): ?>
<dialog class="modal" id="dlg-new-ex" style="max-width:760px"><div class="modal-head"><b><?= icon('plus') ?> تمرین جدید</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
<form class="modal-body" method="post" action="<?= url('/admin/courses/' . $c['id'] . '/exercises') ?>"><?= csrf_field() ?>
    <?php $ex = null; include __DIR__ . '/_exercise_fields.php'; ?>
    <div class="flex"><button class="btn btn-primary"><?= icon('plus') ?> افزودن تمرین</button><button type="button" class="btn btn-ghost" data-close>انصراف</button></div>
</form>
</dialog>
<?php endif; ?>

<?php else: ?>
<?php $srcNames = ['self' => 'ثبت‌نام خود', 'assignment' => 'تخصیص', 'rule' => 'قانون خودکار', 'path' => 'مسیر', 'api' => 'API']; ?>
<div class="card flush">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('users') ?> فراگیران دوره</h3>
        <div class="btn-group"><?php if (can('courses.export')): ?><a class="btn btn-sm btn-outline" href="<?= url('/admin/courses/' . $c['id'] . '/export') ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?><?php if (can('assignments.assign')): ?><a class="btn btn-sm btn-primary" href="<?= url('/admin/assignments', ['course_id' => $c['id']]) ?>"><?= icon('send') ?> تخصیص</a><?php endif; ?></div></div>
    <?php if ($learners['rows']): ?><div class="crows">
    <?php foreach ($learners['rows'] as $e): $pp = (int)$e['progress_pct']; ?>
        <div class="crow is-link">
            <?= avatar_html(['id' => $e['user_id'], 'first_name' => $e['first_name'], 'last_name' => $e['last_name'], 'avatar_path' => $e['avatar_path']], 'md') ?>
            <span class="crow-main">
                <a class="crow-title stretch" href="<?= url('/admin/reports/user/' . $e['user_id']) ?>"><?= e($e['first_name'] . ' ' . $e['last_name']) ?></a>
                <span class="crow-meta">
                    <span class="crow-tag"><?= icon('user-plus') ?> <?= e($srcNames[$e['source']] ?? $e['source']) ?></span>
                    <span><?= icon('activity') ?> <?= $e['last_activity_at'] ? time_ago($e['last_activity_at']) : 'بدون فعالیت' ?></span>
                    <?php if ($e['due_at']): ?><span><?= icon('calendar') ?> مهلت <?= jdate($e['due_at']) ?></span><?php endif; ?>
                    <?php if ($e['score'] !== null): ?><span><?= icon('award') ?> نمره <?= fa((float)$e['score']) ?></span><?php endif; ?>
                </span>
            </span>
            <span class="crow-side">
                <span class="crow-prog"><?= progress_bar((float)$pp) ?><b><?= fa($pp) ?>٪</b></span>
                <?= status_badge($e['status']) ?>
            </span>
        </div>
    <?php endforeach; ?>
    </div><?php else: ?><div class="empty"><?= icon('users') ?><div>فراگیری ندارد</div></div><?php endif; ?>
    <div style="padding:0 1rem 1rem"><?= paginate_links($learners) ?></div>
</div>
<?php endif; ?>
