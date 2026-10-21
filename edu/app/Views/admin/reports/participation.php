<?php
use App\Core\Labels;
use App\Core\Jalali;

$base = $lesson ? '/admin/reports/lesson/' . $lesson['id'] : '/admin/reports/course/' . $c['id'];
$keep = aud_q() + ['period' => $range['key'], 'from' => $range['key'] === 'custom' ? ($_GET['from'] ?? '') : '', 'to' => $range['key'] === 'custom' ? ($_GET['to'] ?? '') : '',
    'segment' => $R['segment'], 'q' => $R['q'], 'tab' => $R['tab'] === 'in' ? '' : $R['tab'], 'audience' => $R['audience'] === 'all' ? 'all' : '',
    'task' => $R['tab'] === 'task' ? ($R['task']['type'] . '-' . $R['task']['id'] . '-' . $R['task']['state']) : ''];
$assess = $assess ?? ['exams' => [], 'exercises' => []];
$taskRows = $taskRows ?? [];
$lessonSec = $lesson && in_array($lesson['content_type'], ['video', 'audio'], true) ? App\Services\CompletionAudit::expectedSeconds($lesson) : 0;
$mm = fn(int $s) => $s >= 60 ? fa(intdiv($s, 60)) . ' دقیقه' . ($s % 60 >= 30 && $s < 600 ? ' و نیم' : '') : fa($s) . ' ثانیه';
$overview = $overview ?? null; $funnel = $funnel ?? []; $userLessons = $userLessons ?? [];
$isIn = in_array($R['tab'], ['in', 'done'], true);
// «done» = completed (validly) at least one lesson in the period — NOT the whole course
$doneLabel = $lesson ? 'درس را تکمیل کردند' : 'درس تکمیل کردند';
$doneTip = $lesson ? 'این درس را در این بازه به‌طور معتبر تکمیل کردند (محتوا دیده شده و آزمون/تمرین الزامی درس انجام شده)' : 'حداقل یک درس از این دوره را در این بازه به‌طور معتبر تکمیل کردند — به معنی تمام کردن کل دوره نیست';
$examStateTone = ['took' => 'primary', 'passed' => 'success', 'failed' => 'danger', 'pending' => 'warning', 'none' => 'gray'];
$exStateTone = ['sent' => 'primary', 'accepted' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'none' => 'gray'];
$link = function (string $path, array $over = []) use ($keep): string {
    return url($path, array_filter(array_merge($keep, $over), fn($v) => $v !== '' && $v !== null));
};
$segs = array_intersect_key(Labels::SEGMENT, array_flip(['merchant', 'employee', 'agent', 'custom']));
$rate = ($R['n_in'] + $R['n_out']) ? round($R['n_in'] * 100 / ($R['n_in'] + $R['n_out'])) : 0;
$audLabel = $R['audience'] === 'all' ? 'همه کاربران فعال' : 'ثبت‌نام‌شدگان دوره';
?>
<div class="crumbs mb-1"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a> / <a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a><?= $lesson ? ' / ' . e($lesson['title']) : '' ?></div>
<div class="card chero" style="--cc:<?= e(($c['category_color'] ?? '') ?: '#6366f1') ?>">
    <div class="chero-top">
        <div class="chero-cover"><?= icon('chart-column') ?></div>
        <div class="chero-main">
            <h1><?= e($lesson ? $lesson['title'] : $c['title']) ?></h1>
            <div class="chero-tags">
                <span class="badge badge-primary">گزارش مدیریتی <?= $lesson ? 'درس' : 'دوره' ?></span>
                <?php if ($lesson): ?><a class="chero-cat" href="<?= $link('/admin/reports/course/' . $c['id']) ?>"><?= e($c['title']) ?></a><?php endif; ?>
                <span class="chero-meta"><?= icon('calendar') ?> <?= e($range['label']) ?><?php if ($range['from_j']): ?> <span class="ltr">(<?= e($range['from_j']) ?> تا <?= e($range['to_j']) ?>)</span><?php endif; ?></span>
            </div>
        </div>
        <div class="chero-acts">
            <?php if (can('reports.export')): ?><a class="btn btn-primary" href="<?= $link($base, ['export' => 1]) ?>"><?= icon('file-spreadsheet') ?> خروجی Excel</a><?php endif; ?>
            <a class="btn btn-outline" href="<?= url($lesson ? '/learn/lesson/' . $lesson['id'] : '/learn/course/' . $c['id']) ?>"><?= icon('eye') ?> مشاهده <?= $lesson ? 'درس' : 'دوره' ?></a>
            <details class="dropdown chero-more"><summary class="btn btn-icon btn-ghost" title="بیشتر"><?= icon('ellipsis-vertical') ?></summary>
                <div class="dd-menu">
                    <?php if ($lesson): ?><a href="<?= $link('/admin/reports/course/' . $c['id']) ?>"><?= icon('chart-pie') ?> گزارش کل دوره</a><?php endif; ?>
                    <?php if ($lesson && can('lessons.edit')): ?><a href="<?= url('/admin/lessons/' . $lesson['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش درس</a><?php endif; ?>
                    <?php if (can('courses.view')): ?><a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= icon('settings') ?> مدیریت دوره</a><?php endif; ?>
                </div>
            </details>
        </div>
    </div>
    <div class="chero-stats">
        <a class="cs" style="--tc:var(--primary)" href="<?= $link($base, ['tab' => '', 'page' => '']) ?>#people"><span class="cs-ic"><?= icon('user-check') ?></span><span><b><?= nf($R['n_in']) ?></b><small>شرکت کردند</small></span></a>
        <a class="cs" style="--tc:var(--danger)" href="<?= $link($base, ['tab' => 'out', 'page' => '']) ?>#people"><span class="cs-ic"><?= icon('user-x') ?></span><span><b><?= nf($R['n_out']) ?></b><small>شرکت نکردند</small></span></a>
        <a class="cs" style="--tc:var(--success)" title="<?= e($doneTip) ?>" href="<?= $link($base, ['tab' => 'done', 'page' => '']) ?>#people"><span class="cs-ic"><?= icon('circle-check') ?></span><span><b><?= nf($R['completed']) ?></b><small><?= $doneLabel ?></small></span></a>
        <div class="cs" style="--tc:#0ea5e9"><span class="cs-ic"><?= icon('eye') ?></span><span><b><?= nf($R['views']) ?></b><small>دفعات مشاهده</small></span></div>
        <div class="cs" style="--tc:#8b5cf6"><span class="cs-ic"><?= icon('gauge') ?></span><span><b><?= fa($rate) ?>٪</b><small>نرخ مشارکت</small></span></div>
    </div>
