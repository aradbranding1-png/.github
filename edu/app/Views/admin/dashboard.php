<div class="page-head">
    <div><h1>داشبورد مدیریتی</h1><div class="sub">نمای کلی آموزش <?= $scope !== 'all' ? '— محدوده: ' . e(label('scope', $scope)) : 'در کل سامانه' ?> · <?= jdate(now(), 'l j F Y') ?></div></div>
    <div class="btn-group">
        <?php if (can('reports.view')): ?><a class="btn btn-outline" href="<?= url('/admin/reports') ?>"><?= icon('chart-column') ?> گزارش‌ها</a><?php endif; ?>
        <?php if (can('assignments.assign')): ?><a class="btn btn-grad" href="<?= url('/admin/assignments') ?>"><?= icon('send') ?> تخصیص آموزش</a><?php endif; ?>
    </div>
</div>

<div class="grid g-4 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= nf($k['users']) ?></div><div class="l">کاربران فعال · امروز <?= nf($k['active_today']) ?> نفر آنلاین بوده‌اند</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('activity') ?></div><div><div class="v"><?= nf($k['active30']) ?></div><div class="l">کاربران فعال ۳۰ روز اخیر</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('book-open') ?></div><div><div class="v"><?= nf($k['courses']) ?><small class="faint small"> / <?= nf($k['courses_all']) ?></small></div><div class="l">دوره منتشرشده</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= nf($k['completed']) ?></div><div class="l">آموزش تکمیل‌شده از <?= nf($k['enrollments']) ?> (<?= fa($k['completion_rate']) ?>٪)</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('hourglass') ?></div><div><div class="v"><?= nf($k['incomplete']) ?></div><div class="l">آموزش ناقص / در جریان</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('clipboard-check') ?></div><div><div class="v"><?= nf($k['exams']) ?></div><div class="l">آزمون برگزارشده · میانگین <?= $k['avg_score'] === null ? '—' : fa(round((float)$k['avg_score'])) ?></div></div></div>
    <a class="card stat tone-danger" href="<?= url('/admin/reports/progress', ['state' => 'overdue']) ?>"><div class="bubble"><?= icon('triangle-alert') ?></div><div><div class="v"><?= nf($k['overdue']) ?></div><div class="l">آموزش عقب‌افتاده / منقضی</div></div></a>
    <a class="card stat tone-danger" href="<?= url('/admin/reports/inactive') ?>"><div class="bubble"><?= icon('user-x') ?></div><div><div class="v"><?= nf($k['inactive']) ?></div><div class="l">بدون فعالیت بیش از <?= fa($inact) ?> روز</div></div></a>
</div>

<div class="grid g-main mb-3">
    <div class="card"><div class="card-head"><h3><?= icon('trending-up') ?> روند فعالیت کاربران (۳۰ روز)</h3></div><div class="chart" data-chart='<?= e(json_encode($trend, JSON_UNESCAPED_UNICODE)) ?>' data-height="250"></div></div>
    <div class="card"><div class="card-head"><h3><?= icon('chart-pie') ?> کاربران به تفکیک گروه</h3></div><div class="donut-wrap"><div class="chart" data-chart='<?= e(json_encode($segChart, JSON_UNESCAPED_UNICODE)) ?>'></div><div class="legend" data-for></div></div></div>
</div>
<div class="grid g-main mb-3">
    <div class="card"><div class="card-head"><h3><?= icon('chart-column') ?> روند آموزش — دوره‌های تکمیل‌شده در هفته</h3></div><div class="chart" data-chart='<?= e(json_encode($completions, JSON_UNESCAPED_UNICODE)) ?>' data-height="230"></div></div>
    <div class="card"><div class="card-head"><h3><?= icon('gauge') ?> وضعیت آموزش‌ها</h3></div><div class="donut-wrap"><div class="chart" data-chart='<?= e(json_encode($stChart, JSON_UNESCAPED_UNICODE)) ?>'></div><div class="legend" data-for></div></div></div>
</div>

