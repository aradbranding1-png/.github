<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/exams') ?>">آزمون‌ها</a> / <a href="<?= url('/admin/exams/' . $x['id']) ?>"><?= e($x['title']) ?></a></div><h1>گزارش آزمون</h1></div>
<?php if (can('exams.export')): ?><a class="btn btn-outline" href="<?= url('/admin/exams/' . $x['id'] . '/report', ['export' => 1]) ?>"><?= icon('file-spreadsheet') ?> خروجی Excel</a><?php endif; ?></div>
<div class="grid g-5 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('users') ?></div><div><div class="v"><?= fa($sum['users']) ?></div><div class="l">شرکت‌کننده</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('refresh-cw') ?></div><div><div class="v"><?= fa($sum['n']) ?></div><div class="l">دفعات برگزاری</div></div></div>
    <div class="card stat tone-purple"><div class="bubble"><?= icon('gauge') ?></div><div><div class="v"><?= $sum['avg'] !== null ? fa(round($sum['avg'])) : '—' ?></div><div class="l">میانگین نمره (٪)</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= $sum['pass'] !== null ? fa(round($sum['pass'])) . '٪' : '—' ?></div><div class="l">نرخ قبولی</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('clock') ?></div><div><div class="v"><?= fa($sum['pending']) ?></div><div class="l">در انتظار تصحیح</div></div></div>
</div>
<div class="grid g-2 mb-3">
    <div class="card"><h3><?= icon('chart-column') ?> توزیع نمرات</h3><div class="chart" data-chart='<?= e(json_encode($dist, JSON_UNESCAPED_UNICODE)) ?>' data-height="230"></div></div>
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('circle-help') ?> تحلیل سؤالات (دشوارترین در ابتدا)</h3></div>
        <div class="table-wrap" style="max-height:300px"><table class="table"><tbody><?php foreach ($qstats as $q): ?><tr><td class="small"><?= e(str_limit($q['text'], 70)) ?></td><td class="num"><?= fa($q['n']) ?> پاسخ</td><td style="min-width:120px"><?= $q['rate'] !== null ? progress_bar((float)$q['rate'] * 100) : '—' ?></td><td class="num small"><?= $q['rate'] !== null ? fa(round((float)$q['rate'] * 100)) . '٪ صحیح' : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
</div>
<div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>شرکت‌کننده</th><th>دفعه</th><th>تاریخ</th><th>نمره</th><th>نتیجه</th><th></th></tr></thead><tbody>
<?php foreach ($attempts as $a): ?><tr><td><a href="<?= url('/admin/reports/user/' . $a['user_id']) ?>"><?= person_name($a, 'user_id') ?></a></td><td class="num"><?= fa($a['attempt_no']) ?></td><td class="num"><?= jdatetime($a['submitted_at']) ?></td><td class="num"><?= $a['percent'] !== null ? fa((float)$a['percent']) . '٪' : '—' ?></td><td><?= $a['status'] === 'pending_review' ? status_badge('pending_review') : ((int)$a['passed'] ? status_badge('passed') : status_badge('failed')) ?></td><td class="actions"><?php if (can('reviews.view')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/reviews/attempt/' . $a['id']) ?>"><?= icon('eye') ?></a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$attempts): ?><tr><td colspan="6" class="faint text-center">هنوز کسی شرکت نکرده</td></tr><?php endif; ?>
</tbody></table></div></div>
