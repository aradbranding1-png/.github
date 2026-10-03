<div class="page-head"><div><h1>تمرین‌های من</h1><div class="sub">تمرین‌های دوره‌های شما و وضعیت بررسی آن‌ها</div></div></div>
<div class="card flush">
<?php if (!$rows): ?><div class="empty"><?= icon('notebook-pen') ?><h3>تمرینی ندارید</h3></div><?php else: ?>
<div class="table-wrap"><table class="table">
    <thead><tr><th>تمرین</th><th>دوره</th><th>نوع</th><th>وضعیت</th><th>نمره</th><th>آخرین ارسال</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="fw-b"><?= e($r['title']) ?></td><td><?= e($r['course_title']) ?></td>
            <td><?= $r['is_required'] ? '<span class="badge badge-danger">الزامی</span>' : '<span class="badge badge-gray">اختیاری</span>' ?></td>
            <td><?= $r['sub_status'] ? status_badge($r['sub_status']) : '<span class="badge badge-gray">ارسال نشده</span>' ?></td>
            <td class="num"><?= $r['sub_score'] !== null ? fa((float)$r['sub_score']) . ' / ' . fa((float)$r['max_score']) : '—' ?></td>
            <td class="num"><?= $r['sub_at'] ? jdatetime($r['sub_at']) : '—' ?></td>
            <td class="actions"><a class="btn btn-sm btn-primary" href="<?= url('/learn/exercise/' . $r['id']) ?>">مشاهده</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
</div>
