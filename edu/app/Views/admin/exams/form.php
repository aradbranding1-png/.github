<?php
$isEdit = $x !== null;
$v = fn($k, $d = '') => old($k, $x[$k] ?? $d);
$qScores = App\Services\ExamService::scoreMap(array_map(fn($q) => (int)$q['id'], $questions ?? []), array_column($questions ?? [], 'eq_score', 'id'));
$totalScore = array_sum($qScores);
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/exams') ?>">آزمون‌ها</a><?= $isEdit && $x['course_id'] ? ' / <a href="' . url('/admin/courses/' . $x['course_id'], ['tab' => 'exams']) . '">' . e($x['course_title']) . '</a>' : '' ?><?= $isEdit && $x['lesson_id'] ? ' / <a href="' . url('/admin/lessons/' . $x['lesson_id'] . '/edit') . '#lesson-extras">بازگشت به درس</a>' : '' ?></div><h1><?= $isEdit ? e($x['title']) : 'آزمون جدید' ?></h1><?= $isEdit ? '<div class="sub">' . status_badge($x['status']) . '</div>' : '' ?></div>
    <?php if ($isEdit): ?><div class="btn-group">
        <a class="btn btn-outline" href="<?= url('/learn/exam/' . $x['id']) ?>" target="_blank"><?= icon('eye') ?> پیش‌نمایش</a>
        <?php if (can('exams.report')): ?><a class="btn btn-outline" href="<?= url('/admin/exams/' . $x['id'] . '/report') ?>"><?= icon('chart-column') ?> گزارش</a><?php endif; ?>
        <?php if (can('exams.delete')): ?><form class="inline" method="post" action="<?= url('/admin/exams/' . $x['id'] . '/delete') ?>" data-confirm="آزمون حذف شود؟"><?= csrf_field() ?><button class="btn btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?>
    </div><?php endif; ?>
