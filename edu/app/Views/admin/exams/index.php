<div class="page-head"><div><h1>آزمون‌ها</h1><div class="sub">آزمون‌های دوره و درس با زمان، حد نصاب، دفعات مجاز و تصادفی‌سازی</div></div>
<div class="btn-group"><a class="btn btn-outline" href="<?= url('/admin/questions') ?>"><?= icon('circle-help') ?> بانک سؤال</a><?php if (can('exams.create')): ?><a class="btn btn-grad" href="<?= url('/admin/exams/create') ?>"><?= icon('plus') ?> آزمون جدید</a><?php endif; ?></div></div>
<form class="card filters" method="get" action="<?= url('/admin/exams') ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>"></div>
    <div class="field"><label>دوره</label><select name="course" data-autosubmit><option value="">همه</option><?php foreach ($courses as $k => $v): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['course'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<?php if (!empty($course)):
    $canCreate = can('exams.create');
    $newUrl = fn(?int $lid) => url('/admin/exams/create', array_filter(['course_id' => $course['id'], 'lesson_id' => $lid]));
    $examChips = function (array $list): string {
        $h = '';
        foreach ($list as $ex) $h .= '<a class="chip" href="' . url('/admin/exams/' . (int)$ex['id']) . '">' . icon('clipboard-check') . ' ' . e($ex['title']) . ($ex['status'] !== 'published' ? ' <span class="faint">(' . e(label('status', $ex['status'])) . ')</span>' : '') . '</a>';
        return $h;
    };
    $withExam = count(array_filter($lessonRows, fn($l) => !empty($examsByLesson[(int)$l['id']])));
    $sec = null; ?>
<div class="card mb-3 ex-lessons">
    <div class="flex between flex-wrap mb-2">
        <div><h3 class="mb-0"><?= icon('book-open') ?> درس‌های دوره «<?= e($course['title']) ?>»</h3>
            <div class="small muted"><?= fa(count($lessonRows)) ?> درس · <?= fa($withExam) ?> درس دارای آزمون — برای هر درس می‌توانید همین‌جا آزمون تعریف کنید.</div></div>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/courses/' . $course['id']) ?>"><?= icon('pencil') ?> مدیریت دوره</a>
    </div>
    <div class="ex-row ex-final">
        <span class="ex-ic"><?= icon('trophy') ?></span>
        <div class="grow"><b>آزمون پایانی دوره</b><div class="ex-chips"><?= $examChips($examsByLesson[0] ?? []) ?: '<span class="small faint">تعریف نشده</span>' ?></div></div>
        <?php if ($canCreate): ?><a class="btn btn-sm btn-primary" href="<?= $newUrl(null) ?>"><?= icon('plus') ?> تعریف آزمون</a><?php endif; ?>
    </div>
    <?php if (!$lessonRows): ?><div class="empty"><?= icon('book-open') ?><div>این دوره هنوز درسی ندارد.</div></div><?php endif; ?>
    <?php $n = 0; foreach ($lessonRows as $l): $n++; $lx = $examsByLesson[(int)$l['id']] ?? [];
        if (($l['section_title'] ?? '') !== '' && $l['section_title'] !== $sec): $sec = $l['section_title']; ?>
        <div class="ex-sec"><?= icon('layers') ?> <?= e($sec) ?></div>
    <?php endif; ?>
    <div class="ex-row<?= $lx ? ' has-exam' : '' ?>">
        <span class="ex-ic"><?= $lx ? icon('check') : fa($n) ?></span>
        <div class="grow"><b><?= e($l['title']) ?></b><?php if ($l['status'] !== 'published'): ?> <span class="badge badge-gray">پیش‌نویس</span><?php endif; ?>
            <?php if ($lx): ?><div class="ex-chips"><?= $examChips($lx) ?></div><?php endif; ?></div>
        <?php if ($canCreate): ?><a class="btn btn-sm <?= $lx ? 'btn-outline' : 'btn-primary' ?>" href="<?= $newUrl((int)$l['id']) ?>"><?= icon('plus') ?> <?= $lx ? 'آزمون دیگر' : 'تعریف آزمون' ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<h3 class="mb-2"><?= icon('clipboard-check') ?> آزمون‌های این دوره</h3>
<?php endif; ?>
<div class="card flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>آزمون</th><th>دوره</th><th>سؤال</th><th>زمان</th><th>قبولی</th><th>شرکت</th><th>میانگین</th><th>نرخ قبولی</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($page['rows'] as $x): ?>
        <tr><td class="fw-b"><a href="<?= url('/admin/exams/' . $x['id']) ?>"><?= e($x['title']) ?></a></td><td class="small"><?= e($x['course_title'] ?? '—') ?></td>
        <td class="num"><?= fa($x['random_count'] ?: $x['qn']) ?></td><td class="num"><?= $x['time_limit_minutes'] ? fa($x['time_limit_minutes']) . '′' : '—' ?></td><td class="num"><?= fa((float)$x['pass_score']) ?>٪</td>
        <td class="num"><?= nf($x['attempts']) ?></td><td class="num"><?= $x['avg_pct'] !== null ? fa(round((float)$x['avg_pct'])) : '—' ?></td>
        <td style="min-width:110px"><?= $x['pass_rate'] !== null ? progress_bar((float)$x['pass_rate'] * 100, 'success') : '—' ?></td>
        <td><?= status_badge($x['status']) ?></td>
        <td class="actions"><a class="btn btn-xs btn-ghost" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= icon('pencil') ?></a><?php if (can('exams.report')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/exams/' . $x['id'] . '/report') ?>"><?= icon('chart-column') ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$page['rows']): ?><tr><td colspan="10"><div class="empty"><?= icon('clipboard-check') ?><div>آزمونی تعریف نشده</div></div></td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= paginate_links($page) ?>
