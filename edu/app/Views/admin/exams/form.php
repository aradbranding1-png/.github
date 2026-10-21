<?php
$isEdit = $x !== null;
$v = fn($k, $d = '') => old($k, $x[$k] ?? $d);
$qScores = App\Services\ExamService::scoreMap(array_map(fn($q) => (int)$q['id'], $questions ?? []), array_column($questions ?? [], 'eq_score', 'id'));
$totalScore = array_sum($qScores);
$canEditExam = $isEdit ? can_edit_exam($x) : can('exams.create');
$canEditQ = $isEdit && (can('questions.edit') || $canEditExam);
$lessonName = $isEdit && $x['lesson_id'] ? ($lessons[$x['lesson_id']] ?? null) : (!$isEdit && ($preLesson ?? 0) ? ($lessons[$preLesson] ?? null) : null);
$courseName = $isEdit ? ($x['course_title'] ?? null) : (($preCourse ?? 0) ? ($courses[$preCourse] ?? null) : null);
?>
<div class="crumbs mb-1"><a href="<?= url('/admin/exams') ?>">آزمون‌ها</a><?= $isEdit && $x['course_id'] ? ' / <a href="' . url('/admin/courses/' . $x['course_id'], ['tab' => 'exams']) . '">' . e($x['course_title']) . '</a>' : '' ?><?= $isEdit && $x['lesson_id'] ? ' / <a href="' . url('/admin/lessons/' . $x['lesson_id'] . '/edit') . '#lesson-extras">بازگشت به درس</a>' : '' ?></div>
<div class="card chero" style="--cc:#8b5cf6">
    <div class="chero-top">
        <div class="chero-cover"><?= icon($isEdit ? 'clipboard-check' : 'plus') ?></div>
        <div class="chero-main">
            <h1><?= $isEdit ? e($x['title']) : 'آزمون جدید' ?></h1>
            <div class="chero-tags">
                <?php if ($isEdit): ?><?= status_badge($x['status']) ?><?= $x['is_required'] ? '<span class="badge badge-danger">الزامی</span>' : '' ?><?php endif; ?>
                <?php if ($courseName): ?><span class="chero-cat"><?= e($courseName) ?></span><?php endif; ?>
                <?php if ($lessonName): ?><span class="chero-meta"><?= icon('book-open') ?> <?= e($lessonName) ?></span><?php elseif ($courseName): ?><span class="chero-meta"><?= icon('flag') ?> آزمون پایانی دوره</span><?php endif; ?>
            </div>
        </div>
        <?php if ($isEdit): ?>
        <div class="chero-acts">
            <a class="btn btn-outline" href="<?= url('/learn/exam/' . $x['id']) ?>" target="_blank"><?= icon('eye') ?> پیش‌نمایش</a>
            <?php if (can('exams.report')): ?><a class="btn btn-outline" href="<?= url('/admin/exams/' . $x['id'] . '/report') ?>"><?= icon('chart-column') ?> گزارش</a><?php endif; ?>
            <?php if (can('exams.delete')): ?><form class="inline" method="post" action="<?= url('/admin/exams/' . $x['id'] . '/delete') ?>" data-confirm="آزمون حذف شود؟"><?= csrf_field() ?><button class="btn btn-icon btn-ghost chero-del" title="حذف آزمون"><?= icon('trash-2') ?></button></form><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($isEdit): ?>
    <div class="chero-stats">
        <div class="cs" style="--tc:#8b5cf6"><span class="cs-ic"><?= icon('circle-help') ?></span><span><b><?= fa($x['random_count'] ?: count($questions)) ?></b><small><?= $x['random_count'] ? 'سؤال تصادفی' : 'سؤال' ?></small></span></div>
        <div class="cs" style="--tc:var(--primary)"><span class="cs-ic"><?= icon('award') ?></span><span><b><?= fa($totalScore) ?></b><small>مجموع نمره</small></span></div>
        <div class="cs" style="--tc:var(--warning)"><span class="cs-ic"><?= icon('clock') ?></span><span><b><?= $x['time_limit_minutes'] ? fa($x['time_limit_minutes']) . ' دقیقه' : 'نامحدود' ?></b><small>زمان</small></span></div>
        <div class="cs" style="--tc:var(--success)"><span class="cs-ic"><?= icon('target') ?></span><span><b><?= fa((float)$x['pass_score']) ?>٪</b><small>حد قبولی</small></span></div>
        <div class="cs" style="--tc:#0ea5e9"><span class="cs-ic"><?= icon('repeat') ?></span><span><b><?= $x['max_attempts'] ? fa($x['max_attempts']) . ' بار' : 'نامحدود' ?></b><small>دفعات در روز</small></span></div>
    </div>
    <?php endif; ?>
