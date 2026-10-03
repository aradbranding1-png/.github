<?php
$last = $subs[0] ?? null;
$canSubmit = $en && (!$last || in_array($last['status'], ['needs_revision', 'rejected'], true));
$formats = explode(',', (string)$x['formats']);
?>
<div class="crumbs"><a href="<?= url('/learn/exercises') ?>">تمرین‌های من</a> / <a href="<?= url('/learn/course/' . $x['course_id']) ?>"><?= e($x['course_title']) ?></a></div>
<div class="page-head"><div><h1><?= e($x['title']) ?></h1><div class="sub">حداکثر نمره <?= fa((float)$x['max_score']) ?> · حد قبولی <?= fa((float)$x['pass_score']) ?></div></div><?= $last ? status_badge($last['status']) : '' ?></div>
<div class="grid g-main">
    <div class="stack">
        <div class="card"><h3><?= icon('info') ?> شرح تمرین</h3><div class="prose"><?= clean_html($x['instructions']) ?: '<span class="faint">—</span>' ?></div></div>
        <?php if ($canSubmit): ?>
        <form class="card" method="post" enctype="multipart/form-data" action="<?= url('/learn/exercise/' . $x['id']) ?>">
            <?= csrf_field() ?>
            <h3><?= icon('send') ?> ارسال پاسخ</h3>
            <?php if (in_array('text', $formats, true)): ?><div class="field"><label>پاسخ متنی</label><textarea name="text_answer" rows="7"></textarea></div><?php endif; ?>
            <?php if (array_intersect(['file', 'image', 'video'], $formats)): ?>
                <div class="field"><label>فایل پاسخ (<?= e(implode('، ', array_map(fn($f) => ['file' => 'سند/PDF/صوت', 'image' => 'تصویر', 'video' => 'ویدیو'][$f] ?? '', array_intersect(['file', 'image', 'video'], $formats)))) ?>)</label><input type="file" name="file"></div>
            <?php endif; ?>
            <button class="btn btn-primary"><?= icon('send') ?> ارسال برای بررسی</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3><?= icon('history') ?> ارسال‌های من</h3>
        <?php if (!$subs): ?><div class="faint small">هنوز پاسخی ارسال نکرده‌اید.</div><?php endif; ?>
        <div class="timeline">
        <?php foreach ($subs as $s): ?>
            <div class="tl-item <?= $s['status'] === 'accepted' ? 'success' : ($s['status'] === 'submitted' ? 'info' : 'warning') ?>">
                <div class="t"><?= status_badge($s['status']) ?> <?= $s['score'] !== null ? 'نمره: ' . fa((float)$s['score']) : '' ?></div>
                <div class="d"><?= jdatetime($s['created_at']) ?></div>
                <?php if ($s['text_answer']): ?><div class="small mt-1"><?= nl2br(e(str_limit($s['text_answer'], 300))) ?></div><?php endif; ?>
                <?php if ($s['file_uuid']): ?><a class="small" href="<?= url('/file/' . $s['file_uuid']) ?>" target="_blank"><?= icon('file') ?> <?= e($s['original_name']) ?></a><?php endif; ?>
                <?php if ($s['feedback']): ?><div class="alert alert-info mt-1 small"><?= icon('message-square') ?><div><b>بازخورد:</b> <?= nl2br(e($s['feedback'])) ?></div></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
