<?php
use App\Services\TraderGrowth;
$cur = currency_label();
$st = ['pending' => ['در انتظار بررسی', 'warning'], 'approved' => ['تأیید شده', 'success'], 'rejected' => ['رد / نیاز به اصلاح', 'danger']][$row['status']] ?? [$row['status'], 'gray'];
$uid = (int)$u['id'];
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/growth/reviews', ['tab' => $type === 'deal' ? 'deals' : 'requests']) ?>">بررسی معاملات و مدارک</a></div>
        <h1><?= $type === 'deal' ? 'معامله: ' . e($row['product']) : 'درخواست تأیید مرحله «' . e($stage['title'] ?? '') . '»' ?></h1>
        <div class="sub"><?= user_name_html($u) ?> · <?= jdatetime($row['created_at']) ?></div></div>
    <span class="badge badge-<?= $st[1] ?>" style="font-size:.9rem"><?= $st[0] ?></span>
</div>
<div class="grid g-main">
    <div class="stack">
        <div class="card">
            <?php if ($type === 'deal'): ?>
                <h3><?= icon('handshake') ?> اطلاعات معامله</h3>
                <div class="grid g-2">
                    <div><div class="small faint">محصول</div><b><?= e($row['product']) ?></b></div>
                    <div><div class="small faint">مشتری</div><b><?= e($row['customer']) ?></b></div>
                    <div><div class="small faint">مبلغ</div><b><?= $row['amount'] !== null ? nf($row['amount']) . ' ' . e($cur) : '—' ?></b></div>
                    <div><div class="small faint">تاریخ معامله</div><b><?= $row['deal_date'] ? jdate($row['deal_date']) : '—' ?></b></div>
                    <div><div class="small faint">بازار / مقصد</div><b><?= e($row['market'] ?: '—') ?></b></div>
                </div>
                <?php if ($row['description']): ?><hr><div class="muted"><?= nl2br(e($row['description'])) ?></div><?php endif; ?>
            <?php else: ?>
                <h3><?= icon('file-check') ?> درخواست</h3>
                <?php if ($stage['goal']): ?><div class="tg-goal"><?= icon('target') ?> <b>هدف مرحله:</b> <?= e($stage['goal']) ?></div><?php endif; ?>
                <?php $need = TraderGrowth::docLines($stage['request_docs']); if ($need): ?><div class="small mb-2">مدارک لازم: <?= e(implode('، ', $need)) ?></div><?php endif; ?>
                <?= $row['note'] ? '<div class="muted">' . nl2br(e($row['note'])) . '</div>' : '<div class="faint small">توضیحی ثبت نشده.</div>' ?>
                <?php if ($stageEv): ?><hr><div class="flex gap-2"><?= progress_ring($stageEv['shown'], 60, $stageEv['meets'] ? 'success' : 'warning') ?><div class="small">پیشرفت فعلی این مرحله: <b><?= fa(round($stageEv['shown'])) ?>٪</b> (شرط عبور <?= fa((int)$stage['pass_percent']) ?>٪)<br><span class="faint"><?= e(TraderGrowth::APPROVALS[$stage['approval']] ?? '') ?></span></div></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3><?= icon('file-down') ?> مدارک (<?= fa(count($docs)) ?>)</h3>
            <?php $videos = array_filter($docs, fn($d) => $d['kind'] === 'video'); ?>
            <?php include APP_PATH . '/Views/partials/growth_docs.php'; ?>
            <?php foreach ($videos as $vd): ?><div class="mt-2"><div class="small fw-b mb-1"><?= e($vd['label']) ?></div><video controls preload="metadata" style="width:100%;max-height:420px;border-radius:12px;background:#000" src="<?= e(file_url($vd['file_id'])) ?>"></video></div><?php endforeach; ?>
        </div>
        <?php if ($row['status'] !== 'pending' && $row['review_note']): ?><div class="card"><h3><?= icon('message-square') ?> نظر کارشناس</h3><div><?= e($row['review_note']) ?></div><div class="small faint mt-1"><?= $reviewer ? e(full_name($reviewer)) . ' · ' : '' ?><?= jdatetime($row['reviewed_at']) ?></div></div><?php endif; ?>
    </div>
    <div class="stack">
        <?php if (can('growth.approve')): ?>
        <form class="card" method="post" action="<?= url('/admin/growth/review/' . $type . '/' . $row['id']) ?>"><?= csrf_field() ?>
            <h3><?= icon('user-check') ?> نتیجه بررسی</h3>
            <div class="field"><label>توضیح برای تاجر</label><textarea name="review_note" rows="3" placeholder="برای رد، علت و مدارک لازم را بنویسید"><?= e($row['review_note'] ?? '') ?></textarea></div>
            <label class="check small mb-2"><input type="checkbox" name="next" value="1" checked> پس از ثبت، مورد بعدی را باز کن</label>
            <div class="flex flex-wrap">
                <button class="btn btn-success" name="decision" value="approved"><?= icon('check') ?> تأیید</button>
                <button class="btn btn-outline" name="decision" value="rejected" style="color:var(--danger)"><?= icon('x') ?> رد / نیاز به اصلاح</button>
                <?php if ($row['status'] !== 'pending'): ?><button class="btn btn-ghost btn-sm" name="decision" value="pending"><?= icon('rotate-ccw') ?> برگشت به انتظار</button><?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
        <div class="card">
            <h3><?= icon('user') ?> تاجر</h3>
            <div class="flex gap-2 mb-2"><?= avatar_html($u, 'md') ?><div><b><?= user_name_html($u) ?></b><div class="small faint ltr" style="text-align:right"><?= e($u['mobile']) ?></div></div></div>
            <div class="small">مرحله فعلی: <b><?= fa($ev['current']) ?>. <?= e($ev['cur']['stage']['title'] ?? '') ?></b></div>
            <div class="small">معاملات تأییدشده: <b><?= fa($stats['count']) ?></b> · مشتری‌ها: <b><?= fa($stats['customers']) ?></b> (تکرارشونده: <?= fa($stats['repeat_customers']) ?>)</div>
            <div class="small">ماه‌های دارای فروش: <b><?= fa($stats['months']) ?></b> · محصولات: <b><?= fa($stats['products']) ?></b><?= $stats['sum'] ? ' · جمع: <b>' . nf($stats['sum']) . '</b> ' . e($cur) : '' ?></div>
            <a class="btn btn-outline btn-sm mt-2 w-100" href="<?= url('/admin/growth/user/' . $uid) ?>"><?= icon('route') ?> مسیر رشد کامل تاجر</a>
        </div>
        <?php if ($otherDeals): ?>
        <div class="card"><h3><?= icon('handshake') ?> سایر معاملات</h3>
            <?php foreach ($otherDeals as $d): ?><a class="list-item small" href="<?= url('/admin/growth/review/deal/' . $d['id']) ?>" style="color:var(--text)"><span class="grow"><?= e($d['product']) ?> ← <?= e($d['customer']) ?></span><?= status_badge($d['status']) ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