</div>

<form method="post" action="<?= url($isEdit ? '/admin/exams/' . $x['id'] : '/admin/exams') ?>" class="mb-3">
    <?= csrf_field() ?>
    <fieldset <?= $isEdit && !$canEditExam ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
    <div class="exf exam-f">
        <div class="exf-sec ef-wide">
            <div class="exf-h"><span>۱</span> اطلاعات آزمون</div>
            <div class="form-grid">
                <div class="field full"><label>عنوان <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required placeholder="مثلاً: آزمون درس — گردش پول در بازار کالا"></div>
                <div class="field"><label>دوره</label><select name="course_id" data-lessons-url="<?= url('/admin/exams/lessons') ?>" data-lessons-target="exam-lesson"><option value="">— آزمون مستقل —</option><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('course_id', $preCourse ?: '')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>درس مرتبط</label><select name="lesson_id" id="exam-lesson"><option value="">— آزمون پایانی دوره —</option><?php foreach ($lessons as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('lesson_id', $preLesson ?? '')) ?>><?= e($t) ?></option><?php endforeach; ?></select><div class="hint">بدون انتخاب درس، آزمون پایانی دوره است.</div></div>
                <div class="field full mb-0"><label>توضیحات برای شرکت‌کننده</label><textarea name="description" rows="3" data-rich><?= e($v('description')) ?></textarea></div>
            </div>
        </div>
        <div class="exf-sec">
            <div class="exf-h"><span>۲</span> زمان و قبولی</div>
            <div class="form-grid">
                <div class="field"><label>زمان (دقیقه)</label><input type="number" name="time_limit_minutes" value="<?= e($isEdit ? $v('time_limit_minutes') : old('time_limit_minutes', 10)) ?>" placeholder="خالی = نامحدود"></div>
                <div class="field"><label>حد قبولی (٪) <span class="req">*</span></label><input type="number" name="pass_score" value="<?= e($v('pass_score', 80)) ?>" min="0" max="100" required></div>
                <div class="field full"><label>دفعات مجاز در روز</label><input type="number" name="max_attempts" value="<?= e($v('max_attempts', 1)) ?>" min="0"><div class="hint">۰ = نامحدود. سهمیه هر شب ساعت ۰۰:۰۰ (تهران) از نو شروع می‌شود.</div></div>
                <div class="field mb-0"><label>شروع (شمسی)</label><input class="ltr" type="text" name="available_from" value="<?= e(old('available_from', App\Core\Jalali::input($x['available_from'] ?? null))) ?>" placeholder="1405/07/01"></div>
                <div class="field mb-0"><label>پایان (شمسی)</label><input class="ltr" type="text" name="available_until" value="<?= e(old('available_until', App\Core\Jalali::input($x['available_until'] ?? null))) ?>" placeholder="1405/07/30"></div>
            </div>
        </div>
        <div class="exf-sec">
            <div class="exf-h"><span>۳</span> سؤالات تصادفی <small>اختیاری</small></div>
            <div class="field"><label>تعداد سؤال تصادفی</label><input type="number" name="random_count" value="<?= e($v('random_count')) ?>" min="0" placeholder="خالی = همه سؤالات انتخاب‌شده"></div>
            <div class="field"><label>منبع سؤال‌ها از دسته</label><select name="random_category_id"><option value="">— فقط سؤالات انتخاب‌شده —</option><?php foreach ($qcats as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('random_category_id')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <?php if (can('exams.publish')): ?><div class="field mb-0"><label>وضعیت انتشار</label><select name="status"><?php foreach (['draft', 'published', 'archived'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $isEdit ? $x['status'] : old('status', 'published')) ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        </div>
        <div class="exf-sec ef-wide">
            <div class="exf-h"><span>۴</span> تنظیمات</div>
            <div class="ef-switches">
                <label class="exf-req switch"><input type="checkbox" name="is_required" value="1"<?= checked($v('is_required', 1)) ?>><span><b>الزامی برای تکمیل دوره</b><small>تا قبول نشود، دوره کامل نمی‌شود.</small></span></label>
                <label class="exf-req switch"><input type="checkbox" name="shuffle_questions" value="1"<?= checked($v('shuffle_questions', 1)) ?>><span><b>ترتیب تصادفی سؤالات</b><small>هر نفر ترتیب متفاوتی می‌بیند.</small></span></label>
                <label class="exf-req switch"><input type="checkbox" name="shuffle_options" value="1"<?= checked($v('shuffle_options', 1)) ?>><span><b>ترتیب تصادفی گزینه‌ها</b><small>جای گزینه‌ها جابه‌جا می‌شود.</small></span></label>
                <label class="exf-req switch"><input type="checkbox" name="show_answers" value="1"<?= checked($v('show_answers', 0)) ?>><span><b>نمایش پاسخ صحیح</b><small>بعد از پایان آزمون نشان داده شود.</small></span></label>
            </div>
        </div>
    </div>
    <div class="ef-save"><?php if ($isEdit && $x['course_id']): ?><a class="btn btn-ghost" href="<?= url('/admin/courses/' . $x['course_id'], ['tab' => 'exams']) ?>">انصراف</a><?php endif; ?><button class="btn btn-grad btn-lg"><?= icon('save') ?> <?= $isEdit ? 'ذخیره تغییرات' : 'ساخت آزمون و افزودن سؤال' ?></button></div>
    </fieldset>
</form>

<?php if ($isEdit && !empty($editAll)): include __DIR__ . '/_bulk_questions.php'; elseif ($isEdit): ?>
<form class="card flush" id="questions" method="post" action="<?= url('/admin/exams/' . $x['id'] . '/questions') ?>"><?= csrf_field() ?>
    <div class="card-head flex-wrap" style="padding:1rem 1.25rem;gap:.5rem">
        <h3><?= icon('list-checks') ?> سؤالات آزمون <span class="badge badge-gray"><?= fa(count($questions)) ?> سؤال · <?= fa($totalScore) ?> نمره</span></h3>
        <div class="flex" style="gap:.5rem">
            <?php if ($questions): $showOpts = $showOpts ?? false; $opts = $opts ?? []; ?><a class="btn btn-sm <?= $showOpts ? 'btn-grad' : 'btn-outline' ?>" href="<?= url('/admin/exams/' . $x['id'], $showOpts ? [] : ['opts' => 1]) ?>#questions" title="نمایش همه گزینه‌ها و پاسخ صحیح هر سؤال در یک نگاه"><?= icon('list-checks') ?> <?= $showOpts ? 'پنهان کردن گزینه‌ها' : 'نمایش گزینه‌ها و پاسخ‌ها' ?></a><?php endif; ?>
            <?php if ($canEditQ && $questions): ?><a class="btn btn-sm btn-outline" href="<?= url('/admin/exams/' . $x['id'], ['edit' => 1]) ?>#questions" title="ویرایش متن، گزینه‌ها و پاسخ صحیح همه سؤالات در یک صفحه"><?= icon('file-pen-line') ?> ویرایش همه سؤالات</a><?php endif; ?>
            <?php if ($canEditExam && $questions): ?><button class="btn btn-sm btn-outline"><?= icon('save') ?> ذخیره نمره‌ها</button><?php endif; ?>
            <?php if (can('questions.create')): ?><a class="btn btn-sm btn-grad" href="<?= url('/admin/questions/create', ['exam' => $x['id']]) ?>"><?= icon('plus') ?> سؤال جدید</a><?php endif; ?>
        </div>
    </div>
    <?php if ($questions): ?><div class="crows">
    <?php foreach ($questions as $i => $q): $qUrl = url('/admin/questions/' . $q['id'] . '/edit', ['exam' => $x['id']]); ?>
        <div class="crow qrow">
            <span class="crow-no"><?= fa($i + 1) ?></span>
            <span class="crow-main">
                <?php if ($canEditQ): ?><a class="crow-title" href="<?= $qUrl ?>"><?= e(str_limit($q['text'], 220)) ?></a><?php else: ?><span class="crow-title"><?= e(str_limit($q['text'], 220)) ?></span><?php endif; ?>
                <span class="crow-meta"><span class="crow-tag"><?= icon('circle-help') ?> <?= e(label('qtype', $q['type'])) ?></span></span>
                <?php if (!empty($showOpts)): $qo = $opts[(int)$q['id']] ?? []; ?>
                    <span class="qopts">
                    <?php foreach ($qo as $oi => $o): $ok = !empty($o['is_correct']); ?>
                        <span class="qopt<?= $ok ? ' is-ok' : '' ?>">
                            <span class="qopt-mark"><?= $ok ? icon('circle-check') : '○' ?></span>
                            <span><?= e($o['text']) ?></span>
                            <?php if ($ok): ?><span class="badge badge-success" style="margin-inline-start:auto">پاسخ صحیح</span><?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if (!$qo): ?><span class="small faint">گزینه‌ای ندارد (پاسخ تشریحی / کوتاه)</span><?php elseif (!array_filter($qo, fn($o) => !empty($o['is_correct']))): ?><span class="badge badge-danger">⚠ هیچ گزینه‌ای به‌عنوان پاسخ صحیح علامت نخورده</span><?php endif; ?>
                    </span>
                <?php endif; ?>
            </span>
            <span class="crow-side">
                <label class="q-score" title="خالی = تقسیم خودکار ۱۰۰ نمره بین سؤالات"><span>نمره</span><input type="number" step="0.5" name="score[<?= (int)$q['id'] ?>]" value="<?= e($q['eq_score']) ?>" placeholder="<?= e(rtrim(rtrim(number_format($qScores[(int)$q['id']] ?? 0, 2, '.', ''), '0'), '.')) ?>"></label>
                <span class="crow-acts">
                    <?php if ($canEditQ): ?><a class="btn btn-sm btn-outline" href="<?= $qUrl ?>" title="ویرایش متن، گزینه‌ها و پاسخ صحیح"><?= icon('file-pen-line') ?> ویرایش</a><?php endif; ?>
                    <?php if ($canEditExam): ?><label class="q-remove" title="حذف از این آزمون (با «ذخیره نمره‌ها» اعمال می‌شود)"><input type="checkbox" name="remove[]" value="<?= (int)$q['id'] ?>"><span><?= icon('trash-2') ?></span></label><?php endif; ?>
                </span>
            </span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if ($canEditExam): ?><div class="small faint" style="padding:0 1.25rem 1rem"><?= icon('info') ?> نمره خالی یعنی تقسیم خودکار. برای حذف سؤال، آیکون سطل را بزنید و «ذخیره نمره‌ها» را بزنید؛ سؤال فقط از این آزمون برداشته می‌شود.</div><?php endif; ?>
    <?php else: ?>
        <div class="empty"><?= icon('circle-help') ?><div>هنوز سؤالی طراحی نشده<?= $x['random_count'] && $x['random_category_id'] ? ' — سؤالات به صورت تصادفی از دسته انتخاب‌شده برداشته می‌شود' : '' ?></div><?php if (can('questions.create')): ?><a class="btn btn-grad mt-1" href="<?= url('/admin/questions/create', ['exam' => $x['id']]) ?>"><?= icon('plus') ?> طراحی اولین سؤال</a><?php endif; ?></div>
    <?php endif; ?>
</form>
<?php endif; ?>