</div>
<?php if (!$lesson && $overview): $on = (int)$overview['n']; $pct = fn($x) => $on ? (int)round($x * 100 / $on) : 0; $started = $on - (int)$overview['ns']; ?>
<div class="card mb-3 cov">
    <div class="cov-head"><h3><?= icon('chart-pie') ?> وضعیت کلی دوره</h3><span class="small muted">همه ثبت‌نام‌شدگان، از ابتدای دوره تا امروز</span></div>
    <div class="cov-funnel">
        <div class="cov-step" style="--tc:var(--primary)"><b><?= nf($on) ?></b><span>ثبت‌نام‌شده</span><i style="width:100%"></i></div>
        <div class="cov-step" style="--tc:#0ea5e9"><b><?= nf($started) ?> <small><?= fa($pct($started)) ?>٪</small></b><span>شروع کرده</span><i style="width:<?= $pct($started) ?>%"></i></div>
        <div class="cov-step" style="--tc:var(--warning)"><b><?= nf($overview['active']) ?> <small><?= fa($pct($overview['active'])) ?>٪</small></b><span>در حال یادگیری</span><i style="width:<?= $pct($overview['active']) ?>%"></i></div>
        <div class="cov-step" style="--tc:var(--success)"><b><?= nf($overview['done']) ?> <small><?= fa($pct($overview['done'])) ?>٪</small></b><span>کل دوره را تمام کرده</span><i style="width:<?= $pct($overview['done']) ?>%"></i></div>
    </div>
    <div class="cov-kpis">
        <div><span><?= icon('gauge') ?> میانگین پیشرفت</span><b><?= fa(round((float)$overview['prog'])) ?>٪</b></div>
        <div><span><?= icon('award') ?> میانگین نمره</span><b><?= $overview['score'] !== null ? fa(round((float)$overview['score'])) : '—' ?></b></div>
        <div><span><?= icon('user-plus') ?> ثبت‌نام جدید در این بازه</span><b><?= nf($overview['new_in']) ?></b></div>
        <div><span><?= icon('graduation-cap') ?> پایان دوره در این بازه</span><b><?= nf($overview['done_in']) ?></b></div>
        <div><span><?= icon('user-x') ?> هنوز شروع نکرده</span><b><?= nf($overview['ns']) ?></b></div>
    </div>
