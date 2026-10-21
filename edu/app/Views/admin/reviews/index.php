<?php
$K = App\Controllers\Admin\ReviewController::KINDS;
$tone = ['audio' => 'purple', 'text' => 'info', 'video' => 'danger', 'doc' => 'warning', 'image' => 'success', 'exam' => 'primary'];
$seg = $seg ?? ''; $gid = (int)($gid ?? 0); $segCounts = $segCounts ?? []; $groups = $groups ?? [];
$st = $st ?? ''; $stCounts = $stCounts ?? [];
$q = fn(array $o) => url('/admin/reviews', array_filter(array_merge(['tab' => $tab === 'done' ? 'done' : '', 'kind' => $kind, 'st' => $st, 'seg' => $seg, 'group' => $gid ?: ''], $o), fn($v) => $v !== '' && $v !== 0));
$segLabels = ['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', 'custom' => 'سایر'];
$all = array_sum($counts);
?>
<div class="page-head"><div><h1>بررسی و تصحیح</h1><div class="sub">تمرین‌های ارسالی و پاسخ‌های تشریحی آزمون‌ها — به تفکیک نوع ارسال</div></div></div>
<div class="tabs">
    <a class="<?= $tab !== 'done' ? 'active' : '' ?>" href="<?= $q(['tab' => '']) ?>"><?= icon('clock') ?> در انتظار بررسی<?= $tab !== 'done' ? ' (' . fa($all) . ')' : '' ?></a>
    <a class="<?= $tab === 'done' ? 'active' : '' ?>" href="<?= $q(['tab' => 'done']) ?>"><?= icon('circle-check') ?> بررسی‌شده</a>
