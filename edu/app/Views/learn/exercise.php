<?php
$last = $subs[0] ?? null;
$gate = $gate ?? null;
$canSubmit = $en && !$gate && (!$last || in_array($last['status'], ['needs_revision', 'rejected'], true));
$formats = explode(',', (string)$x['formats']);
?>
<div class="crumbs"><a href="<?= url('/learn/exercises') ?>">تمرین‌های من</a> / <a href="<?= url('/learn/course/' . $x['course_id']) ?>"><?= e($x['course_title']) ?></a></div>
<?php $canExEdit = can_edit_exercise($x); if ($canExEdit || can('lessons.view')): ?>
<div class="staff-bar">
    <span class="sb-label"><?= icon('shield-check') ?> ابزار مدیریت تمرین</span>
    <div class="flex flex-wrap">
        <?php if ($canExEdit): ?><a class="btn btn-primary btn-sm" href="<?= exercise_edit_url($x) ?>"><?= icon('file-pen-line') ?> ویرایش تمرین</a><?php endif; ?>
        <?php if (can('lessons.view') || can('courses.view')): ?><a class="btn btn-outline btn-sm" href="<?= url('/admin/courses/' . $x['course_id'], ['tab' => 'exercises']) ?>"><?= icon('notebook-pen') ?> همه تمرین‌های دوره</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="page-head"><div><h1><?= e($x['title']) ?></h1><div class="sub">حداکثر نمره <?= fa((float)$x['max_score']) ?> · حد قبولی <?= fa((float)$x['pass_score']) ?></div></div><?= $last ? status_badge($last['status']) : '' ?></div>
<div class="grid g-main">
    <div class="stack">
        <div class="card"><h3><?= icon('info') ?> شرح تمرین</h3><div class="prose"><?= clean_html($x['instructions']) ?: '<span class="faint">—</span>' ?></div></div>
        <?php if ($gate): ?>
        <div class="card"><div class="alert alert-warning task-gate"><?= icon('lock') ?><div><b>ارسال این تمرین هنوز برای شما فعال نشده است.</b><div class="mt-1"><?= e($gate['msg']) ?></div></div></div>
            <a class="btn btn-primary" href="<?= url('/learn/lesson/' . $gate['lesson']['id']) ?>"><?= icon('circle-play') ?> رفتن به درس «<?= e($gate['lesson']['title']) ?>»</a></div>
        <?php endif; ?>
        <?php if ($canSubmit): ?>
        <form class="card" method="post" enctype="multipart/form-data" action="<?= url('/learn/exercise/' . $x['id']) ?>">
            <?= csrf_field() ?>
            <h3><?= icon('send') ?> ارسال پاسخ</h3>
            <?php if (in_array('text', $formats, true)): ?><div class="alert alert-warning no-ai"><?= icon('sparkles') ?><div><b>تمرین را خودتان انجام دهید.</b> در نوشتن این پاسخ از هوش مصنوعی (مانند ChatGPT و ابزارهای مشابه) استفاده نکنید؛ هدف این تمرین سنجش فهم و نوشتهٔ شخص شماست.</div></div>
            <div class="field"><label>پاسخ متنی</label><textarea name="text_answer" rows="7" data-wordcount placeholder="پاسخ خود را با کلمات خودتان بنویسید…"></textarea></div><?php endif; ?>
            <?php $upKinds = array_intersect_key(['audio' => ['صوت', 'audio/*,.mp3,.m4a,.wav,.ogg'], 'file' => ['Word / PDF', '.pdf,.doc,.docx'], 'image' => ['تصویر', 'image/*'], 'video' => ['ویدیو', 'video/*']], array_flip($formats)); ?>
            <?php if ($upKinds): ?>
                <div class="field"><label>فایل پاسخ (<?= e(implode('، ', array_column($upKinds, 0))) ?>)</label><input type="file" name="file" accept="<?= e(implode(',', array_column($upKinds, 1))) ?>"></div>
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
                <?php if ($s['text_answer']): ?><div class="mt-1"><?= wc_badge($s['text_answer']) ?></div><div class="small mt-1"><?= nl2br(e(str_limit($s['text_answer'], 300))) ?></div><?php endif; ?>
                <?php if ($s['file_uuid']): ?><a class="small" href="<?= url('/file/' . $s['file_uuid']) ?>" target="_blank"><?= icon('file') ?> <?= e($s['original_name']) ?></a><?php endif; ?>
                <?php if ($s['feedback']): ?><div class="alert alert-info mt-1 small"><?= icon('message-square') ?><div><b>بازخورد:</b> <?= nl2br(e($s['feedback'])) ?></div></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
