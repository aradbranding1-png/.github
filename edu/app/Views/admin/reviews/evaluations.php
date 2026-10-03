<div class="page-head"><div><h1>ارزیابی عملی</h1><div class="sub">ثبت ارزیابی مهارتی (عملکرد واقعی، شبیه‌سازی مذاکره، ارائه، ...) — در شرایط عبور مسیر و نظام رشد استفاده می‌شود</div></div></div>
<div class="grid g-main">
    <div class="card flush"><div class="table-wrap"><table class="table">
        <thead><tr><th>فراگیر</th><th>عنوان</th><th>دوره / مرحله رشد</th><th>نمره</th><th>نتیجه</th><th>ارزیاب</th><th>تاریخ</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><a href="<?= url('/admin/reports/user/' . $r['user_id']) ?>"><?= person_name($r, 'user_id') ?></a></td><td class="fw-b"><?= e($r['title']) ?><?= $r['notes'] ? '<div class="small faint">' . e(str_limit($r['notes'], 60)) . '</div>' : '' ?></td><td class="small"><?= e($r['course_title'] ?? $r['stage_name'] ?? '—') ?></td><td class="num"><?= $r['score'] !== null ? fa((float)$r['score']) . ' / ' . fa((float)$r['max_score']) : '—' ?></td><td><?= $r['passed'] ? status_badge('passed') : status_badge('failed') ?></td><td class="small"><?= e(trim(($r['ev_first'] ?? '') . ' ' . ($r['ev_last'] ?? ''))) ?></td><td class="num small"><?= jdate($r['evaluated_at']) ?></td>
        <td class="actions"><?php if (can('evaluations.delete')): ?><form class="inline" method="post" action="<?= url('/admin/evaluations/' . $r['id'] . '/delete') ?>" data-confirm="حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost"><?= icon('trash-2') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8"><div class="empty"><?= icon('clipboard-list') ?><div>ارزیابی ثبت نشده</div></div></td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php if (can('evaluations.create')): ?>
    <form class="card" method="post" action="<?= url('/admin/evaluations') ?>"><?= csrf_field() ?>
        <h3><?= icon('plus') ?> ثبت ارزیابی جدید</h3>
        <div class="field"><label>فراگیر</label><input type="search" data-filter-select="ev-user" placeholder="جست‌وجو…" class="mb-1"><select id="ev-user" name="user_id" required size="5"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e(full_name($u)) ?> — <?= e($u['mobile']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>عنوان ارزیابی</label><input type="text" name="title" required placeholder="مثلاً: شبیه‌سازی مذاکره با مشتری خارجی"></div>
        <div class="field"><label>دوره مرتبط</label><select name="course_id"><option value="">—</option><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>مرحله رشد مرتبط</label><select name="stage_id"><option value="">—</option><?php foreach ($stages as $k => $t): ?><option value="<?= (int)$k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>معیارها</label><textarea name="criteria" rows="2"></textarea></div>
        <div class="form-grid"><div class="field"><label>نمره</label><input type="number" name="score" step="0.5"></div><div class="field"><label>از</label><input type="number" name="max_score" value="100"></div></div>
        <label class="switch mb-2"><input type="checkbox" name="passed" value="1" checked> قبول</label>
        <div class="field"><label>یادداشت ارزیاب</label><textarea name="notes" rows="3"></textarea></div>
        <button class="btn btn-grad w-100"><?= icon('save') ?> ثبت ارزیابی</button>
    </form>
    <?php endif; ?>
</div>