</div>
<?php endif; ?>
<?php $audSegments = false; include APP_PATH . '/Views/partials/report_audience.php'; ?>


<div class="card mb-3">
    <div class="pill-nav" style="margin-bottom:.75rem">
        <?php foreach (App\Services\Participation::PRESETS as $k => $t): ?><a class="<?= $range['key'] === $k ? 'active' : '' ?>" href="<?= $link($base, ['period' => $k, 'from' => '', 'to' => '', 'page' => '']) ?>"><?= e($t) ?></a><?php endforeach; ?>
    </div>
    <div class="flex flex-wrap" style="gap:1rem;align-items:flex-end">
    <form class="filters" method="get" action="<?= url($base) ?><?= aud_hidden() ?>" style="margin:0">
        <?php foreach (['segment', 'tab', 'audience', 'q'] as $h): if ($keep[$h] !== ''): ?><input type="hidden" name="<?= $h ?>" value="<?= e($keep[$h]) ?>"><?php endif; endforeach; ?>
        <div class="field"><label><?= icon('calendar') ?> ماه</label>
            <select name="period">
                <?php foreach ($months as $k => $t): ?><option value="<?= e($k) ?>"<?= selected($k, $range['key']) ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-outline"><?= icon('filter') ?> نمایش ماه</button>
    </form>
    <form class="filters" method="get" action="<?= url($base) ?><?= aud_hidden() ?>" style="margin:0">
        <input type="hidden" name="period" value="custom">
        <?php foreach (['segment', 'tab', 'audience', 'q'] as $h): if ($keep[$h] !== ''): ?><input type="hidden" name="<?= $h ?>" value="<?= e($keep[$h]) ?>"><?php endif; endforeach; ?>
        <div class="field"><label>از تاریخ (شمسی)</label><input class="ltr" type="text" name="from" value="<?= e($range['key'] === 'custom' ? ($_GET['from'] ?? '') : '') ?>" placeholder="<?= e(Jalali::format('Y/m/01', time())) ?>"></div>
        <div class="field"><label>تا تاریخ (شمسی)</label><input class="ltr" type="text" name="to" value="<?= e($range['key'] === 'custom' ? ($_GET['to'] ?? '') : '') ?>" placeholder="<?= e(Jalali::format('Y/m/d', time())) ?>"></div>
        <button class="btn btn-primary"><?= icon('filter') ?> بازه دلخواه</button>
    </form>
    </div>
</div>

<div class="pill-nav">
    <a class="<?= $R['segment'] === '' ? 'active' : '' ?>" href="<?= $link($base, ['segment' => '', 'page' => '']) ?>">همه (<?= fa(array_sum($R['seg_in'])) ?> شرکت‌کننده)</a>
    <?php foreach ($segs as $k => $t): ?><a class="<?= $R['segment'] === $k ? 'active' : '' ?>" href="<?= $link($base, ['segment' => $k, 'page' => '']) ?>"><?= e($t) ?> (<?= fa($R['seg_in'][$k] ?? 0) ?>)</a><?php endforeach; ?>
    <a class="<?= $R['tab'] === 'done' ? 'active' : '' ?>" href="<?= $link($base, ['tab' => 'done', 'page' => '']) ?>#people" style="<?= $R['tab'] === 'done' ? '' : 'border-color:var(--success);color:var(--success)' ?>"><?= icon('circle-check') ?> <?= $doneLabel ?> (<?= fa($R['completed']) ?>)</a>
