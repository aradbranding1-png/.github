<?php $audLabels = ['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', 'all' => 'همه فراگیران']; ?>
<div class="card hero mb-3" style="background:linear-gradient(135deg, <?= e($p['color']) ?>, #0f172a)">
    <div class="flex between flex-wrap gap-2">
        <div class="grow"><div class="crumbs" style="color:rgba(255,255,255,.7)"><a style="color:inherit" href="<?= url('/learn/paths') ?>">مسیرهای آموزشی</a></div><h1><?= e($p['title']) ?></h1>
            <?php if (trim((string)$p['description']) !== ''): ?><p class="muted mb-0"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
            <div class="flex flex-wrap mt-1" style="gap:.35rem"><span class="badge" style="background:rgba(255,255,255,.15);color:#fff"><?= icon('route') ?> <?= fa(count($steps)) ?> مرحله</span>
                <?php if ($staff): ?><span class="badge" style="background:rgba(255,255,255,.15);color:#fff"><?= icon('users') ?> <?= e($audLabels[$audience] ?? $audience) ?><?= $p['group_name'] ? ' · ' . e($p['group_name']) : '' ?></span><?php endif; ?></div></div>
        <div class="text-center">
            <?php if ($pe): ?><?= progress_ring((float)$pe['progress_pct'], 110, 'white') ?><div class="small mt-1"><?= $pe['status'] === 'completed' ? 'مسیر کامل شد 🎉' : 'مرحله ' . fa($pe['current_step']) . ' از ' . fa(count($steps)) ?></div>
            <?php elseif ($canStart): ?><form method="post" action="<?= url('/learn/path/' . $p['id'] . '/enroll') ?>"><?= csrf_field() ?><button class="btn btn-white btn-lg"><?= icon('rocket') ?> شروع این مسیر</button></form>
            <?php elseif ($staff): ?><span class="badge" style="background:rgba(255,255,255,.15);color:#fff"><?= icon('eye') ?> نمای مدیر آموزش</span><?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$steps): ?>
    <div class="card empty"><?= icon('route') ?><div>هنوز مرحله‌ای برای این مسیر تعریف نشده است.</div></div>
<?php else: ?>
<div class="roadmap" style="--c:<?= e($p['color'] ?: '#6366f1') ?>">
    <div class="rm-item rm-flag">
        <div class="rm-node"><?= icon('flag') ?></div>
        <div class="rm-flag-txt"><b>شروع مسیر</b><span><?= $pe ? 'از اینجا شروع کردید' : 'با شروع مسیر، مرحله اول باز می‌شود' ?></span></div>
    </div>
    <?php foreach ($steps as $i => $s):
        $state = !$pe ? 'locked' : ($s['passed'] ? 'done' : ((int)$pe['current_step'] === $i + 1 ? 'current' : 'locked'));
        $open = $state !== 'locked' || $staff; ?>
    <div class="rm-item is-<?= $state ?><?= $i % 2 ? ' rm-alt' : '' ?>">
        <div class="rm-node"><?= $state === 'done' ? icon('check') : ($state === 'locked' ? icon('lock') : fa($i + 1)) ?></div>
        <<?= $open ? 'a href="' . url('/learn/course/' . $s['course_id']) . '"' : 'div' ?> class="rm-card">
            <div class="rm-top"><span>مرحله <?= fa($i + 1) ?></span>
                <?= $state === 'done' ? '<span class="badge badge-success">گذرانده شد</span>' : ($state === 'current' ? '<span class="badge badge-primary">مرحله فعلی</span>' : '<span class="badge badge-gray">' . icon('lock') . ' قفل</span>') ?></div>
            <h3><?= e($s['title'] ?: $s['course_title']) ?></h3>
            <?php if ($s['title'] && $s['title'] !== $s['course_title']): ?><div class="small muted">دوره: <?= e($s['course_title']) ?></div><?php endif; ?>
            <div class="rm-meta">
                <span class="chip">پیشرفت ≥ <?= fa((int)$s['min_progress']) ?>٪</span>
                <?php if ($s['min_score'] !== null): ?><span class="chip">نمره ≥ <?= fa((float)$s['min_score']) ?></span><?php endif; ?>
                <?php if ($s['require_exercises']): ?><span class="chip">تأیید تمرین‌ها</span><?php endif; ?>
                <?php if ($s['require_evaluation']): ?><span class="chip">ارزیابی عملی</span><?php endif; ?>
                <?php if (!empty($s['duration_minutes'])): ?><span class="chip"><?= icon('clock') ?> <?= fa(round((int)$s['duration_minutes'] / 60, 1)) ?> ساعت</span><?php endif; ?>
            </div>
            <?php if ($s['en']): ?>
            <div class="rm-prog"><div class="grow"><?= progress_bar((float)$s['en']['progress_pct']) ?></div><b class="small"><?= fa((int)$s['en']['progress_pct']) ?>٪</b></div>
            <?php endif; ?>
            <?php if ($open): ?><span class="rm-cta"><?= $state === 'done' ? 'مرور دوره' : ($state === 'current' ? 'ادامه یادگیری' : 'مشاهده دوره') ?> <?= icon('chevron-left') ?></span><?php endif; ?>
        </<?= $open ? 'a' : 'div' ?>>
    </div>
    <?php endforeach; ?>
    <div class="rm-item rm-flag rm-finish<?= $pe && $pe['status'] === 'completed' ? ' is-done' : '' ?>">
        <div class="rm-node"><?= icon('trophy') ?></div>
        <div class="rm-flag-txt"><b><?= $pe && $pe['status'] === 'completed' ? 'مسیر را کامل کردید 🎉' : 'پایان مسیر' ?></b><span>با گذراندن همه مراحل، این مسیر کامل می‌شود</span></div>
    </div>
</div>
<?php endif; ?>
