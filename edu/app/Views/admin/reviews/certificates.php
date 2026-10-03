<div class="page-head"><div><h1>گواهی‌ها</h1><div class="sub">گواهی‌های صادرشده (خودکار پس از تکمیل دوره‌های دارای گواهی یا صدور دستی)</div></div>
<?php if (can('certificates.export')): ?><a class="btn btn-outline" href="<?= url('/admin/certificates', ['export' => 1, 'q' => $_GET['q'] ?? '']) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?></div>
<div class="grid g-main">
    <div class="stack">
        <form class="card filters" method="get" action="<?= url('/admin/certificates') ?>"><div class="field grow"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="کد، نام یا دوره"></div><button class="btn btn-primary"><?= icon('search') ?></button></form>
        <div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>کد</th><th>نام</th><th>دوره</th><th>نمره</th><th>صدور</th><th>اعتبار</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($page['rows'] as $c): ?><tr><td class="ltr num"><a href="<?= url('/learn/certificate/' . $c['code']) ?>" target="_blank"><?= e($c['code']) ?></a></td><td><?= e($c['user_name']) ?></td><td class="small"><?= e($c['course_title']) ?></td><td class="num"><?= $c['score'] !== null ? fa((float)$c['score']) : '—' ?></td><td class="num small"><?= jdate($c['issued_at']) ?></td><td class="num small"><?= $c['expires_at'] ? jdate($c['expires_at']) : 'دائمی' ?></td><td><?= $c['revoked_at'] ? '<span class="badge badge-danger">ابطال</span>' : '<span class="badge badge-success">معتبر</span>' ?></td>
        <td class="actions"><?php if (!$c['revoked_at'] && can('certificates.delete')): ?><form class="inline" method="post" action="<?= url('/admin/certificates/' . $c['id'] . '/revoke') ?>" data-confirm="گواهی ابطال شود؟"><?= csrf_field() ?><input type="hidden" name="reason" value="ابطال توسط مدیر"><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('x') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$page['rows']): ?><tr><td colspan="8"><div class="empty"><?= icon('medal') ?><div>گواهی صادر نشده</div></div></td></tr><?php endif; ?>
        </tbody></table></div></div>
        <?= paginate_links($page) ?>
    </div>
    <?php if (can('certificates.create')): ?>
    <form class="card" method="post" action="<?= url('/admin/certificates') ?>"><?= csrf_field() ?>
        <h3><?= icon('medal') ?> صدور دستی گواهی</h3>
        <p class="small muted">فراگیرانی که دوره را تکمیل کرده‌اند و هنوز گواهی ندارند:</p>
        <select name="pair" size="10" required><?php foreach ($candidates as $c): ?><option value="<?= (int)$c['user_id'] ?>:<?= (int)$c['course_id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?> — <?= e($c['title']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary mt-1 w-100"><?= icon('award') ?> صدور گواهی</button>
    </form>
    <?php endif; ?>
</div>
