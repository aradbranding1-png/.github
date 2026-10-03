<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/reviews') ?>">بررسی و تصحیح</a></div><h1><?= e($a['title']) ?></h1><div class="sub"><?= user_name_html($u) ?> · دفعه <?= fa($a['attempt_no']) ?> · <?= jdatetime($a['submitted_at']) ?></div></div>
<div><?= status_badge($a['status']) ?> <?php if ($a['percent'] !== null): ?><span class="badge badge-primary"><?= fa((float)$a['percent']) ?>٪</span><?php endif; ?></div></div>
<form method="post" action="<?= url('/admin/reviews/attempt/' . $a['id']) ?>"><?= csrf_field() ?>
<div class="stack">
<?php foreach ($answers as $i => $an): $sel = $an['type'] === 'multiple' ? (json_decode((string)$an['answer'], true) ?: []) : [(int)$an['answer']]; ?>
    <div class="card q-card" style="border-right-color:var(--<?= $an['type'] === 'essay' ? 'warning' : ((int)$an['is_correct'] ? 'success' : 'danger') ?>)">
        <div class="flex between"><b><span class="q-num"><?= fa($i + 1) ?></span><?= e(label('qtype', $an['type'])) ?></b><span class="badge badge-gray">حداکثر <?= fa((float)$an['max_score']) ?></span></div>
        <p class="mt-1"><?= nl2br(e($an['text'])) ?></p>
        <?php if ($an['type'] === 'essay'): ?>
            <div class="card" style="background:var(--surface-2)"><?= nl2br(e($an['answer'] ?: '— بدون پاسخ —')) ?></div>
            <?php if (can('reviews.approve')): ?>
            <div class="form-grid mt-1">
                <div class="field"><label>نمره</label><input type="number" step="0.25" min="0" max="<?= e($an['max_score']) ?>" name="g[<?= (int)$an['id'] ?>][score]" value="<?= e($an['score']) ?>" required></div>
                <div class="field"><label>بازخورد</label><input type="text" name="g[<?= (int)$an['id'] ?>][feedback]" value="<?= e($an['feedback']) ?>"></div>
            </div>
            <?php endif; ?>
        <?php elseif ($an['type'] === 'short'): ?>
            <div class="small"><b>پاسخ:</b> <?= e($an['answer'] ?: '—') ?> · <b>قابل قبول:</b> <?= e(implode('، ', array_column($an['options'], 'text'))) ?> — <?= fa((float)$an['score']) ?> نمره</div>
        <?php else: ?>
            <?php foreach ($an['options'] as $o): $picked = in_array((int)$o['id'], $sel, true); ?><div class="opt<?= $o['is_correct'] ? ' correct' : ($picked ? ' wrong' : '') ?>"><?= e($o['text']) ?><?= $picked ? ' <span class="badge badge-gray">انتخاب</span>' : '' ?></div><?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if ($a['status'] === 'pending_review' && can('reviews.approve')): ?><button class="btn btn-grad btn-lg"><?= icon('circle-check') ?> ثبت تصحیح و اعلام نتیجه</button><?php endif; ?>
</div>
</form>
