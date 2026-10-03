<?php $pending = $a['status'] === 'pending_review'; $passed = (int)$a['passed'] === 1; ?>
<div class="crumbs"><a href="<?= url('/learn/exams') ?>">آزمون‌های من</a> / <a href="<?= url('/learn/exam/' . $x['id']) ?>"><?= e($x['title']) ?></a></div>
<div class="card score-hero mb-3">
    <?php if ($pending): ?>
        <div class="ring ring-warning" style="width:130px;height:130px;margin:0 auto"><svg viewBox="0 0 36 36"><circle class="ring-bg" cx="18" cy="18" r="15.9155"/><circle class="ring-fg" cx="18" cy="18" r="15.9155" stroke-dasharray="50 100"/></svg><span><?= icon('clock') ?></span></div>
        <h2 class="mt-2">پاسخ‌های تشریحی شما در انتظار تصحیح است</h2>
        <p class="muted">نمره بخش خودکار: <?= fa((float)$a['score']) ?> — نتیجه نهایی پس از تصحیح اعلام می‌شود.</p>
    <?php else: ?>
        <?= progress_ring((float)$a['percent'], 150, $passed ? 'success' : 'danger') ?>
        <h2 class="mt-2" style="color:var(--<?= $passed ? 'success' : 'danger' ?>)"><?= $passed ? 'تبریک! قبول شدید' : 'متأسفانه قبول نشدید' ?></h2>
        <p class="muted">نمره: <?= fa((float)$a['score']) ?> از <?= fa((float)$a['max_score']) ?> · حد قبولی <?= fa((float)$x['pass_score']) ?>٪</p>
        <?php if (!$passed && $remaining > 0): ?><a class="btn btn-primary" href="<?= url('/learn/exam/' . $x['id']) ?>"><?= icon('refresh-cw') ?> تلاش مجدد<?= $remaining < 99 ? ' (' . fa($remaining) . ' فرصت دیگر امروز)' : '' ?></a><?php elseif (!$passed): ?><span class="badge badge-warning"><?= icon('clock') ?> سهمیه امروز تمام شد؛ از فردا دوباره می‌توانید شرکت کنید</span><?php endif; ?>
    <?php endif; ?>
    <?php if ($x['course_id']): ?><a class="btn btn-outline" href="<?= url('/learn/course/' . $x['course_id']) ?>">بازگشت به دوره</a><?php endif; ?>
</div>
<?php if ((int)$x['show_answers'] === 1 && !$pending): ?>
<h3>مرور پاسخ‌ها</h3>
<div class="stack">
<?php foreach ($answers as $i => $an):
    $sel = $an['type'] === 'multiple' ? (json_decode((string)$an['answer'], true) ?: []) : [(int)$an['answer']]; ?>
    <div class="card q-card" style="border-right-color:var(--<?= (int)$an['is_correct'] ? 'success' : 'danger' ?>)">
        <div class="flex between"><b><span class="q-num"><?= fa($i + 1) ?></span><?= e(label('qtype', $an['type'])) ?></b><span class="badge badge-<?= (int)$an['is_correct'] ? 'success' : 'danger' ?>"><?= fa((float)$an['score']) ?> / <?= fa((float)$an['max_score']) ?></span></div>
        <p class="mt-1"><?= nl2br(e($an['text'])) ?></p>
        <?php if (in_array($an['type'], ['single', 'multiple', 'truefalse'], true)): ?>
            <?php foreach ($an['options'] as $o): $picked = in_array((int)$o['id'], $sel, true); ?>
                <div class="opt<?= $o['is_correct'] ? ' correct' : ($picked ? ' wrong' : '') ?>"><?= icon($o['is_correct'] ? 'circle-check' : ($picked ? 'circle-x' : 'minus')) ?> <?= e($o['text']) ?><?= $picked ? ' <span class="badge badge-gray">انتخاب شما</span>' : '' ?></div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="small"><b>پاسخ شما:</b> <?= nl2br(e($an['answer'] ?: '—')) ?></div>
            <?php if ($an['type'] === 'short'): ?><div class="small muted">پاسخ‌های قابل قبول: <?= e(implode('، ', array_column($an['options'], 'text'))) ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($an['feedback']): ?><div class="alert alert-info small mt-1"><?= icon('message-square') ?> <?= nl2br(e($an['feedback'])) ?></div><?php endif; ?>
        <?php if ($an['explanation']): ?><div class="small muted mt-1"><?= icon('lightbulb') ?> <?= nl2br(e($an['explanation'])) ?></div><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
