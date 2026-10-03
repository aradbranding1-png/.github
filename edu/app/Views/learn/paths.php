<?php
$segLabels = ['' => 'همه', 'merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان'];
$audLabel = fn(string $a) => $a === 'all' ? 'همه فراگیران' : ($segLabels[$a] ?? $a);
?>
<div class="page-head"><div><h1>مسیرهای آموزشی</h1>
    <div class="sub"><?= $staff ? 'نقشه راه آموزشی همه گروه‌ها — مسیرها به ترتیبی که مدیر آموزش تعیین کرده نمایش داده می‌شوند' : 'نقشه راه رشد شما — قدم‌به‌قدم از اولین مسیر تا آخرین' ?></div></div></div>

<?php if ($staff): ?>
<div class="rm-tabs mb-3">
    <div class="seg">
        <?php foreach ($segLabels as $k => $v): ?>
            <a class="<?= $seg === $k ? 'active' : '' ?>" href="<?= url('/learn/paths', $k !== '' ? ['seg' => $k] : []) ?>"><?= e($v) ?> <span class="faint">(<?= fa($counts[$k] ?? 0) ?>)</span></a>
        <?php endforeach; ?>
    </div>
    <span class="badge badge-info"><?= icon('eye') ?> نمای مدیر آموزش</span>
</div>
<?php endif; ?>

<?php if ($mineN): ?>
<div class="card rm-summary mb-3">
    <?= progress_ring($avg, 64) ?>
    <div class="grow"><b>پیشرفت شما در مسیرها</b>
        <div class="small muted"><?= fa($mineN) ?> مسیر فعال · <?= fa($doneN) ?> مسیر تکمیل‌شده</div></div>
    <?php if ($doneN && $doneN === $mineN): ?><span class="badge badge-success"><?= icon('trophy') ?> همه را تمام کردید</span><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="card empty"><?= icon('route') ?><div><?= $staff ? 'برای این گروه مسیر منتشرشده‌ای وجود ندارد.' : 'هنوز مسیر آموزشی برای شما تعریف نشده است.' ?></div></div>
<?php else: ?>
<div class="roadmap">
    <div class="rm-item rm-flag">
        <div class="rm-node"><?= icon('flag') ?></div>
        <div class="rm-flag-txt"><b>شروع</b><span><?= fa(count($rows)) ?> مسیر در نقشه راه</span></div>
    </div>
    <?php foreach ($rows as $i => $p):
        $state = $p['pe_id'] ? ($p['pe_status'] === 'completed' ? 'done' : 'current') : 'new';
        $steps = (int)$p['steps']; ?>
    <div class="rm-item is-<?= $state ?><?= $i % 2 ? ' rm-alt' : '' ?>" style="--c:<?= e($p['color'] ?: '#6366f1') ?>">
        <div class="rm-node"><?= $state === 'done' ? icon('check') : fa($i + 1) ?></div>
        <a class="rm-card" href="<?= url('/learn/path/' . $p['id']) ?>">
            <div class="rm-top"><span>مسیر <?= fa($i + 1) ?></span>
                <?= $state === 'done' ? '<span class="badge badge-success">تکمیل شد</span>' : ($state === 'current' ? '<span class="badge badge-primary">در حال یادگیری</span>' : '<span class="badge badge-gray">شروع نشده</span>') ?></div>
            <h3><?= e($p['title']) ?></h3>
            <?php if (trim((string)$p['description']) !== ''): ?><p class="small muted mb-0"><?= e(str_limit($p['description'], 120)) ?></p><?php endif; ?>
            <div class="rm-meta">
                <span class="chip"><?= icon('route') ?> <?= fa($steps) ?> مرحله</span>
                <?php if ($staff): ?>
                    <span class="chip"><?= icon('users') ?> <?= e($audLabel($p['audience'])) ?><?= $p['group_name'] && $p['group_name'] !== $audLabel($p['audience']) ? ' · ' . e($p['group_name']) : '' ?></span>
                    <span class="chip"><?= fa((int)$p['learners']) ?> فراگیر · <?= fa((int)$p['done']) ?> تکمیل</span>
                    <?php if ($p['status'] !== 'published'): ?><span class="chip"><?= e(label('status', $p['status'])) ?></span><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($p['pe_id']): ?>
            <div class="rm-prog"><div class="grow"><?= progress_bar((float)$p['progress_pct']) ?></div><b class="small"><?= fa((int)$p['progress_pct']) ?>٪</b></div>
            <div class="small muted mt-1"><?= $state === 'done' ? 'همه ' . fa($steps) . ' مرحله را گذراندید' : 'مرحله ' . fa(min(max(1, (int)$p['current_step']), max(1, $steps))) . ' از ' . fa($steps) ?></div>
            <?php endif; ?>
            <span class="rm-cta"><?= $state === 'done' ? 'مرور مسیر' : ($state === 'current' ? 'ادامه مسیر' : ($staff ? 'مشاهده مسیر' : 'مشاهده و شروع')) ?> <?= icon('chevron-left') ?></span>
        </a>
    </div>
    <?php endforeach; ?>
    <div class="rm-item rm-flag rm-finish">
        <div class="rm-node"><?= icon('trophy') ?></div>
        <div class="rm-flag-txt"><b>پایان نقشه راه</b><span>با گذراندن همه مسیرها به هدف می‌رسید</span></div>
    </div>
</div>
<?php endif; ?>
