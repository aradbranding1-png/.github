<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/reviews') ?>">بررسی و تصحیح</a></div><h1><?= e($s['title']) ?></h1><div class="sub"><?= e($s['course_title']) ?> · <?= user_name_html($u) ?> · <?= jdatetime($s['created_at']) ?></div></div><?= status_badge($s['status']) ?></div>
<div class="grid g-main">
    <div class="stack">
        <div class="card"><h3><?= icon('info') ?> صورت تمرین</h3><div class="prose small"><?= clean_html($s['instructions']) ?: '—' ?></div></div>
        <div class="card"><h3><?= icon('send') ?> پاسخ فراگیر</h3>
            <?php if ($s['text_answer']): ?><div class="prose"><?= nl2br(e($s['text_answer'])) ?></div><?php endif; ?>
            <?php if ($s['file_uuid']): ?>
                <?php if ($s['file_kind'] === 'image'): ?><img src="<?= url('/file/' . $s['file_uuid']) ?>" alt="" style="border-radius:12px;max-height:480px"><?php elseif ($s['file_kind'] === 'video'): ?><div class="media-box"><video controls src="<?= url('/file/' . $s['file_uuid']) ?>"></video></div><?php endif; ?>
                <div class="file-tile mt-1"><span class="fi"><?= icon('file') ?></span><div class="grow"><?= e($s['original_name']) ?></div><a class="btn btn-sm btn-outline" href="<?= url('/file/' . $s['file_uuid'], ['dl' => 1]) ?>"><?= icon('download') ?></a><a class="btn btn-sm btn-ghost" target="_blank" href="<?= url('/file/' . $s['file_uuid']) ?>"><?= icon('eye') ?></a></div>
            <?php endif; ?>
        </div>
        <?php if ($history): ?><div class="card"><h3><?= icon('history') ?> ارسال‌های قبلی</h3><?php foreach ($history as $h): ?><div class="list-item small"><?= status_badge($h['status']) ?><div class="grow"><?= e(str_limit($h['text_answer'], 120)) ?></div><span class="faint"><?= jdate($h['created_at']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php if (can('reviews.approve')): ?>
    <form class="card" method="post" action="<?= url('/admin/reviews/submission/' . $s['id']) ?>"><?= csrf_field() ?>
        <h3><?= icon('clipboard-check') ?> ثبت نتیجه</h3>
        <div class="field"><label>نتیجه</label><div class="chip-select">
            <?php foreach (['accepted' => 'پذیرفته', 'needs_revision' => 'نیازمند اصلاح', 'rejected' => 'رد'] as $k => $t): ?><label><input type="radio" name="status" value="<?= $k ?>"<?= checked($s['status'] === $k || ($s['status'] === 'submitted' && $k === 'accepted')) ?>><span><?= e($t) ?></span></label><?php endforeach; ?>
        </div></div>
        <div class="field"><label>نمره (از <?= fa((float)$s['max_score']) ?> — قبولی <?= fa((float)$s['pass_score']) ?>)</label><input type="number" step="0.5" name="score" value="<?= e($s['score']) ?>" max="<?= e($s['max_score']) ?>"></div>
        <div class="field"><label>بازخورد برای فراگیر</label><textarea name="feedback" rows="5"><?= e($s['feedback']) ?></textarea></div>
        <button class="btn btn-grad w-100"><?= icon('send') ?> ثبت و اطلاع به فراگیر</button>
    </form>
    <?php endif; ?>
</div>