</div>
<form method="post" action="<?= url($isEdit ? '/admin/exams/' . $x['id'] : '/admin/exams') ?>" class="card mb-3">
    <?= csrf_field() ?>
    <fieldset <?= $isEdit && !can('exams.edit') ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
    <div class="form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <div class="field" style="grid-column:span 2"><label>عنوان <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required></div>
        <div class="field"><label>دوره</label><select name="course_id" data-lessons-url="<?= url('/admin/exams/lessons') ?>" data-lessons-target="exam-lesson"><option value="">— آزمون مستقل —</option><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('course_id', $preCourse ?: '')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>درس مرتبط (اختیاری)</label><select name="lesson_id" id="exam-lesson"><option value="">— آزمون پایانی دوره —</option><?php foreach ($lessons as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('lesson_id', $preLesson ?? '')) ?>><?= e($t) ?></option><?php endforeach; ?></select><div class="hint">با انتخاب دوره، درس‌های آن در این فهرست می‌آید؛ اگر درسی انتخاب نشود، آزمون پایانی دوره است.</div></div>
        <div class="field"><label>زمان (دقیقه، خالی = نامحدود)</label><input type="number" name="time_limit_minutes" value="<?= e($isEdit ? $v('time_limit_minutes') : old('time_limit_minutes', 10)) ?>"></div>
        <div class="field"><label>حد نصاب قبولی (٪)</label><input type="number" name="pass_score" value="<?= e($v('pass_score', 80)) ?>" min="0" max="100" required></div>
        <div class="field"><label>تعداد دفعات مجاز در روز (۰ = نامحدود)</label><input type="number" name="max_attempts" value="<?= e($v('max_attempts', 1)) ?>" min="0"><div class="hint">هر فراگیر در هر روز حداکثر همین تعداد بار می‌تواند آزمون بدهد؛ سهمیه هر شب ساعت ۰۰:۰۰ (به وقت تهران) از نو شروع می‌شود.</div></div>
        <div class="field"><label>تعداد سؤال تصادفی (اختیاری)</label><input type="number" name="random_count" value="<?= e($v('random_count')) ?>"></div>
        <div class="field"><label>منبع سؤال تصادفی از دسته</label><select name="random_category_id"><option value="">— فقط سؤالات انتخاب‌شده —</option><?php foreach ($qcats as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $v('random_category_id')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>شروع (شمسی، اختیاری)</label><input class="ltr" type="text" name="available_from" value="<?= e(old('available_from', App\Core\Jalali::input($x['available_from'] ?? null))) ?>" placeholder="1405/07/01"></div>
        <div class="field"><label>پایان (شمسی، اختیاری)</label><input class="ltr" type="text" name="available_until" value="<?= e(old('available_until', App\Core\Jalali::input($x['available_until'] ?? null))) ?>" placeholder="1405/07/30"></div>
        <?php if (can('exams.publish')): ?><div class="field"><label>وضعیت انتشار</label><select name="status"><?php foreach (['draft', 'published', 'archived'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $isEdit ? $x['status'] : old('status', 'published')) ?>><?= e(label('status', $s)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="field full" style="grid-column:1/-1"><label>توضیحات برای شرکت‌کننده</label><textarea name="description" rows="2"><?= e($v('description')) ?></textarea></div>
    </div>
    <div class="flex flex-wrap gap-2 mb-2">
        <label class="switch"><input type="checkbox" name="is_required" value="1"<?= checked($v('is_required', 1)) ?>> الزامی برای تکمیل دوره</label>
        <label class="switch"><input type="checkbox" name="shuffle_questions" value="1"<?= checked($v('shuffle_questions', 1)) ?>> ترتیب تصادفی سؤالات</label>
        <label class="switch"><input type="checkbox" name="shuffle_options" value="1"<?= checked($v('shuffle_options', 1)) ?>> ترتیب تصادفی گزینه‌ها</label>
        <label class="switch"><input type="checkbox" name="show_answers" value="1"<?= checked($v('show_answers', 0)) ?>> نمایش پاسخ صحیح پس از آزمون</label>
    </div>
    <button class="btn btn-grad"><?= icon('save') ?> ذخیره آزمون</button>
    </fieldset>
</form>

<?php if ($isEdit): ?>
<div class="grid g-2" id="questions">
    <form class="card flush" method="post" action="<?= url('/admin/exams/' . $x['id'] . '/questions') ?>"><?= csrf_field() ?>
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('list-checks') ?> سؤالات آزمون (<?= fa(count($questions)) ?>) · مجموع نمره <?= fa($totalScore) ?></h3><?php if (can('exams.edit')): ?><button class="btn btn-sm btn-primary"><?= icon('save') ?> ذخیره نمره‌ها</button><?php endif; ?></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>سؤال</th><th>نوع</th><th>نمره <span class="faint small" title="خالی = تقسیم خودکار">(خودکار)</span></th><th>حذف</th></tr></thead><tbody>
        <?php foreach ($questions as $i => $q): ?><tr><td class="num"><?= fa($i + 1) ?></td><td class="small"><?= e(str_limit($q['text'], 90)) ?></td><td><span class="badge badge-gray"><?= e(label('qtype', $q['type'])) ?></span></td>
            <td><input type="number" step="0.5" name="score[<?= (int)$q['id'] ?>]" value="<?= e($q['eq_score']) ?>" placeholder="<?= e(rtrim(rtrim(number_format($qScores[(int)$q['id']] ?? 0, 2, '.', ''), '0'), '.')) ?>" title="خالی = تقسیم خودکار ۱۰۰ نمره بین سؤالات" style="width:80px;padding:.3rem"></td>
            <td><input type="checkbox" name="remove[]" value="<?= (int)$q['id'] ?>" class="check"></td></tr><?php endforeach; ?>
        <?php if (!$questions): ?><tr><td colspan="5" class="faint text-center">سؤالی اضافه نشده<?= $x['random_count'] && $x['random_category_id'] ? ' — سؤالات به صورت تصادفی از دسته انتخاب‌شده برداشته می‌شود' : '' ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </form>
    <div class="card flush">
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('circle-help') ?> افزودن از بانک سؤال</h3><?php if (can('questions.create')): ?><a class="btn btn-sm btn-outline" href="<?= url('/admin/questions/create', ['exam' => $x['id']]) ?>"><?= icon('plus') ?> سؤال جدید</a><?php endif; ?></div>
        <form class="filters" method="get" action="<?= url('/admin/exams/' . $x['id']) ?>" style="padding:0 1.25rem">
            <div class="field grow"><input type="search" name="qs" value="<?= e($_GET['qs'] ?? '') ?>" placeholder="متن یا برچسب"></div>
            <div class="field"><select name="qcat"><option value="">همه دسته‌ها</option><?php foreach ($qcats as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['qcat'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><select name="qtype"><option value="">همه انواع</option><?php foreach (App\Core\Labels::QTYPE as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $_GET['qtype'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-sm btn-outline"><?= icon('search') ?></button>
        </form>
        <form method="post" action="<?= url('/admin/exams/' . $x['id'] . '/questions') ?>"><?= csrf_field() ?>
            <div class="table-wrap" style="max-height:460px"><table class="table"><tbody>
            <?php foreach ($bank as $q): ?><tr><td><input type="checkbox" name="add[]" value="<?= (int)$q['id'] ?>" class="check"></td><td class="small"><?= e(str_limit($q['text'], 90)) ?><div class="faint"><?= e($q['cat_name'] ?? '') ?> · <?= e(App\Core\Labels::DIFFICULTY[(int)$q['difficulty']] ?? '') ?></div></td><td><span class="badge badge-gray"><?= e(label('qtype', $q['type'])) ?></span></td></tr><?php endforeach; ?>
            <?php if (!$bank): ?><tr><td class="faint text-center">سؤالی یافت نشد</td></tr><?php endif; ?>
            </tbody></table></div>
            <?php if (can('exams.edit')): ?><div style="padding:1rem 1.25rem"><button class="btn btn-primary"><?= icon('plus') ?> افزودن انتخاب‌شده‌ها</button></div><?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>