</div>

<div class="grid g-main mb-3">
    <div class="card flush">
        <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('users') ?> تفکیک بر اساس نوع کاربر</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>نوع کاربر</th><th>شرکت کردند</th><th>شرکت نکردند</th><th><?= e($audLabel) ?></th><th>نرخ مشارکت</th></tr></thead><tbody>
        <?php foreach ($segs as $k => $t): $in = $R['seg_in'][$k] ?? 0; $out = $R['seg_out'][$k] ?? 0; $aud = $R['seg_aud'][$k] ?? 0; if (!$in && !$out && !$aud) continue; ?>
            <tr<?= $R['segment'] === $k ? ' style="background:var(--primary-soft)"' : '' ?>><td class="fw-b"><a href="<?= $link($base, ['segment' => $k, 'page' => '']) ?>"><?= e($t) ?></a></td>
                <td class="num"><a href="<?= $link($base, ['segment' => $k, 'tab' => '', 'page' => '']) ?>"><?= nf($in) ?></a></td>
                <td class="num"><a href="<?= $link($base, ['segment' => $k, 'tab' => 'out', 'page' => '']) ?>" style="color:var(--danger)"><?= nf($out) ?></a></td>
                <td class="num"><?= nf($aud) ?></td>
                <td style="min-width:120px"><?= progress_bar(($in + $out) ? $in * 100 / ($in + $out) : 0, 'success') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="small muted" style="padding:.75rem 1.25rem">
            جامعه «شرکت‌نکرده‌ها»:
            <a class="<?= $R['audience'] === 'enrolled' ? 'fw-b' : '' ?>" href="<?= $link($base, ['audience' => '', 'page' => '']) ?>">ثبت‌نام‌شدگان این دوره</a> |
            <a class="<?= $R['audience'] === 'all' ? 'fw-b' : '' ?>" href="<?= $link($base, ['audience' => 'all', 'page' => '']) ?>">همه کاربران فعال سامانه</a>
        </div>
    </div>
    <div class="card">
        <h3><?= icon('chart-column') ?> روند روزانه (نفر)</h3>
        <?php if ($R['daily']): $max = max(1, max($R['daily'])); ?>
            <div style="display:flex;align-items:flex-end;gap:2px;height:150px;direction:ltr;margin-top:.5rem">
                <?php foreach ($R['daily'] as $d => $n): ?><div title="<?= e(jdate($d) . ' — ' . fa($n) . ' نفر') ?>" style="flex:1;min-width:3px;height:<?= max(2, round($n * 100 / $max)) ?>%;background:<?= $n ? 'var(--primary)' : 'var(--border)' ?>;border-radius:3px 3px 0 0"></div><?php endforeach; ?>
            </div>
            <div class="flex small faint" style="justify-content:space-between;direction:ltr;margin-top:.35rem"><span><?= jdate(array_key_first($R['daily'])) ?></span><span>بیشترین: <?= fa($max) ?> نفر</span><span><?= jdate(array_key_last($R['daily'])) ?></span></div>
        <?php else: ?>
            <div class="empty small"><?= icon('calendar') ?><div>نمودار روزانه برای بازه‌های ۲ تا ۶۲ روزه نمایش داده می‌شود.</div></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($lesson && ($assess['exams'] || $assess['exercises'])): ?>
