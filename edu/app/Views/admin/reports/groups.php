<?php
$dims = ['group' => 'گروه‌ها', 'org_unit' => 'واحد / بخش / سمت', 'level' => 'سطح', 'term' => 'طبقه‌بندی'];
$q = $_GET; unset($q['r']); $q['export'] = 1;
$chart = ['type' => 'bar', 'labels' => array_map(fn($r) => str_limit($r['name'], 18), array_slice($rows, 0, 14)), 'series' => [['name' => 'میانگین پیشرفت', 'data' => array_map(fn($r) => round((float)$r['prog']), array_slice($rows, 0, 14)), 'color' => '#6366f1']], 'max' => 100];
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a></div><h1>گزارش گروهی</h1><div class="sub">تعداد افراد، فعال/غیرفعال، میانگین پیشرفت، دوره‌های تکمیل‌شده و افراد عقب‌مانده</div></div>
<?php if (can('reports.export')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/groups', $q) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?></div>
<div class="pill-nav"><?php foreach ($dims as $k => $t): ?><a class="<?= $dim === $k ? 'active' : '' ?>" href="<?= url('/admin/reports/groups', ['dim' => $k]) ?>"><?= e($t) ?></a><?php endforeach; ?></div>
<form class="card filters" method="get" action="<?= url('/admin/reports/groups') ?>"><input type="hidden" name="dim" value="<?= e($dim) ?>">
    <div class="field grow"><label>انتخاب مورد (برای جزئیات افراد)</label><select name="id"><option value="">— همه —</option><?php foreach ($entities as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $selected) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<?php if (!$selected && count($rows) > 1): ?><div class="card mb-3"><h3><?= icon('chart-column') ?> مقایسه میانگین پیشرفت</h3><div class="chart" data-chart='<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>' data-height="240"></div></div><?php endif; ?>
<div class="card flush mb-3"><div class="table-wrap"><table class="table">
    <thead><tr><th>عنوان</th><th>تعداد افراد</th><th>فعال</th><th>غیرفعال</th><th>میانگین پیشرفت</th><th>دوره تکمیل‌شده</th><th>عقب‌مانده</th><th>میانگین نمره</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td class="fw-b"><a href="<?= url('/admin/reports/groups', ['dim' => $dim, 'id' => $r['id']]) ?>"><?= e($r['name']) ?></a></td><td class="num"><?= nf($r['members']) ?></td><td class="num" style="color:var(--success)"><?= nf($r['active']) ?></td><td class="num" style="color:var(--danger)"><?= nf($r['inactive']) ?></td>
    <td style="min-width:140px"><?php if ($r['prog'] !== null): ?><div class="flex"><div class="grow"><?= progress_bar((float)$r['prog']) ?></div><span class="small"><?= fa(round((float)$r['prog'])) ?>٪</span></div><?php else: ?>—<?php endif; ?></td>
    <td class="num"><?= nf($r['done']) ?></td><td class="num"><?= $r['laggards'] ? '<span class="badge badge-danger">' . fa($r['laggards']) . '</span>' : '—' ?></td><td class="num"><?= $r['avg_score'] !== null ? fa(round((float)$r['avg_score'])) : '—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($selected && !empty($rows[0]['people'])): ?>
<div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('users') ?> افراد</h3></div><div class="table-wrap"><table class="table"><thead><tr><th>نام</th><th>آخرین ورود</th><th>پیشرفت</th><th>تکمیل</th><th>عقب‌افتاده</th></tr></thead><tbody>
<?php foreach ($rows[0]['people'] as $p): ?><tr><td><a href="<?= url('/admin/reports/user/' . $p['id']) ?>"><?= person_name($p, 'id') ?></a></td><td class="small"><?= $p['last_login_at'] ? time_ago($p['last_login_at']) : 'هرگز' ?></td><td style="min-width:130px"><?= $p['prog'] !== null ? progress_bar((float)$p['prog']) : '—' ?></td><td class="num"><?= fa($p['done']) ?></td><td><?= $p['late'] ? '<span class="badge badge-danger">' . fa($p['late']) . '</span>' : '—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
