<?php use App\Services\Credit; $C = Credit::class; ?>
<div class="page-head"><div><h1>اعتبارهای من</h1><div class="sub">موجودی و تاریخچه اعتبار دوره‌ها، وبینارها، کارگاه‌ها و اشتراک میتینگ</div></div>
    <a class="btn btn-outline" href="<?= url('/me/services') ?>"><?= icon('bookmark') ?> خدمات و جلسات من</a></div>
<div class="svc-credits mb-3">
    <div class="svc-c tone-primary"><span class="ic"><?= icon('graduation-cap') ?></span><div><span class="l">اعتبار دوره‌ها</span><b><?= Credit::format($bal['course']) ?></b><small>مصرف‌شده: <?= Credit::format($totals['used']) ?></small></div></div>
    <div class="svc-c tone-purple"><span class="ic"><?= icon('video') ?></span><div><span class="l">اعتبار وبینار</span><b><?= Credit::format($bal['webinar']) ?></b></div></div>
    <div class="svc-c tone-warning"><span class="ic"><?= icon('briefcase') ?></span><div><span class="l">اعتبار کارگاه آنلاین</span><b><?= fa($bal['workshop']) ?> عدد</b></div></div>
    <div class="svc-c tone-info"><span class="ic"><?= icon('users') ?></span><div><span class="l">اشتراک میتینگ</span><?php if ($bal['meeting_active']): ?><b>فعال تا <?= jdate($bal['meeting_until']) ?></b><small><?= fa($bal['meeting_days']) ?> روز باقی‌مانده</small><?php else: ?><b>غیرفعال</b><?php endif; ?></div></div>
    <?php if ($bal['account_until']): ?><div class="svc-c tone-success"><span class="ic"><?= icon('badge-check') ?></span><div><span class="l">اکانت سامانه آموزش</span><?php if ($bal['account_active']): ?><b>فعال تا <?= jdate($bal['account_until']) ?></b><small><?= fa($bal['account_days']) ?> روز باقی‌مانده</small><?php else: ?><b>منقضی</b><small>پایان: <?= jdate($bal['account_until']) ?></small><?php endif; ?></div></div><?php endif; ?>
</div>
<div class="alert alert-info"><?= icon('info') ?><div>هر درس هنگام فعال‌سازی به اندازه زمان خودش از اعتبار دوره‌ها، و هر وبینار به اندازه مدتش از اعتبار وبینار کم می‌کند؛ هر کارگاه یک عدد اعتبار کارگاه مصرف می‌کند. <?= e(setting('minutes_charge_text')) ?></div></div>
<div class="card flush">
    <div class="card-head"><h3><?= icon('history') ?> تاریخچه</h3></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>تاریخ</th><th>نوع</th><th>شرح</th><th>تغییر</th><th>مانده</th></tr></thead>
        <tbody>
        <?php if (!$ledger): ?><tr><td colspan="5"><div class="empty"><?= icon('clock') ?><div>هنوز تراکنشی ثبت نشده است.</div></div></td></tr><?php endif; ?>
        <?php foreach ($ledger as $r): $ct = $r['credit_type'] ?? 'course'; ?>
            <tr>
                <td class="nowrap"><?= jdatetime($r['created_at']) ?></td>
                <td><span class="badge badge-gray"><?= e(['course' => 'دوره', 'webinar' => 'وبینار', 'workshop' => 'کارگاه', 'meeting' => 'میتینگ', 'account' => 'اکانت'][$ct] ?? $ct) ?></span></td>
                <td><?php if ($r['kind'] === 'consume'): ?><?= $r['lesson_title'] ? 'فعال‌سازی درس «' . e($r['lesson_title']) . '»' . ($r['course_title'] ? ' <span class="faint small">— ' . e($r['course_title']) . '</span>' : '') : 'ثبت‌نام در «' . e($r['event_title'] ?? $r['note']) . '»' ?>
                    <?php else: ?><?= ['charge' => in_array($ct, ['meeting', 'account'], true) ? 'فعال‌سازی / تمدید اشتراک' : 'شارژ اعتبار', 'deduct' => 'کسر توسط مدیر', 'refund' => 'بازگشت اعتبار'][$r['kind']] ?? $r['kind'] ?><?= $r['note'] ? ' <span class="faint small">— ' . e($r['note']) . '</span>' : '' ?><?php endif; ?></td>
                <td class="num"><span class="badge badge-<?= (int)$r['delta'] > 0 ? 'success' : 'warning' ?>"><?= (int)$r['delta'] > 0 ? '+' : '−' ?><?= e($C::amount($ct, abs((int)$r['delta']))) ?></span></td>
                <td class="num small"><?= e($C::amount($ct, (int)$r['balance_after'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
