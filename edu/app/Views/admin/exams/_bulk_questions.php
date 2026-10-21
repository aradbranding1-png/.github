<?php
// Edit every question (text, options, correct answers) of the exam in one form. Expects: $x, $questions, $opts, $qScores
$draft = old('q', []);
?>
<form class="card flush" id="questions" method="post" action="<?= url('/admin/exams/' . $x['id'] . '/questions/bulk') ?>"><?= csrf_field() ?>
    <div class="card-head flex-wrap" style="padding:1rem 1.25rem;gap:.5rem">
        <h3><?= icon('file-pen-line') ?> ویرایش همه سؤالات <span class="badge badge-gray"><?= fa(count($questions)) ?> سؤال</span></h3>
        <div class="flex" style="gap:.5rem">
            <a class="btn btn-sm btn-outline" href="<?= url('/admin/exams/' . $x['id']) ?>#questions">انصراف</a>
            <button class="btn btn-sm btn-grad"><?= icon('save') ?> ذخیره همه تغییرات</button>
        </div>
    </div>
    <div class="small faint" style="padding:0 1.25rem .8rem"><?= icon('info') ?> متن سؤال و گزینه‌ها را همین‌جا تغییر دهید و گزینه صحیح را علامت بزنید. برای حذف گزینه، ✕ را بزنید. فقط سؤالاتی که تغییر کرده‌اند ذخیره می‌شوند. سؤالی که در آزمون‌های دیگر هم هست، همه جا تغییر می‌کند. نوع سؤال و نمره از بخش «ویرایش» هر سؤال تغییر می‌کند.</div>
    <div class="crows">
    <?php foreach ($questions as $i => $q): $qid = (int)$q['id'];
        $type = $q['type'];
        $d = $draft[$qid] ?? null;
        $text = $d ? (string)($d['text'] ?? '') : (string)$q['text'];
        $rows = [];
        if ($d) {
            foreach ((array)($d['opt'] ?? []) as $k => $t) $rows[] = ['k' => (string)$k, 'text' => (string)$t, 'ok' => in_array((string)$k, array_map('strval', (array)($d['correct'] ?? [])), true)];
        } else {
            foreach ($opts[$qid] ?? [] as $o) $rows[] = ['k' => 'o' . (int)$o['id'], 'text' => (string)$o['text'], 'ok' => !empty($o['is_correct'])];
        }
        $inputType = $type === 'multiple' ? 'checkbox' : 'radio';
        $hasCorrect = !in_array($type, ['short', 'essay'], true);
        $curDiff = $d ? (int)($d['difficulty'] ?? 1) : (int)$q['difficulty'];
        $curCat = $d ? (string)($d['category_id'] ?? '') : (string)($q['category_id'] ?? '');
        $curLvl = $d ? (string)($d['level_id'] ?? '') : (!empty($q['level_id']) ? (string)$q['level_id'] : (!empty($q['group_id']) ? 'g' . $q['group_id'] : ''));
        $rm = '<button class="btn btn-ghost btn-icon" data-remove-row type="button" title="حذف گزینه">✕</button>';
    ?>
        <div class="crow qrow qrow-edit qcard">
            <span class="crow-no"><?= fa($i + 1) ?></span>
            <span class="crow-main">
                <span class="qcard-head"><span class="crow-tag"><?= icon('circle-help') ?> <?= e(label('qtype', $type)) ?></span><?php if ($type === 'multiple'): ?><span class="small faint">چند پاسخ صحیح مجاز است</span><?php elseif ($type === 'short'): ?><span class="small faint">پاسخ‌های قابل قبول</span><?php endif; ?><span class="small faint" style="margin-inline-start:auto">سؤال <?= fa($i + 1) ?> از <?= fa(count($questions)) ?></span></span>
                <textarea class="qtext-edit" name="q[<?= $qid ?>][text]" rows="2" aria-label="متن سؤال"><?= e($text) ?></textarea>
                <span class="qmeta">
                    <label class="qmeta-f"><span>دشواری</span><select name="q[<?= $qid ?>][difficulty]"><?php foreach (App\Core\Labels::DIFFICULTY as $dk => $dt): ?><option value="<?= $dk ?>"<?= selected($dk, $curDiff) ?>><?= e($dt) ?></option><?php endforeach; ?></select></label>
                    <label class="qmeta-f"><span>دسته</span><select name="q[<?= $qid ?>][category_id]"><option value="">—</option><?php foreach ($qcatsAll as $ck => $ct): ?><option value="<?= (int)$ck ?>"<?= selected($ck, $curCat) ?>><?= e($ct) ?></option><?php endforeach; ?></select></label>
                    <label class="qmeta-f"><span>سطح</span><select name="q[<?= $qid ?>][level_id]"><option value="">—</option><?php foreach ($levelGroups as $gid => $g): ?><optgroup label="<?= e($g['name']) ?>"><option value="g<?= (int)$gid ?>"<?= selected('g' . $gid, $curLvl) ?>>همه سطوح <?= e($g['name']) ?></option><?php foreach ($g['levels'] as $lid => $ln): ?><option value="<?= (int)$lid ?>"<?= selected((string)$lid, $curLvl) ?>><?= e($g['name'] . ' — ' . $ln) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
                </span>
                <?php if ($type !== 'essay'): ?>
                    <span class="qopts" id="opts-<?= $qid ?>">
                    <?php foreach ($rows as $r): ?>
                        <span class="qopt" data-row-item>
                            <?php if ($hasCorrect): ?><input type="<?= $inputType ?>" name="q[<?= $qid ?>][correct][]" value="<?= e($r['k']) ?>"<?= checked($r['ok']) ?> title="گزینه صحیح"><?php endif; ?>
                            <input type="text" name="q[<?= $qid ?>][opt][<?= e($r['k']) ?>]" value="<?= e($r['text']) ?>" placeholder="متن گزینه">
                            <?php if ($hasCorrect): ?><span class="badge badge-success qopt-ok-badge">پاسخ صحیح</span><?php endif; ?>
                            <?= $rm ?>
                        </span>
                    <?php endforeach; ?>
                    </span>
                    <?php if ($type !== 'truefalse'): ?>
                    <button class="btn btn-sm btn-outline qopt-add" type="button" data-add-row="opt-tpl-<?= $qid ?>" data-into="#opts-<?= $qid ?>"><?= icon('plus') ?> افزودن <?= $type === 'short' ? 'پاسخ' : 'گزینه' ?></button>
                    <template id="opt-tpl-<?= $qid ?>"><span class="qopt" data-row-item><?php if ($hasCorrect): ?><input type="<?= $inputType ?>" name="q[<?= $qid ?>][correct][]" value="n__i__" title="گزینه صحیح"><?php endif; ?><input type="text" name="q[<?= $qid ?>][opt][n__i__]" placeholder="متن گزینه"><?php if ($hasCorrect): ?><span class="badge badge-success qopt-ok-badge">پاسخ صحیح</span><?php endif; ?><?= $rm ?></span></template>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="small faint">سؤال تشریحی گزینه ندارد.</span>
                <?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
    </div>
    <div class="flex" style="gap:.5rem;padding:0 1.25rem 1.25rem">
        <button class="btn btn-grad"><?= icon('save') ?> ذخیره همه تغییرات</button>
        <a class="btn btn-outline" href="<?= url('/admin/exams/' . $x['id']) ?>#questions">انصراف</a>
    </div>
</form>