<div class="card mb-3">
    <div class="flex between flex-wrap mb-1"><h3 class="mb-0"><?= icon('clipboard-check') ?> آزمون و تمرین این درس</h3><span class="small muted">وضعیت فعلیِ ثبت‌نام‌شدگان دوره<?= $R['segment'] ? ' (' . e(label('segment', $R['segment'])) . ')' : '' ?> — روی هر عدد بزنید تا اسامی را ببینید</span></div>
    <div class="grid g-2">
    <?php foreach ($assess['exams'] as $x): ?>
        <div class="assess-box">
            <div class="assess-head"><span class="ico" style="background:var(--purple-soft);color:var(--purple)"><?= icon('clipboard-check') ?></span><div class="grow"><b><?= e($x['title']) ?></b><div class="small faint">آزمون <?= (int)$x['is_required'] ? 'الزامی' : 'اختیاری' ?> · حد قبولی <?= fa((float)$x['pass_score']) ?>٪ · مجموع <?= fa($x['attempts']) ?> بار شرکت<?= $x['took'] ? ' (میانگین ' . fa(round($x['attempts'] / $x['took'], 1)) . ' بار برای هر نفر)' : '' ?></div></div>
                <?php if (can('exams.report')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/exams/' . $x['id'] . '/report') ?>" title="گزارش کامل آزمون"><?= icon('chart-column') ?></a><?php endif; ?></div>
            <div class="assess-nums">
            <?php foreach (App\Services\Participation::EXAM_STATES as $st => $lb): $on = $R['tab'] === 'task' && $R['task']['type'] === 'exam' && $R['task']['id'] === (int)$x['id'] && $R['task']['state'] === $st; ?>
                <a class="assess-n tone-<?= $examStateTone[$st] ?><?= $on ? ' is-on' : '' ?>" href="<?= $link($base, ['tab' => 'task', 'task' => 'exam-' . $x['id'] . '-' . $st, 'page' => '', 'q' => '']) ?>#people"><b><?= fa($x[$st]) ?></b><span><?= e($lb) ?></span></a>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php foreach ($assess['exercises'] as $x): ?>
        <div class="assess-box">
            <div class="assess-head"><span class="ico" style="background:var(--warning-soft);color:var(--warning)"><?= icon('notebook-pen') ?></span><div class="grow"><b><?= e($x['title']) ?></b><div class="small faint">تمرین <?= (int)$x['is_required'] ? 'الزامی' : 'اختیاری' ?></div></div>
                <?php if (can('reviews.view')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/reviews') ?>" title="بررسی ارسال‌ها"><?= icon('file-check') ?></a><?php endif; ?></div>
            <div class="assess-nums">
            <?php foreach (App\Services\Participation::EX_STATES as $st => $lb): $on = $R['tab'] === 'task' && $R['task']['type'] === 'ex' && $R['task']['id'] === (int)$x['id'] && $R['task']['state'] === $st; ?>
                <a class="assess-n tone-<?= $exStateTone[$st] ?><?= $on ? ' is-on' : '' ?>" href="<?= $link($base, ['tab' => 'task', 'task' => 'ex-' . $x['id'] . '-' . $st, 'page' => '', 'q' => '']) ?>#people"><b><?= fa($x[$st]) ?></b><span><?= e($lb) ?></span></a>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$lesson && $lessons): $on = (int)($overview['n'] ?? 0); ?>
<div class="card flush mb-3">
    <div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('list') ?> پیشرفت درس به درس</h3><span class="small muted">نوار = چند درصد ثبت‌نام‌شدگان این درس را تمام کرده‌اند · روی درس بزنید تا اسامی را ببینید</span></div>
    <div class="lfun">
    <?php foreach ($lessons as $i => $l): $f = $funnel[(int)$l['id']] ?? ['started' => 0, 'done' => 0, 'done_in' => 0]; $pl = $R['per_lesson'][(int)$l['id']] ?? null; $dp = $on ? (int)round($f['done'] * 100 / $on) : 0; $sp = $on ? (int)round($f['started'] * 100 / $on) : 0; ?>
        <a class="lfun-row" href="<?= $link('/admin/reports/lesson/' . $l['id'], ['page' => '']) ?>">
            <span class="crow-no"><?= fa($i + 1) ?></span>
            <span class="lfun-t"><b><?= e($l['title']) ?></b><?= $l['status'] !== 'published' ? ' ' . status_badge($l['status']) : '' ?></span>
            <span class="lfun-bar" title="<?= fa($sp) ?>٪ شروع کرده‌اند · <?= fa($dp) ?>٪ تمام کرده‌اند"><i class="is-started" style="width:<?= $sp ?>%"></i><i class="is-done" style="width:<?= $dp ?>%"></i></span>
            <span class="lfun-n"><b><?= fa($dp) ?>٪</b><small><?= nf($f['done']) ?> از <?= nf($on) ?> نفر</small></span>
            <span class="lfun-p" title="در این بازه"><small>این بازه</small><b>+<?= nf($f['done_in']) ?></b><small><?= nf($pl['people'] ?? 0) ?> نفر دیدند</small></span>
        </a>
    <?php endforeach; ?>
    </div>
    <div class="p-legend"><i class="is-ok"></i>تمام کرده‌اند <i style="background:color-mix(in srgb,var(--primary) 35%,transparent)"></i>شروع کرده‌اند — «این بازه» یعنی چند نفر در بازه انتخاب‌شده این درس را تمام کردند.</div>
</div>
<?php endif; ?>

<div class="card flush" id="people">
    <div style="padding:1rem 1.25rem 0">
        <nav class="ctabs">
            <a class="<?= $R['tab'] === 'in' ? 'active' : '' ?>" href="<?= $link($base, ['tab' => '', 'task' => '', 'page' => '']) ?>#people"><?= icon('user-check') ?><span>شرکت کردند</span><em><?= fa($R['n_in']) ?></em></a>
            <a class="<?= $R['tab'] === 'done' ? 'active' : '' ?>" href="<?= $link($base, ['tab' => 'done', 'task' => '', 'page' => '']) ?>#people"><?= icon('circle-check') ?><span><?= $doneLabel ?></span><em><?= fa($R['completed']) ?></em></a>
            <a class="<?= $R['tab'] === 'out' ? 'active' : '' ?>" href="<?= $link($base, ['tab' => 'out', 'task' => '', 'page' => '']) ?>#people"><?= icon('user-x') ?><span>شرکت نکردند</span><em><?= fa($R['n_out']) ?></em></a>
            <?php if ($R['tab'] === 'task'): ?><a class="active" href="#people"><?= icon($R['task']['type'] === 'exam' ? 'clipboard-check' : 'notebook-pen') ?><span><?= e(str_limit($R['task']['title'], 24)) ?>: <?= e($R['task']['label']) ?></span><em><?= fa($page['total']) ?></em></a><?php endif; ?>
        </nav>
        <?php if ($R['tab'] === 'done'): ?><div class="rp-note"><?= icon('info') ?><span><?= e($doneTip) ?>.<?php if (!$lesson): ?> برای دیدن کسانی که کل دوره را تمام کرده‌اند، ستون «وضعیت» را ببینید (برچسب «تکمیل شده»).<?php endif; ?></span></div><?php endif; ?>
        <form class="rp-search" method="get" action="<?= url($base) ?><?= aud_hidden() ?>">
            <?php foreach (['period', 'from', 'to', 'segment', 'tab', 'audience', 'task'] as $h): if ($keep[$h] !== ''): ?><input type="hidden" name="<?= $h ?>" value="<?= e($keep[$h]) ?>"><?php endif; endforeach; ?>
            <span class="rp-search-ic"><?= icon('search') ?></span>
            <input type="search" name="q" value="<?= e($R['q']) ?>" placeholder="جست‌وجوی شخص: نام، موبایل، ایمیل یا نام کاربری">
            <?php if ($R['q'] !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= $link($base, ['q' => '', 'page' => '']) ?>#people"><?= icon('x') ?></a><?php endif; ?>
            <button class="btn btn-primary btn-sm">جست‌وجو</button>
        </form>
    </div>
    <?php if ($page['rows']): ?><div class="plist">
    <?php foreach ($page['rows'] as $r): $pp = $r['progress_pct'] !== null ? (int)$r['progress_pct'] : null; ?>
        <div class="prow2">
            <span class="prow-av" style="--h:<?= ((int)$r['user_id'] * 47) % 360 ?>"><?= e(mb_substr(trim((string)($r['first_name'] ?? '')) ?: '؟', 0, 1)) ?></span>
            <span class="p-name"><a class="stretch" href="<?= url('/admin/reports/user/' . $r['user_id']) ?>"><?= person_name($r, 'user_id') ?></a><small><?= e(label('segment_one', $r['segment'])) ?> · <span class="ltr"><?= e((string)$r['mobile']) ?></span></small></span>
            <span class="p-meta"><?php if ($isIn): ?>
                <span title="اولین بار: <?= e(jdatetime($r['first_at'])) ?> — آخرین بار: <?= e(jdatetime($r['last_at'])) ?>"><?= icon('history') ?> <?= jdatetime($r['last_at']) ?></span>
                <small><?= fa((int)$r['views']) ?> بار مشاهده<?= !$lesson ? ' · ' . fa((int)$r['lessons']) . ' از ' . fa(count($lessons)) . ' درس' : '' ?></small>
            <?php else: ?>
                <span><?= icon('activity') ?> <?= $r['last_activity_at'] ? time_ago($r['last_activity_at']) : 'هرگز' ?></span><small>آخرین فعالیت</small>
            <?php endif; ?></span>
            <?php if ($lesson && in_array($lesson['content_type'], ['video', 'audio'], true)): $ws = (int)($r['lp_watched'] ?? 0); $wp = $lessonSec ? min(100, (int)round($ws * 100 / $lessonSec)) : null; ?>
            <span class="p-watch<?= $wp !== null && $wp < 60 ? ' is-low' : '' ?>" title="زمانی که فایل واقعاً پخش شده (جلو زدن حساب نمی‌شود)<?= $r['time_spent_sec'] ? ' · حضور در صفحه: ' . e(strip_tags($mm((int)$r['time_spent_sec']))) : '' ?>">
                <span><?= icon($lesson['content_type'] === 'audio' ? 'music' : 'circle-play') ?> <b><?= $ws ? $mm($ws) : '۰' ?></b><?= $lessonSec ? ' از ' . $mm($lessonSec) : '' ?></span>
                <?php if ($wp !== null): ?><span class="p-watch-bar"><i style="width:<?= $wp ?>%"></i></span><?php endif; ?>
            </span>
            <?php endif; ?>
            <?php if (!$lesson && $lessons): $ul = $userLessons[(int)$r['user_id']] ?? []; ?>
            <span class="p-lessons" title="<?= fa(count($ul)) ?> از <?= fa(count($lessons)) ?> درس تمام شده">
                <?php foreach ($lessons as $i => $l): $dAt = $ul[(int)$l['id']] ?? null; ?><span class="<?= $dAt ? 'is-done' : '' ?>" title="<?= e(fa($i + 1) . '. ' . $l['title'] . ($dAt ? ' — تکمیل: ' . jdatetime($dAt) : ' — تمام نشده')) ?>"><?= fa($i + 1) ?></span><?php endforeach; ?>
            </span>
            <?php endif; ?>
            <?php if ($assess['exams'] || $assess['exercises']): ?>
            <span class="p-tasks">
                <?php foreach ($assess['exams'] as $x): $t = $taskRows[(int)$r['user_id']]['exam' . $x['id']] ?? null;
                    $tone = !$t ? 'none' : ((int)$t['passed'] ? 'ok' : ((int)$t['pending'] ? 'wait' : 'bad'));
                    $txt = !$t ? 'شرکت نکرده' : (((int)$t['passed'] ? 'قبول' : ((int)$t['pending'] ? 'در انتظار تصحیح' : 'مردود')) . ($t['best'] !== null ? ' · ' . fa(round((float)$t['best'])) . '٪' : '') . ' · ' . fa((int)$t['n']) . ' بار'); ?>
                    <span class="ptk is-<?= $tone ?>" title="<?= e($x['title'] . ' — ' . $txt) ?>"><?= icon('clipboard-check') ?></span>
                <?php endforeach; ?>
                <?php foreach ($assess['exercises'] as $x): $t = $taskRows[(int)$r['user_id']]['ex' . $x['id']] ?? null;
                    $st = $t ? (string)$t['status'] : ''; $tone = !$t ? 'none' : ($st === 'accepted' ? 'ok' : ($st === 'rejected' ? 'bad' : 'wait'));
                    $txt = !$t ? 'ارسال نکرده' : trim(strip_tags(status_badge($st))) . ((int)$t['n'] > 1 ? ' · ' . fa((int)$t['n']) . ' بار' : ''); ?>
                    <span class="ptk is-<?= $tone ?>" title="<?= e($x['title'] . ' — ' . $txt) ?>"><?= icon(str_contains($x['title'], 'صوت') ? 'music' : 'notebook-pen') ?></span>
                <?php endforeach; ?>
            </span>
            <?php endif; ?>
            <span class="p-st"><?php if ($lesson): ?><?= !empty($r['flag_reason']) ? '<a class="badge badge-danger" href="' . url('/admin/reports/suspicious', ['q' => $r['mobile']]) . '" title="' . e(App\Services\CompletionAudit::REASONS[$r['flag_reason']] ?? '') . '">' . icon('triangle-alert') . ' مشکوک</a>' : '' ?><?= $r['lp_status'] === 'completed' && empty($r['flag_reason']) && !(int)($r['still_ok'] ?? 1) ? '<span class="badge badge-warning" title="محتوا دیده شده ولی آزمون/تمرین الزامی درس انجام نشده یا رد شده">' . icon('clipboard-check') . ' کار مانده</span>' : ($r['lp_status'] ? status_badge($r['lp_status']) . ($r['lp_status'] !== 'completed' && (int)$r['lp_percent'] ? ' <span class="small faint">' . fa((int)$r['lp_percent']) . '٪</span>' : '') : '<span class="badge badge-gray">ندیده</span>') ?><?php else: ?><?= (int)($r['flag_count'] ?? 0) ? '<a class="badge badge-danger" href="' . url('/admin/reports/suspicious', ['q' => $r['mobile']]) . '">' . icon('triangle-alert') . ' ' . fa((int)$r['flag_count']) . ' مشکوک</a>' : '' ?><?= $r['en_status'] ? status_badge($r['en_status']) : '<span class="badge badge-gray">ثبت‌نام نکرده</span>' ?><?php endif; ?></span>
            <span class="crow-prog p-prog" title="پیشرفت دوره"><?php if ($pp !== null): ?><?= progress_bar((float)$pp) ?><b><?= fa($pp) ?>٪</b><?php else: ?><span class="faint small">—</span><?php endif; ?></span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if ($assess['exams'] || $assess['exercises']): ?><div class="p-legend"><span><?= icon('clipboard-check') ?> آزمون</span><span><?= icon('notebook-pen') ?> تمرین</span><i class="is-ok"></i>قبول/پذیرفته <i class="is-wait"></i>در انتظار <i class="is-bad"></i>مردود/رد <i class="is-none"></i>انجام نشده — برای جزئیات، ماوس را روی آیکون نگه دارید.</div><?php endif; ?>
    <?php else: ?>
        <div class="empty"><?= icon($R['tab'] === 'out' ? 'user-x' : ($R['tab'] === 'done' ? 'circle-check' : 'user-check')) ?><div><?= $R['q'] !== '' ? 'کسی با این مشخصات پیدا نشد' : (['in' => 'در این بازه کسی شرکت نکرده است', 'done' => 'در این بازه کسی درسی را (به‌طور معتبر) تکمیل نکرده است', 'out' => 'همه شرکت کرده‌اند', 'task' => 'کسی در این وضعیت نیست'][$R['tab']]) ?></div></div>
    <?php endif; ?>
</div>
<?= paginate_links($page) ?>
