<div class="grid g-main">
    <form id="exam-form" method="post" action="<?= url('/learn/attempt/' . $a['id']) ?>" class="stack" data-confirm="پاسخ‌ها ارسال شود؟ پس از ارسال امکان تغییر وجود ندارد.">
        <?= csrf_field() ?>
        <div class="card"><h2 class="mb-0"><?= e($x['title']) ?></h2><div class="small faint">دفعه <?= fa($a['attempt_no']) ?> · <?= fa(count($questions)) ?> سؤال</div></div>
        <?php foreach ($questions as $i => $q): ?>
            <div class="card q-card" id="q<?= (int)$q['id'] ?>" data-q="<?= (int)$q['id'] ?>">
                <div class="flex between mb-1"><div class="fw-b"><span class="q-num"><?= fa($i + 1) ?></span><?= e(label('qtype', $q['type'])) ?></div><span class="badge badge-gray"><?= fa((float)$q['score']) ?> نمره</span></div>
                <div class="prose mb-2"><?= nl2br(e($q['text'])) ?></div>
                <?php if (in_array($q['type'], ['single', 'truefalse'], true)): ?>
                    <?php foreach ($q['options'] as $o): ?><label class="opt"><input type="radio" name="q[<?= (int)$q['id'] ?>]" value="<?= (int)$o['id'] ?>"> <?= e($o['text']) ?></label><?php endforeach; ?>
                <?php elseif ($q['type'] === 'multiple'): ?>
                    <div class="small faint mb-1">همه گزینه‌های صحیح را انتخاب کنید.</div>
                    <?php foreach ($q['options'] as $o): ?><label class="opt"><input type="checkbox" name="q[<?= (int)$q['id'] ?>][]" value="<?= (int)$o['id'] ?>"> <?= e($o['text']) ?></label><?php endforeach; ?>
                <?php elseif ($q['type'] === 'short'): ?>
                    <input type="text" name="q[<?= (int)$q['id'] ?>]" placeholder="پاسخ کوتاه" autocomplete="off">
                <?php else: ?>
                    <textarea name="q[<?= (int)$q['id'] ?>]" rows="6" placeholder="پاسخ تشریحی خود را بنویسید"></textarea>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-grad btn-lg" type="submit"><?= icon('send') ?> پایان و ارسال پاسخ‌ها</button>
    </form>
    <aside class="stack" style="position:sticky;top:80px;align-self:start">
        <?php if ($a['deadline_at']): ?><div class="timer" data-deadline="<?= strtotime($a['deadline_at']) ?>" data-now="<?= time() ?>"><?= icon('clock') ?> <span data-timer-text>--:--</span></div><?php endif; ?>
        <div class="card"><h3>پاسخ‌نامه</h3><div class="q-nav"><?php foreach ($questions as $i => $q): ?><a href="#q<?= (int)$q['id'] ?>"><?= fa($i + 1) ?></a><?php endforeach; ?></div><div class="small faint mt-1">سؤالات پاسخ‌داده‌شده رنگی می‌شوند.</div></div>
    </aside>
</div>
