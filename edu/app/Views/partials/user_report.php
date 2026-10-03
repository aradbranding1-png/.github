<?php
use App\Core\Jalali;
use App\Services\UserReport;
/** @var array $R report data @var string $baseUrl */
$u = $R['u'];
$nav = function (array $q) use ($baseUrl, $R) { return url($baseUrl, array_merge(['view' => $R['view'], 'y' => $R['jy'], 'm' => $R['jm'], 'd' => $R['jd']], $q)); };
$prevM = $R['jm'] - 1; $prevY = $R['jy']; if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $R['jm'] + 1; $nextY = $R['jy']; if ($nextM > 12) { $nextM = 1; $nextY++; }
$hours = $R['totals']['seconds'] / 3600;
$today = date('Y-m-d');
$trendChart = ['type' => 'line', 'labels' => $R['trend']['labels'], 'series' => [['name' => 'روزهای فعال', 'data' => $R['trend']['active'], 'color' => '#0ea5e9'], ['name' => 'روزهای یادگیری', 'data' => $R['trend']['learning'], 'color' => '#6366f1']]];
$stLabels = []; $stVals = []; $stColors = [];
$toneColor = ['success' => '#10b981', 'primary' => '#6366f1', 'warning' => '#f59e0b', 'danger' => '#ef4444', 'gray' => '#94a3b8', 'dark' => '#475569', 'info' => '#0ea5e9'];
foreach ($R['statusDist'] as $k => $v) { $stLabels[] = label('status', $k); $stVals[] = (int)$v; $stColors[] = $toneColor[App\Core\Labels::STATUS[$k][1] ?? 'gray'] ?? '#94a3b8'; }
?>
<div class="card mb-3">
    <div class="flex between flex-wrap gap-2">
        <div class="flex gap-2">
            <?= avatar_html($u, 'lg') ?>
            <div>
                <h2 class="mb-0"><?= user_name_html($u) ?></h2>
                <div class="small muted ltr" style="text-align:right"><?= e($u['mobile'] ?? '') ?> <?= $u['email'] ? ' · ' . e($u['email']) : '' ?> · #<?= (int)$u['id'] ?></div>
                <div class="flex flex-wrap mt-1"><?= status_badge($u['status']) ?><span class="badge badge-gray"><?= e(label('segment_one', $u['segment'])) ?></span><?php $tgNo = (int)($u['tg_stage'] ?? 0); if ($tgNo > 0): $tgS = App\Services\TraderGrowth::stageByNo($tgNo); ?><span class="badge badge-primary"><?= icon('mountain') ?> نظام رشد: مرحله <?= fa($tgNo) ?><?= $tgS ? ' «' . e($tgS['title']) . '»' : '' ?> · <?= fa(round((float)($u['tg_progress'] ?? 0))) ?>٪</span><?php endif; ?>
                    <?php foreach ($R['growth'] as $g): ?><span class="badge badge-gray" title="نظام رشد قبلی"><?= icon('mountain') ?> <?= e($g['stage_name']) ?></span><?php endforeach; ?></div>
            </div>
        </div>
        <dl class="kv">
            <dt>اولین ورود</dt><dd><?= jdatetime($u['first_login_at']) ?></dd>
            <dt>آخرین ورود</dt><dd><?= jdatetime($u['last_login_at']) ?> <span class="faint small">(<?= time_ago($u['last_login_at']) ?>)</span></dd>
            <dt>تعداد کل ورود</dt><dd><?= nf($u['login_count']) ?></dd>
            <dt>روزهای فعال / غیرفعال (کل)</dt><dd><span style="color:var(--success)"><?= nf($R['overall']['total_active_days']) ?></span> / <span style="color:var(--danger)"><?= nf($R['overall']['total_inactive_days']) ?></span></dd>
        </dl>
    </div>
</div>

<div class="grid g-5 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('gauge') ?></div><div><div class="v"><?= fa(round((float)$R['overall']['avg_progress'])) ?>٪</div><div class="l">میانگین پیشرفت</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= fa(count(array_filter($R['enrollments'], fn($e) => $e['status'] === 'completed'))) ?> / <?= fa(count($R['enrollments'])) ?></div><div class="l">دوره تکمیل‌شده</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('eye') ?></div><div><div class="v"><?= nf($R['overall']['lessons_completed']) ?> / <?= nf($R['overall']['lessons_viewed']) ?></div><div class="l">درس تکمیل / مشاهده‌شده</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('clipboard-check') ?></div><div><div class="v"><?= $R['overall']['avg_score'] === null ? '—' : fa(round((float)$R['overall']['avg_score'])) ?></div><div class="l">میانگین نمره آزمون</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('clock') ?></div><div><div class="v"><?= fa(round($R['overall']['total_seconds'] / 3600, 1)) ?></div><div class="l">ساعت فعالیت آموزشی</div></div></div>
</div>

