<?php
/**
 * One stage of the trader growth path.
 * @var array $o evaluated stage  @var array $ev  @var bool $admin  @var array $trackNames  @var int $uid
 */
use App\Services\TraderGrowth;
use App\Services\EventService;
$s = $o['stage'];
$stateLbl = ['done' => ['تکمیل شده', 'success'], 'current' => ['مرحله فعلی', 'primary'], 'reopened' => ['نیازمند تکمیل موارد جدید', 'warning'], 'locked' => ['قفل', 'gray']][$o['state']];
if ($o['waived']) $stateLbl = ['معاف (تعیین مرحله توسط مدیر)', 'info'];
$open = in_array($o['state'], ['current', 'reopened'], true) || !empty($forceOpen);
$compLbl = ['edu' => ['آموزش‌ها', 'graduation-cap'], 'svc' => ['خدمات', 'package'], 'deal' => ['معاملات', 'handshake']];
$docs = TraderGrowth::docLines($s['request_docs']);
$trackTag = fn($tid) => $tid ? ' <span class="badge badge-info">' . e($trackNames[(int)$tid] ?? 'مسیر') . '</span>' : '';
$canAct = $admin && can('growth.approve');
?>
<details class="card tg-stage" id="stage-<?= $o['no'] ?>" style="--stc:<?= e($s['color']) ?>"<?= $open ? ' open' : '' ?>>
    <summary>
        <span class="sno"><?= icon($s['icon']) ?></span>
        <span class="grow"><span class="faint small">مرحله <?= fa($o['no']) ?><?= $s['season_title'] ? ' · ' . e($s['season_title']) : '' ?></span><br><span class="t"><?= e($s['title']) ?></span></span>
        <span class="badge badge-<?= $stateLbl[1] ?>"><?= e($stateLbl[0]) ?></span>
        <?php if ($o['defined'] || $o['complete']): ?><?= progress_ring($o['shown'], 52, $o['complete'] ? 'success' : ($o['state'] === 'reopened' ? 'warning' : 'primary')) ?><?php endif; ?>
        <span class="chev"><?= icon('chevron-down') ?></span>
    </summary>
    <div class="tg-body">
        <?php if ($s['goal']): ?><div class="tg-goal"><?= icon('target') ?> <b>هدف:</b> <?= e($s['goal']) ?></div><?php endif; ?>
        <?php if ($s['description']): ?><div class="prose small mb-2"><?= clean_html($s['description']) ?></div><?php endif; ?>
        <?php if ($o['state'] === 'reopened'): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?><div>به این مرحله موارد جدید اضافه شده است. تا تکمیل آن‌ها، این مرحله کامل محسوب نمی‌شود و ارتقا به مراحل بعد متوقف است.</div></div><?php endif; ?>

        <?php if ($o['shares']): ?>
        <div class="tg-comps">
            <?php foreach ($o['shares'] as $k => $share): $pct = $k === 'deal' ? $o['deal']['pct'] : $o[$k]['pct']; ?>
                <div class="tg-comp"><div class="h"><span><?= icon($compLbl[$k][1]) ?> <?= $compLbl[$k][0] ?> <span class="faint">(وزن <?= fa($share) ?>٪)</span></span><b><?= fa(round($pct)) ?>٪</b></div><?= progress_bar($pct) ?>
                    <div class="small faint mt-1"><?= $k === 'deal' ? fa(min($o['deal']['count'], $o['deal']['need'])) . ' از ' . fa($o['deal']['need']) . ' معامله تأییدشده' : fa($o[$k]['done']) . ' از ' . fa($o[$k]['units']) . ' مورد' ?></div></div>
            <?php endforeach; ?>
            <div class="tg-comp"><div class="h"><span><?= icon('flag') ?> شرط عبور</span><b><?= fa((int)$s['pass_percent']) ?>٪</b></div><div class="small muted"><?= e(TraderGrowth::APPROVALS[$s['approval']] ?? '') ?></div></div>
        </div>
        <?php elseif (!$o['defined']): ?>
            <div class="alert alert-info"><?= icon('hourglass') ?><div>الزامات این مرحله هنوز تعریف نشده است<?= $admin && can('growth.edit') ? ' — <a href="' . url('/admin/growth/stages/' . $s['id']) . '">تعریف الزامات</a>' : '' ?>.</div></div>
        <?php endif; ?>

        <?php if ($o['edu']['rows']): ?>
            <div class="tg-sec"><?= icon('graduation-cap') ?> آموزش‌ها</div>
            <ul class="tg-list">
            <?php foreach ($o['edu']['rows'] as $r): $t = EventService::TYPES[$r['etype'] ?? ''] ?? null; ?>
                <li class="<?= $r['done'] ? 'ok' : 'no' ?>"><?= icon($r['done'] ? 'circle-check' : 'circle-x') ?>
                    <div class="grow">
                        <?php if ($r['kind'] === 'course'): ?>
                            <a href="<?= url($r['url']) ?>"><b>دوره «<?= e($r['title']) ?>»</b></a><?= $trackTag($r['track_id']) ?>
                            <div class="small faint"><?= $r['done'] ? 'تکمیل شده' : ($r['pct'] !== null ? 'پیشرفت ' . fa((int)$r['pct']) . '٪' : 'شروع نشده') ?></div>
                        <?php elseif ($r['kind'] === 'event'): ?>
                            <a href="<?= url($r['url']) ?>"><b><?= e($t['label'] ?? 'جلسه') ?> «<?= e($r['title']) ?>»</b></a><?= $trackTag($r['track_id']) ?>
                            <div class="small faint"><?= $r['date'] ? jdatetime($r['date']) . ' · ' : '' ?><?= $r['done'] ? 'شرکت کرده‌اید' : 'هنوز شرکت نکرده‌اید' ?></div>
                        <?php else: ?>
                            <a href="<?= url($r['url']) ?>"><b><?= e($r['title']) ?></b></a><?= $trackTag($r['track_id']) ?>
                            <div class="small faint">شرکت در <?= fa($r['count']) ?> از <?= fa($r['total']) ?><?= $r['kind'] === 'event_all' ? ' جلسه — جلسات جدید خودکار به این فهرست اضافه می‌شوند' : '' ?></div>
                            <?php if (!empty($r['sub'])): ?><details><summary>نمایش جلسات</summary><div class="sub"><?php foreach ($r['sub'] as $x): ?><span class="<?= $x['done'] ? 'ok' : '' ?>"><?= $x['done'] ? '✓ ' : '' ?><?= e(str_limit($x['title'], 40)) ?></span><?php endforeach; ?></div></details><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($admin && (int)$r['weight'] > 1): ?><span class="badge badge-gray">وزن <?= fa($r['weight']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($o['svc']['rows']): ?>
            <div class="tg-sec"><?= icon('package') ?> خدمات</div>
            <div class="table-wrap"><table class="tg-svc">
                <?php foreach ($o['svc']['rows'] as $r): ?>
                    <tr>
                        <td><b><?= e($r['title']) ?></b><?= $trackTag($r['track_id']) ?><?php if ($r['info']): ?><div class="small faint"><?= $r['info']['source'] === 'manual' ? 'ثبت توسط کارشناس' : 'از سامانه فروش' ?><?= $r['info']['purchased_at'] ? ' · ' . jdate($r['info']['purchased_at']) : '' ?><?= $admin && $r['info']['phone'] ? ' · <span class="ltr">' . e($r['info']['phone']) . '</span>' : '' ?></div><?php endif; ?></td>
                        <td class="nowrap"><?= $r['done'] ? '<span class="tg-yes">دریافت شده ✓</span>' : '<span class="tg-no">دریافت نشده ✕</span>' ?></td>
                        <?php if (!$admin): ?><td class="nowrap" style="text-align:left"><?php if (!$r['done'] && $r['buy_url']): ?><a class="btn btn-xs btn-outline" target="_blank" rel="noopener" href="<?= e($r['buy_url']) ?>">درخواست خدمت</a><?php endif; ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>

        <?php if ($o['deal']): ?>
            <div class="tg-sec"><?= icon('handshake') ?> معاملات</div>
            <div class="flex flex-wrap gap-2"><div class="grow" style="min-width:200px"><?= progress_bar($o['deal']['pct'], 'success') ?></div>
                <span class="small"><b><?= fa($o['deal']['count']) ?></b> معامله تأییدشده از <?= fa($o['deal']['need']) ?> موردنیاز<?= $o['deal']['pending'] ? ' · ' . fa($o['deal']['pending']) . ' در انتظار بررسی' : '' ?></span>
                <?php if (!$admin): ?><a class="btn btn-xs btn-primary" href="#deals"><?= icon('plus') ?> ثبت معامله</a><?php endif; ?></div>
        <?php endif; ?>

        <?php if ($s['approval'] !== 'auto' || $o['waived'] || $o['approved'] || $canAct): ?>
        <div class="tg-approval">
            <div class="flex between flex-wrap gap-2">
                <div><b><?= icon('user-check') ?> تأیید کارشناس</b>
                    <div class="small muted"><?= $s['approval'] === 'auto' ? 'این مرحله خودکار تأیید می‌شود.' : e(TraderGrowth::APPROVALS[$s['approval']]) ?><?= $s['request_hint'] ? ' — ' . e($s['request_hint']) : '' ?></div>
                    <?php if ($docs): ?><div class="small mt-1">مدارک لازم: <?= e(implode('، ', $docs)) ?></div><?php endif; ?>
                </div>
                <div>
                    <?php if ($o['waived']): ?><span class="badge badge-info">معاف شده</span>
                    <?php elseif ($o['approved']): ?><span class="badge badge-success"><?= icon('check') ?> تأیید شده</span>
                    <?php elseif ($o['pending_request']): ?><span class="badge badge-warning"><?= icon('hourglass') ?> در انتظار بررسی</span>
                    <?php elseif ($o['request'] && $o['request']['status'] === 'rejected'): ?><span class="badge badge-danger">نیاز به اصلاح</span><?php endif; ?>
                </div>
            </div>
            <?php if ($o['request'] && $o['request']['status'] === 'rejected' && $o['request']['review_note']): ?><div class="alert alert-danger mt-2 mb-0"><?= icon('message-square') ?><div><b>نظر کارشناس:</b> <?= e($o['request']['review_note']) ?></div></div><?php endif; ?>
            <?php if (!$admin && $o['can_request'] && in_array($o['state'], ['current', 'reopened'], true)): ?>
                <form class="mt-2" method="post" enctype="multipart/form-data" action="<?= url('/learn/growth/request/' . $s['id']) ?>"><?= csrf_field() ?>
                    <?php if ($docs): ?><div class="tg-doc-inputs mb-2"><?php foreach ($docs as $i => $lbl): ?><div><label><?= e($lbl) ?> <span class="req">*</span></label><input type="file" name="docs[<?= $i ?>][]" multiple></div><?php endforeach; ?></div><?php endif; ?>
                    <div class="field"><label>توضیحات برای کارشناس</label><textarea name="note" rows="3" placeholder="خلاصه‌ای از کارهایی که در این مرحله انجام داده‌اید…"></textarea></div>
                    <div class="field"><label>سایر مدارک (اختیاری)</label><input type="file" name="docs_extra[]" multiple></div>
                    <button class="btn btn-primary"><?= icon('send') ?> ارسال درخواست تأیید مرحله</button>
                </form>
            <?php endif; ?>
            <?php if ($canAct): ?>
                <div class="flex flex-wrap gap-2 mt-2">
                    <?php if (!$o['approved'] && !$o['waived']): ?>
                        <form method="post" action="<?= url('/admin/growth/user/' . $uid . '/stage/' . $s['id']) ?>" class="flex flex-wrap" data-confirm="مرحله «<?= e($s['title']) ?>» برای این تاجر تأیید شود؟"><?= csrf_field() ?><input type="text" name="note" placeholder="یادداشت (اختیاری)" style="width:200px"><button class="btn btn-sm btn-success"><?= icon('check') ?> تأیید مرحله</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= url('/admin/growth/user/' . $uid . '/stage/' . $s['id']) ?>" data-confirm="تأیید/معافیت این مرحله برداشته شود؟"><?= csrf_field() ?><input type="hidden" name="op" value="revoke"><button class="btn btn-sm btn-ghost" style="color:var(--danger)"><?= icon('rotate-ccw') ?> لغو <?= $o['waived'] ? 'معافیت' : 'تأیید' ?></button></form>
                    <?php endif; ?>
                    <?php if ($o['pending_request']): ?><a class="btn btn-sm btn-outline" href="<?= url('/admin/growth/review/request/' . $o['request']['id']) ?>"><?= icon('file-check') ?> بررسی درخواست</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</details>
