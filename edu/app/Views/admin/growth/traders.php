<?php use App\Services\TraderGrowth; $q = $_GET; ?>
<div class="page-head">
    <div><h1>جایگاه تاجران</h1><div class="sub">مرحله، درصد پیشرفت، رتبه و وضعیت خدمات هر تاجر</div></div>
    <?php if (can('growth.export')): ?><a class="btn btn-outline" href="<?= url('/admin/growth/traders', array_merge($q, ['export' => 1])) ?>"><?= icon('file-spreadsheet') ?> خروجی Excel</a><?php endif; ?>
</div>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="card mb-3 filters" method="get">
    <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
        <div class="field"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="نام یا هر شماره موبایل"></div>
        <div class="field"><label>مرحله</label><select name="stage"><option value="">همه</option><?php foreach ($stages as $s): ?><option value="<?= $s['no'] ?>"<?= selected($s['no'], $q['stage'] ?? '') ?>><?= fa($s['no']) ?>. <?= e($s['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>رتبه</label><select name="rank"><option value="">همه</option><?php foreach ($ranks as $r): ?><option value="<?= (int)$r['id'] ?>"<?= selected($r['id'], $q['rank'] ?? '') ?>><?= e($r['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>مسیر</label><select name="track"><option value="">همه</option><option value="0"<?= selected('0', $q['track'] ?? '') ?>>انتخاب‌نشده</option><?php foreach ($tracks as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($t['id'], $q['track'] ?? '') ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>خدمات</label><select name="sync"><option value="">همه</option><option value="never"<?= selected('never', $q['sync'] ?? '') ?>>هرگز بروزرسانی نشده</option><option value="error"<?= selected('error', $q['sync'] ?? '') ?>>خطا در آخرین بروزرسانی</option></select></div>
        <div class="field"><label>مرتب‌سازی</label><select name="sort"><option value="">بالاترین مرحله</option><option value="low"<?= selected('low', $q['sort'] ?? '') ?>>پایین‌ترین مرحله</option><option value="name"<?= selected('name', $q['sort'] ?? '') ?>>نام</option></select></div>
    </div>
    <button class="btn btn-primary btn-sm"><?= icon('filter') ?> اعمال</button> <a class="btn btn-ghost btn-sm" href="<?= url('/admin/growth/traders') ?>">پاک کردن</a>
</form>
<div class="card flush">
    <div class="card-head"><h3><?= nf($page['total']) ?> تاجر</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>تاجر</th><th>مرحله</th><th style="min-width:140px">پیشرفت مرحله</th><th>مسیر</th><th>خدمات</th><th>معاملات</th><th>بروزرسانی خدمات</th></tr></thead>
        <tbody>
        <?php if (!$page['rows']): ?><tr><td colspan="7"><div class="empty"><?= icon('users') ?><div>تاجری یافت نشد.</div></div></td></tr><?php endif; ?>
        <?php foreach ($page['rows'] as $u): $st = TraderGrowth::stageByNo((int)$u['tg_stage']); ?>
            <tr>
                <td><a class="person" href="<?= url('/admin/growth/user/' . $u['id']) ?>" style="color:var(--text)"><?= avatar_html($u, 'sm') ?><div><div class="nm"><?= user_name_html($u) ?></div><div class="sub ltr" style="text-align:right"><?= e($u['mobile']) ?></div></div></a></td>
                <td><b><?= fa($u['tg_stage']) ?>.</b> <?= e($st['title'] ?? '') ?><?= (int)$u['pending'] ? ' <span class="badge badge-warning">' . fa($u['pending']) . ' در انتظار</span>' : '' ?></td>
                <td><div class="flex"><div class="grow"><?= progress_bar((float)$u['tg_progress']) ?></div><span class="small"><?= fa(round((float)$u['tg_progress'])) ?>٪</span></div></td>
                <td class="small"><?= $u['track_name'] ? e($u['track_name']) : '<span class="faint">—</span>' ?></td>
                <td class="num"><?= fa($u['svc_count']) ?></td>
                <td class="num"><?= fa($u['deals']) ?></td>
                <td class="small"><?= $u['tg_services_at'] ? time_ago($u['tg_services_at']) : '<span class="faint">هرگز</span>' ?><?= $u['tg_services_error'] ? ' <span class="badge badge-danger" title="' . e($u['tg_services_error']) . '">خطا</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?= paginate_links($page) ?>
