<?php $cur = currency_label(); $stLbl = ['pending' => 'در انتظار', 'approved' => 'تأییدشده', 'rejected' => 'ردشده']; ?>
<div class="page-head"><div><h1>بررسی معاملات و مدارک</h1><div class="sub">معاملات ثبت‌شده تاجران و درخواست‌های تأیید مرحله (با مدارک)</div></div></div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="flex between flex-wrap gap-2 mb-2">
    <div class="pill-nav" style="margin:0">
        <a class="<?= $tab !== 'requests' ? 'active' : '' ?>" href="<?= url('/admin/growth/reviews', ['tab' => 'deals', 'status' => $status]) ?>"><?= icon('handshake') ?> معاملات <?= $counts['deals'] ? '<span class="badge badge-warning">' . fa($counts['deals']) . '</span>' : '' ?></a>
        <a class="<?= $tab === 'requests' ? 'active' : '' ?>" href="<?= url('/admin/growth/reviews', ['tab' => 'requests', 'status' => $status]) ?>"><?= icon('file-check') ?> درخواست تأیید مرحله <?= $counts['requests'] ? '<span class="badge badge-warning">' . fa($counts['requests']) . '</span>' : '' ?></a>
    </div>
    <div class="pill-nav" style="margin:0"><?php foreach ($stLbl as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="<?= url('/admin/growth/reviews', ['tab' => $tab, 'status' => $k]) ?>"><?= $l ?></a><?php endforeach; ?></div>
</div>
<div class="card flush">
<?php if ($tab !== 'requests'): ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>تاجر</th><th>محصول / مشتری</th><th>مبلغ</th><th>تاریخ معامله</th><th>مدارک</th><th>ثبت</th><th></th></tr></thead>
        <tbody>
        <?php if (!$deals): ?><tr><td colspan="7"><div class="empty"><?= icon('handshake') ?><div>موردی نیست.</div></div></td></tr><?php endif; ?>
        <?php foreach ($deals as $d): ?>
            <tr><td><a class="person" href="<?= url('/admin/growth/user/' . $d['uid']) ?>" style="color:var(--text)"><?= avatar_html(['id' => $d['uid'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'avatar_path' => $d['avatar_path']], 'sm') ?><div class="nm"><?= person_name($d, 'uid') ?></div></a></td>
                <td><b class="small"><?= e($d['product']) ?></b><div class="small faint"><?= e($d['customer']) ?><?= $d['market'] ? ' · ' . e($d['market']) : '' ?></div></td>
                <td class="num small"><?= $d['amount'] !== null ? nf($d['amount']) . ' ' . e($cur) : '—' ?></td>
                <td class="small"><?= $d['deal_date'] ? jdate($d['deal_date']) : '—' ?></td>
                <td class="num"><?= fa($d['docs']) ?></td>
                <td class="small"><?= time_ago($d['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= url('/admin/growth/review/deal/' . $d['id']) ?>"><?= icon('eye') ?> بررسی</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>تاجر</th><th>مرحله</th><th>مدارک</th><th>ارسال</th><th></th></tr></thead>
        <tbody>
        <?php if (!$requests): ?><tr><td colspan="5"><div class="empty"><?= icon('file-check') ?><div>موردی نیست.</div></div></td></tr><?php endif; ?>
        <?php foreach ($requests as $q): ?>
            <tr><td><a class="person" href="<?= url('/admin/growth/user/' . $q['uid']) ?>" style="color:var(--text)"><?= avatar_html(['id' => $q['uid'], 'first_name' => $q['first_name'], 'last_name' => $q['last_name'], 'avatar_path' => $q['avatar_path']], 'sm') ?><div class="nm"><?= person_name($q, 'uid') ?></div></a></td>
                <td><b class="small"><?= fa($q['stage_sort']) ?>. <?= e($q['stage_title']) ?></b><?= $q['note'] ? '<div class="small faint">' . e(str_limit($q['note'], 70)) . '</div>' : '' ?></td>
                <td class="num"><?= fa($q['docs']) ?></td>
                <td class="small"><?= time_ago($q['created_at']) ?></td>
                <td><a class="btn btn-sm btn-primary" href="<?= url('/admin/growth/review/request/' . $q['id']) ?>"><?= icon('eye') ?> بررسی</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
