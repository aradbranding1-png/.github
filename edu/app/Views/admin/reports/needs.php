<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a></div><h1>گزارش نیاز آموزشی</h1></div><?php if (can('reports.export')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/needs', ['export' => 1]) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?></div>
<div class="card mb-3"><div class="chart" data-chart='<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>' data-height="260"></div></div>
<div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>موضوع</th><th>کل</th><th>باز</th><th>برنامه‌ریزی‌شده</th><th>رفع‌شده</th><th>اولویت بالا</th></tr></thead><tbody>
<?php foreach ($byCat as $r): ?><tr><td class="fw-b"><?= e($r['name']) ?></td><td class="num"><?= fa($r['n']) ?></td><td class="num"><?= fa($r['open_n']) ?></td><td class="num"><?= fa($r['planned']) ?></td><td class="num"><?= fa($r['resolved']) ?></td><td class="num"><?= $r['high'] ? '<span class="badge badge-danger">' . fa($r['high']) . '</span>' : '—' ?></td></tr><?php endforeach; ?>
<?php if (!$byCat): ?><tr><td colspan="6" class="faint text-center">نیازی ثبت نشده</td></tr><?php endif; ?>
</tbody></table></div></div>