<div class="card mb-3">
    <div class="card-head">
        <h3><?= icon('calendar') ?> تقویم فعالیت</h3>
        <div class="flex flex-wrap">
            <div class="seg">
                <a class="<?= $R['view'] === 'day' ? 'active' : '' ?>" href="<?= e($nav(['view' => 'day'])) ?>">روزانه</a>
                <a class="<?= $R['view'] === 'month' ? 'active' : '' ?>" href="<?= e($nav(['view' => 'month'])) ?>">ماهانه</a>
                <a class="<?= $R['view'] === 'year' ? 'active' : '' ?>" href="<?= e($nav(['view' => 'year'])) ?>">سالانه</a>
            </div>
            <?php if ($R['view'] === 'month'): ?>
                <a class="btn btn-sm btn-outline" href="<?= e($nav(['y' => $prevY, 'm' => $prevM])) ?>"><?= icon('chevron-right') ?></a>
                <b><?= e(Jalali::MONTHS[$R['jm']]) ?> <?= fa($R['jy']) ?></b>
                <a class="btn btn-sm btn-outline" href="<?= e($nav(['y' => $nextY, 'm' => $nextM])) ?>"><?= icon('chevron-left') ?></a>
            <?php elseif ($R['view'] === 'year'): ?>
                <a class="btn btn-sm btn-outline" href="<?= e($nav(['y' => $R['jy'] - 1])) ?>"><?= icon('chevron-right') ?></a><b>سال <?= fa($R['jy']) ?></b><a class="btn btn-sm btn-outline" href="<?= e($nav(['y' => $R['jy'] + 1])) ?>"><?= icon('chevron-left') ?></a>
            <?php else: $prevDay = strtotime($R['from'] . ' -1 day'); $nextDay = strtotime($R['from'] . ' +1 day'); $pj = Jalali::toJalali((int)date('Y', $prevDay), (int)date('n', $prevDay), (int)date('j', $prevDay)); $nj = Jalali::toJalali((int)date('Y', $nextDay), (int)date('n', $nextDay), (int)date('j', $nextDay)); ?>
                <a class="btn btn-sm btn-outline" href="<?= e($nav(['y' => $pj[0], 'm' => $pj[1], 'd' => $pj[2]])) ?>"><?= icon('chevron-right') ?></a><b><?= jdate($R['from'], 'l j F Y') ?></b><a class="btn btn-sm btn-outline" href="<?= e($nav(['y' => $nj[0], 'm' => $nj[1], 'd' => $nj[2]])) ?>"><?= icon('chevron-left') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="mini-stats mb-2">
        <div><b style="color:var(--success)"><?= fa($R['totals']['active_days']) ?></b><span>روز فعال</span></div>
        <div><b style="color:var(--danger)"><?= fa($R['totals']['inactive_days']) ?></b><span>روز بدون ورود/فعالیت</span></div>
        <div><b><?= fa($R['totals']['logins']) ?></b><span>ورود</span></div>
        <div><b><?= fa($R['totals']['lessons_completed']) ?></b><span>درس تکمیل‌شده</span></div>
        <div><b><?= fa($R['totals']['courses_completed']) ?></b><span>دوره تکمیل‌شده</span></div>
        <div><b><?= fa($R['totals']['exams']) ?></b><span>آزمون</span></div>
        <div><b><?= fa($R['totals']['exercises']) ?></b><span>تمرین ارسالی</span></div>
        <div><b><?= $R['totals']['avg_score'] === null ? '—' : fa($R['totals']['avg_score']) ?></b><span>میانگین نمره</span></div>
        <div><b><?= fa(round($hours, 1)) ?></b><span>ساعت فعالیت</span></div>
    </div>

    <?php if ($R['view'] === 'month'):
        $len = Jalali::monthLength($R['jy'], $R['jm']);
        [$gy, $gm, $gd] = Jalali::toGregorian($R['jy'], $R['jm'], 1);
        $firstW = ((int)date('w', mktime(0, 0, 0, $gm, $gd, $gy)) + 1) % 7; // Saturday = 0 ?>
        <div class="month-cal">
            <?php foreach (['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $w): ?><div class="wd"><?= $w ?></div><?php endforeach; ?>
            <?php for ($i = 0; $i < $firstW; $i++): ?><div class="day pad"></div><?php endfor; ?>
            <?php for ($d = 1; $d <= $len; $d++):
                $g = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($R['jy'], $R['jm'], $d));
                $row = $R['daily'][$g] ?? null;
                $cls = $g > $today ? '' : UserReport::dayClass($row); ?>
                <a class="day <?= $cls ?><?= $g === $today ? ' today' : '' ?>" href="<?= e($nav(['view' => 'day', 'd' => $d])) ?>" style="color:inherit">
                    <span class="n"><?= fa($d) ?></span>
                    <?php if ($row): ?>
                        <span class="ev"><?php if ($row['logins']): ?><b class="ev-login" title="ورود"></b><?php endif; ?><?php if ($row['learning_events']): ?><b class="ev-learn" title="فعالیت آموزشی"></b><?php endif; ?><?php if ($row['lessons_completed'] || $row['courses_completed']): ?><b class="ev-done" title="تکمیل آموزش"></b><?php endif; ?><?php if ($row['exams']): ?><b class="ev-exam" title="آزمون"></b><?php endif; ?><?php if ($row['exercises']): ?><b class="ev-ex" title="تمرین"></b><?php endif; ?></span>
                        <?php if ($row['seconds_spent'] >= 60): ?><span class="faint"><?= fa(round($row['seconds_spent'] / 60)) ?>د</span><?php endif; ?>
                    <?php elseif ($g <= $today && $g >= substr((string)$u['created_at'], 0, 10)): ?><span class="faint">بدون ورود</span><?php endif; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php elseif ($R['view'] === 'year'): ?>
        <div class="year-grid">
            <?php for ($m = 1; $m <= 12; $m++):
                $len = Jalali::monthLength($R['jy'], $m);
                [$gy, $gm, $gd] = Jalali::toGregorian($R['jy'], $m, 1);
                $firstW = ((int)date('w', mktime(0, 0, 0, $gm, $gd, $gy)) + 1) % 7;
                $act = 0; ?>
                <a class="m" href="<?= e($nav(['view' => 'month', 'm' => $m])) ?>" style="color:inherit">
                    <?php ob_start(); ?>
                    <div class="mini"><?php for ($i = 0; $i < $firstW; $i++): ?><i class="pad"></i><?php endfor; ?>
                    <?php for ($d = 1; $d <= $len; $d++): $g = sprintf('%04d-%02d-%02d', ...Jalali::toGregorian($R['jy'], $m, $d)); $row = $R['daily'][$g] ?? null; $c = $row ? UserReport::dayClass($row) : ''; if ($c && $c !== 'absent') $act++; ?><i class="<?= $c ?>" title="<?= fa($d) ?>"></i><?php endfor; ?></div>
                    <?php $mini = ob_get_clean(); ?>
                    <div class="h"><span><?= e(Jalali::MONTHS[$m]) ?></span><span class="badge badge-<?= $act ? 'primary' : 'gray' ?>"><?= fa($act) ?> روز</span></div>
                    <?= $mini ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php else: $row = $R['daily'][$R['from']] ?? null; ?>
        <?php if (!$row): ?><div class="alert alert-warning"><?= icon('calendar') ?> در این روز ورود یا فعالیتی ثبت نشده است.</div><?php endif; ?>
        <div class="timeline">
            <?php foreach ($R['events'] as $ev):
                [$t, $tone] = match ($ev['type']) {
                    'login' => ['ورود به سامانه', 'info'], 'lesson_view' => ['مشاهده درس «' . ($ev['lesson_title'] ?? '') . '»', 'info'],
                    'lesson_complete' => ['تکمیل درس «' . ($ev['lesson_title'] ?? '') . '»', 'success'], 'course_complete' => ['تکمیل دوره «' . ($ev['course_title'] ?? '') . '»', 'success'],
                    'exam_submit' => ['شرکت در آزمون «' . ($ev['exam_title'] ?? '') . '»', 'warning'], 'exercise_submit' => ['ارسال تمرین', 'warning'],
                    'file_view' => ['مشاهده فایل', 'info'], 'file_download' => ['دانلود فایل', 'info'], default => [$ev['type'], ''],
                }; ?>
                <div class="tl-item <?= $tone ?>"><div class="t"><?= e($t) ?></div><div class="d"><?= fa(date('H:i', strtotime($ev['created_at']))) ?></div></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="heat-legend mt-2"><span><i style="background:#e0f2fe;outline:1px solid #7dd3fc"></i> فقط ورود</span><span><i style="background:var(--primary-soft);outline:1px solid #a5b4fc"></i> فعالیت آموزشی</span><span><i style="background:var(--success-soft);outline:1px solid #6ee7b7"></i> تکمیل آموزش</span><span><i style="background:var(--purple)"></i> آزمون</span><span><i style="background:var(--warning)"></i> تمرین</span></div>
</div>

<div class="grid g-main mb-3">
    <div class="card"><div class="card-head"><h3><?= icon('trending-up') ?> روند فعالیت ۱۲ ماه اخیر</h3></div><div class="chart" data-chart='<?= e(json_encode($trendChart, JSON_UNESCAPED_UNICODE)) ?>' data-height="230"></div></div>
    <div class="card"><div class="card-head"><h3><?= icon('chart-pie') ?> وضعیت آموزش‌ها</h3></div>
        <div class="donut-wrap"><div class="chart" data-chart='<?= e(json_encode(['type' => 'donut', 'labels' => $stLabels, 'values' => $stVals, 'colors' => $stColors, 'center' => 'دوره'], JSON_UNESCAPED_UNICODE)) ?>'></div><div class="legend" data-for></div></div>
    </div>
</div>

<div class="grid g-2">
    <div class="card flush"><div class="card-head"><h3><?= icon('book-open') ?> دوره‌ها و میزان پیشرفت</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>دوره</th><th>نوع</th><th>پیشرفت</th><th>نمره</th><th>وضعیت</th></tr></thead><tbody>
        <?php foreach ($R['enrollments'] as $e): ?><tr><td><?= e($e['title']) ?><div class="small faint"><?= $e['due_at'] ? 'مهلت ' . jdate($e['due_at']) : '' ?></div></td><td><span class="badge badge-gray"><?= e(label('training_type', $e['training_type'])) ?></span></td><td style="min-width:120px"><div class="flex"><div class="grow"><?= progress_bar((float)$e['progress_pct']) ?></div><span class="small"><?= fa((int)$e['progress_pct']) ?>٪</span></div></td><td class="num"><?= $e['score'] !== null ? fa((float)$e['score']) : '—' ?></td><td><?= status_badge($e['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$R['enrollments']): ?><tr><td colspan="5" class="faint text-center">دوره‌ای ثبت نشده</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <div class="stack">
        <div class="card flush"><div class="card-head"><h3><?= icon('clipboard-check') ?> آزمون‌ها و نمرات</h3></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>آزمون</th><th>تاریخ</th><th>نمره</th><th>نتیجه</th></tr></thead><tbody>
            <?php foreach ($R['exams'] as $a): ?><tr><td><?= e($a['title']) ?> <span class="faint small">(دفعه <?= fa($a['attempt_no']) ?>)</span></td><td class="num"><?= jdate($a['submitted_at']) ?></td><td class="num"><?= $a['percent'] !== null ? fa((float)$a['percent']) . '٪' : '—' ?></td><td><?= $a['status'] === 'pending_review' ? status_badge('pending_review') : ((int)$a['passed'] ? status_badge('passed') : status_badge('failed')) ?></td></tr><?php endforeach; ?>
            <?php if (!$R['exams']): ?><tr><td colspan="4" class="faint text-center">آزمونی ثبت نشده</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <div class="card flush"><div class="card-head"><h3><?= icon('notebook-pen') ?> تمرین‌ها</h3></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>تمرین</th><th>تاریخ</th><th>نمره</th><th>وضعیت</th></tr></thead><tbody>
            <?php foreach ($R['exercises'] as $s): ?><tr><td><?= e($s['title']) ?></td><td class="num"><?= jdate($s['created_at']) ?></td><td class="num"><?= $s['score'] !== null ? fa((float)$s['score']) : '—' ?></td><td><?= status_badge($s['status']) ?></td></tr><?php endforeach; ?>
            <?php if (!$R['exercises']): ?><tr><td colspan="4" class="faint text-center">تمرینی ارسال نشده</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php if ($R['paths']): ?>
        <div class="card"><h3><?= icon('route') ?> مسیرهای آموزشی (مرحله آموزشی)</h3>
            <?php foreach ($R['paths'] as $p): ?><div class="list-item"><div class="grow"><b><?= e($p['title']) ?></b><div class="small faint">مرحله <?= fa(min((int)$p['current_step'], (int)$p['steps'])) ?> از <?= fa($p['steps']) ?></div></div><div style="width:140px"><?= progress_bar((float)$p['progress_pct']) ?></div><?= status_badge($p['status']) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
