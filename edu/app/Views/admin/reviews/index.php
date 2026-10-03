<div class="page-head"><div><h1>بررسی و تصحیح</h1><div class="sub">تمرین‌های ارسالی و پاسخ‌های تشریحی آزمون‌ها</div></div></div>
<div class="tabs"><a class="<?= $tab !== 'done' ? 'active' : '' ?>" href="<?= url('/admin/reviews') ?>"><?= icon('clock') ?> در انتظار بررسی (<?= fa(count($subs) + count($atts)) ?>)</a><a class="<?= $tab === 'done' ? 'active' : '' ?>" href="<?= url('/admin/reviews', ['tab' => 'done']) ?>"><?= icon('circle-check') ?> بررسی‌شده</a></div>
<div class="grid g-2">
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('notebook-pen') ?> تمرین‌ها</h3></div>
        <?php foreach ($subs as $s): ?>
            <a class="list-item" href="<?= url('/admin/reviews/submission/' . $s['id']) ?>" style="padding:.8rem 1.25rem;color:var(--text)">
                <?= avatar_html(['id' => $s['user_id'], 'first_name' => $s['first_name'], 'last_name' => $s['last_name'], 'avatar_path' => $s['avatar_path']], 'sm') ?>
                <div class="grow"><b class="small"><?= person_name($s, 'user_id') ?></b><div class="small faint"><?= e($s['title']) ?> · <?= e($s['course_title']) ?></div></div>
                <div class="text-end"><?= status_badge($s['status']) ?><div class="small faint"><?= time_ago($s['created_at']) ?></div></div>
            </a>
        <?php endforeach; ?>
        <?php if (!$subs): ?><div class="empty"><?= icon('circle-check') ?><div>موردی نیست</div></div><?php endif; ?>
    </div>
    <div class="card flush"><div class="card-head" style="padding:1rem 1.25rem"><h3><?= icon('clipboard-check') ?> پاسخ‌های تشریحی آزمون</h3></div>
        <?php foreach ($atts as $a): ?>
            <a class="list-item" href="<?= url('/admin/reviews/attempt/' . $a['id']) ?>" style="padding:.8rem 1.25rem;color:var(--text)">
                <?= avatar_html(['id' => $a['user_id'], 'first_name' => $a['first_name'], 'last_name' => $a['last_name'], 'avatar_path' => $a['avatar_path']], 'sm') ?>
                <div class="grow"><b class="small"><?= person_name($a, 'user_id') ?></b><div class="small faint"><?= e($a['title']) ?> · دفعه <?= fa($a['attempt_no']) ?></div></div>
                <div class="text-end"><?= status_badge($a['status']) ?><div class="small faint"><?= time_ago($a['submitted_at']) ?></div></div>
            </a>
        <?php endforeach; ?>
        <?php if (!$atts): ?><div class="empty"><?= icon('circle-check') ?><div>موردی نیست</div></div><?php endif; ?>
    </div>
</div>