<div class="grid g-3 mb-3">
    <div class="card"><div class="card-head"><h3><?= icon('triangle-alert') ?> افراد عقب‌مانده</h3><a class="small" href="<?= url('/admin/reports/progress', ['state' => 'overdue']) ?>">همه</a></div>
        <?php if (!$laggards): ?><div class="faint small">موردی نیست 👌</div><?php endif; ?>
        <?php foreach ($laggards as $l): ?>
            <div class="list-item"><?= avatar_html(['id' => $l['uid'], 'first_name' => $l['first_name'], 'last_name' => $l['last_name'], 'avatar_path' => $l['avatar_path']], 'sm') ?>
                <div class="grow"><a class="fw-b small" href="<?= url('/admin/reports/user/' . $l['uid']) ?>"><?= person_name($l, 'uid') ?></a><div class="small faint"><?= e(str_limit($l['title'], 34)) ?> · <?= $l['due_at'] ? 'مهلت ' . jdate($l['due_at']) : '' ?></div></div>
                <b class="small" style="color:var(--danger)"><?= fa((int)$l['progress_pct']) ?>٪</b></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><div class="card-head"><h3><?= icon('user-x') ?> افراد بدون فعالیت</h3><a class="small" href="<?= url('/admin/reports/inactive') ?>">همه</a></div>
        <?php foreach ($inactiveUsers as $u): ?>
            <div class="list-item"><?= avatar_html($u, 'sm') ?><div class="grow"><a class="fw-b small" href="<?= url('/admin/users/' . $u['id']) ?>"><?= user_name_html($u) ?></a><div class="small faint">آخرین ورود: <?= $u['last_login_at'] ? time_ago($u['last_login_at']) : 'هرگز' ?></div></div></div>
        <?php endforeach; ?>
        <?php if (!$inactiveUsers): ?><div class="faint small">همه کاربران فعال هستند.</div><?php endif; ?>
    </div>
    <div class="card"><div class="card-head"><h3><?= icon('layers') ?> گروه‌های اصلی</h3></div>
        <?php foreach ($groups as $g): ?>
            <div class="list-item"><span class="ico" style="background:color-mix(in srgb, <?= e($g['color']) ?> 15%, transparent);color:<?= e($g['color']) ?>"><?= icon($g['icon']) ?></span><div class="grow"><b class="small"><?= e($g['name']) ?></b><div class="small faint"><?= nf($g['members']) ?> عضو</div><?= progress_bar((float)$g['prog']) ?></div><b class="small"><?= fa((int)$g['prog']) ?>٪</b></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid g-main">
    <div class="card flush"><div class="card-head"><h3><?= icon('book-open') ?> پرمخاطب‌ترین دوره‌ها</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>دوره</th><th>فراگیر</th><th>تکمیل</th><th>میانگین پیشرفت</th></tr></thead><tbody>
        <?php foreach ($topCourses as $c): ?><tr><td><a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a></td><td class="num"><?= nf($c['n']) ?></td><td class="num"><?= nf($c['done']) ?></td><td style="min-width:150px"><div class="flex"><div class="grow"><?= progress_bar((float)$c['prog']) ?></div><span class="small"><?= fa((int)$c['prog']) ?>٪</span></div></td></tr><?php endforeach; ?>
        <?php if (!$topCourses): ?><tr><td colspan="4" class="faint text-center">هنوز ثبت‌نامی انجام نشده</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <div class="card">
        <?php if ($k['pending_reviews'] && can('reviews.view')): ?><a class="alert alert-warning" href="<?= url('/admin/reviews') ?>"><?= icon('list-checks') ?> <?= fa($k['pending_reviews']) ?> مورد تمرین/پاسخ تشریحی منتظر بررسی است.</a><?php endif; ?>
        <div class="card-head"><h3><?= icon('scroll-text') ?> آخرین رویدادهای مدیریتی</h3><?php if ($audit): ?><a class="small" href="<?= url('/admin/audit') ?>">Audit Log</a><?php endif; ?></div>
        <?php if (!$audit): ?><div class="faint small">برای مشاهده رویدادها مجوز Audit Log لازم است.</div><?php endif; ?>
        <div class="timeline"><?php foreach ($audit as $a): ?><div class="tl-item <?= $a['result'] === 'success' ? 'info' : 'danger' ?>"><div class="t small"><?= e($a['action']) ?> <span class="faint">— <?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'سیستم') ?></span></div><div class="d"><?= time_ago($a['created_at']) ?></div></div><?php endforeach; ?></div>
    </div>
</div>