</div>
<div class="flex flex-wrap" style="gap:.6rem;align-items:center;margin-bottom:.8rem">
    <div class="pill-nav" style="margin:0">
        <a class="<?= $seg === '' ? 'active' : '' ?>" href="<?= $q(['seg' => '']) ?>"><?= icon('users') ?> همه گروه‌ها (<?= fa(array_sum($segCounts)) ?>)</a>
        <?php foreach ($segLabels as $k => $t): if ($k === 'custom' && empty($segCounts[$k])) continue; ?><a class="<?= $seg === $k ? 'active' : '' ?>" href="<?= $q(['seg' => $k]) ?>"><?= e($t) ?> (<?= fa($segCounts[$k] ?? 0) ?>)</a><?php endforeach; ?>
    </div>
    <?php if ($groups): ?>
    <form method="get" action="<?= url('/admin/reviews') ?>" class="flex" style="gap:.4rem;margin-inline-start:auto">
        <?php foreach (['tab' => $tab === 'done' ? 'done' : '', 'kind' => $kind, 'seg' => $seg, 'st' => $st] as $hk => $hv): if ($hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= e($hv) ?>"><?php endif; endforeach; ?>
        <select name="group" aria-label="گروه سازمانی" style="min-width:12rem"><option value="">همه گروه‌های سازمانی</option><?php foreach ($groups as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $gid) ?>><?= e($t) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline btn-sm"><?= icon('filter') ?></button>
    </form>
    <?php endif; ?>
</div>
<?php if ($tab === 'done'): $stLabels = ['accepted' => 'تأیید شده', 'needs_revision' => 'نیازمند اصلاح', 'rejected' => 'رد شده']; ?>
<div class="pill-nav" style="margin:0 0 .8rem">
    <a class="<?= $st === '' ? 'active' : '' ?>" href="<?= $q(['st' => '']) ?>"><?= icon('filter') ?> همه نتایج</a>
    <?php foreach ($stLabels as $k => $t): ?><a class="<?= $st === $k ? 'active' : '' ?>" href="<?= $q(['st' => $k]) ?>"><?= e($t) ?> (<?= fa($stCounts[$k] ?? 0) ?>)</a><?php endforeach; ?>
</div>
<?php endif; ?>
<div class="kind-tabs">
    <a class="kind-tab<?= $kind === '' ? ' is-on' : '' ?>" href="<?= $q(['kind' => '']) ?>"><?= icon('layout-grid') ?><span>همه</span><b><?= fa($all) ?></b></a>
    <?php foreach ($K as $k => [$label, $ic]): ?>
        <a class="kind-tab tone-<?= $tone[$k] ?><?= $kind === $k ? ' is-on' : '' ?><?= empty($counts[$k]) ? ' is-zero' : '' ?>" href="<?= $q(['kind' => $k]) ?>"><?= icon($ic) ?><span><?= e($label) ?></span><b><?= fa($counts[$k] ?? 0) ?></b></a>
    <?php endforeach; ?>
</div>
<div class="grid <?= $kind === '' ? 'g-2' : '' ?>">
    <?php if ($kind !== 'exam'): ?>
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon($kind ? $K[$kind][1] : 'notebook-pen') ?> <?= $kind ? 'تمرین‌های ' . e($K[$kind][0]) : 'تمرین‌ها' ?> (<?= fa(count($subs)) ?>)</h3></div>
        <?php foreach ($subs as $s): [$kl, $ki] = $K[$s['rkind']] ?? $K['doc']; ?>
            <a class="list-item" href="<?= url('/admin/reviews/submission/' . $s['id']) ?>" style="padding:.8rem 1.25rem;color:var(--text)">
                <?= avatar_html(['id' => $s['user_id'], 'first_name' => $s['first_name'], 'last_name' => $s['last_name'], 'avatar_path' => $s['avatar_path']], 'sm') ?>
                <div class="grow"><b class="small"><?= person_name($s, 'user_id') ?></b><div class="small faint"><?= e($s['title']) ?> · <?= e($s['course_title']) ?></div></div>
                <span class="badge kind-badge tone-<?= $tone[$s['rkind']] ?? 'warning' ?>" title="<?= e((string)($s['original_name'] ?? 'پاسخ متنی')) ?>"><?= icon($ki) ?> <?= e($kl) ?></span>
                <?php if (!empty($s['text_answer'])): ?><?= wc_badge($s['text_answer']) ?><?php endif; ?>
                <div class="text-end"><?= status_badge($s['status']) ?><div class="small faint"><?php if ($tab === 'done' && !empty($s['reviewed_at'])): ?>تصحیح: <?= jdatetime($s['reviewed_at']) ?><?php else: ?><?= time_ago($s['created_at']) ?><?php endif; ?></div></div>
            </a>
        <?php endforeach; ?>
        <?php if (!$subs): ?><div class="empty"><?= icon('circle-check') ?><div>موردی نیست</div></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($kind === '' || $kind === 'exam'): ?>
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('clipboard-check') ?> پاسخ‌های تشریحی آزمون (<?= fa(count($atts)) ?>)</h3></div>
        <?php foreach ($atts as $a): ?>
            <a class="list-item" href="<?= url('/admin/reviews/attempt/' . $a['id']) ?>" style="padding:.8rem 1.25rem;color:var(--text)">
                <?= avatar_html(['id' => $a['user_id'], 'first_name' => $a['first_name'], 'last_name' => $a['last_name'], 'avatar_path' => $a['avatar_path']], 'sm') ?>
                <div class="grow"><b class="small"><?= person_name($a, 'user_id') ?></b><div class="small faint"><?= e($a['title']) ?> · دفعه <?= fa($a['attempt_no']) ?></div></div>
                <div class="text-end"><?= status_badge($a['status']) ?><div class="small faint"><?php if ($tab === 'done' && !empty($a['graded_at'])): ?>تصحیح: <?= jdatetime($a['graded_at']) ?><?php else: ?><?= time_ago($a['submitted_at']) ?><?php endif; ?></div></div>
            </a>
        <?php endforeach; ?>
        <?php if (!$atts): ?><div class="empty"><?= icon('circle-check') ?><div>موردی نیست</div></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
