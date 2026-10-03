<?php $kIcons = ['video' => ['video', 'danger'], 'audio' => ['music', 'purple'], 'pdf' => ['file-text', 'warning'], 'image' => ['image', 'success'], 'doc' => ['file', 'info']]; ?>
<div class="page-head"><div><h1>کتابخانه محتوا</h1><div class="sub">مدیریت مرکزی ویدیو، صوت، PDF، تصویر و اسناد — با تعیین «مشاهده آنلاین» و «مجوز دانلود» برای هر فایل</div></div>
<?php if (can('library.create')): ?><button class="btn btn-grad" data-open="dlg-up"><?= icon('upload') ?> آپلود فایل</button><?php endif; ?></div>
<div class="grid g-5 mb-3">
    <?php foreach ($stats as $s): [$ic, $tone] = $kIcons[$s['kind']] ?? ['file', 'gray']; ?>
        <a class="card stat tone-<?= $tone ?>" href="<?= url('/admin/library', ['kind' => $s['kind']]) ?>"><div class="bubble"><?= icon($ic) ?></div><div><div class="v"><?= nf($s['n']) ?></div><div class="l"><?= e(App\Core\Upload::kinds()[$s['kind']] ?? $s['kind']) ?> · <?= human_size((int)$s['s']) ?></div></div></a>
    <?php endforeach; ?>
</div>
<form class="card filters" method="get" action="<?= url('/admin/library') ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>"></div>
    <div class="field"><label>نوع</label><select name="kind"><option value="">همه</option><?php foreach (App\Core\Upload::kinds() as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $_GET['kind'] ?? '') ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>پوشه</label><select name="folder"><option value="">همه</option><?php foreach ($folders as $f): ?><option value="<?= e($f) ?>"<?= selected($f, $_GET['folder'] ?? '') ?>><?= e($f) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<div class="card flush">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>فایل</th><th>نوع</th><th>حجم</th><th>استفاده</th><th>بازدید / دانلود</th><th>مشاهده آنلاین</th><th>دانلود</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $f): [$ic, $tone] = $kIcons[$f['kind']] ?? ['file', 'gray']; ?>
            <tr>
                <td><div class="flex"><span class="ico" style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:var(--<?= $tone ?>-soft);color:var(--<?= $tone ?>)"><?= icon($ic) ?></span><div><b><?= e($f['title'] ?: $f['original_name']) ?></b><div class="small faint"><?= e($f['original_name']) ?> · <?= jdate($f['created_at']) ?><?= $f['folder'] ? ' · ' . e($f['folder']) : '' ?></div></div></div></td>
                <td><span class="badge badge-gray"><?= e(strtoupper($f['ext'])) ?></span></td>
                <td class="num"><?= human_size((int)$f['size']) ?></td>
                <td class="num"><?= $f['used'] ? fa($f['used']) . ' درس' : '<span class="faint">—</span>' ?></td>
                <td class="num"><?= nf($f['views']) ?> / <?= nf($f['downloads']) ?></td>
                <?php if (can('library.edit')): ?>
                    <td colspan="3">
                        <form class="flex" method="post" action="<?= url('/admin/library/' . $f['id']) ?>"><?= csrf_field() ?>
                            <input type="hidden" name="title" value="<?= e($f['title']) ?>"><input type="hidden" name="folder" value="<?= e($f['folder']) ?>">
                            <label class="switch"><input type="checkbox" name="viewable" value="1"<?= checked($f['viewable']) ?> data-autosubmit></label>
                            <label class="switch" style="margin-right:2.2rem"><input type="checkbox" name="downloadable" value="1"<?= checked($f['downloadable']) ?> data-autosubmit></label>
                            <a class="btn btn-xs btn-ghost" href="<?= url('/file/' . $f['uuid']) ?>" target="_blank"><?= icon('eye') ?></a>
                        </form>
                    </td>
                <?php else: ?>
                    <td><?= $f['viewable'] ? '✓' : '—' ?></td><td><?= $f['downloadable'] ? '✓' : '—' ?></td><td><a class="btn btn-xs btn-ghost" href="<?= url('/file/' . $f['uuid']) ?>" target="_blank"><?= icon('eye') ?></a></td>
                <?php endif; ?>
                <td class="actions"><?php if (can('library.delete')): ?><form class="inline" method="post" action="<?= url('/admin/library/' . $f['id'] . '/delete') ?>" data-confirm="<?= $f['used'] ? 'این فایل در ' . fa($f['used']) . ' درس استفاده شده است. ' : '' ?>حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$page['rows']): ?><tr><td colspan="9"><div class="empty"><?= icon('library') ?><div>فایلی یافت نشد</div></div></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?= paginate_links($page) ?>
<?php if (can('library.create')): ?>
<dialog class="modal" id="dlg-up">
    <div class="modal-head"><b>آپلود به کتابخانه</b><button class="btn btn-ghost btn-icon" data-close><?= icon('x') ?></button></div>
    <form class="modal-body" method="post" enctype="multipart/form-data" action="<?= url('/admin/library') ?>"><?= csrf_field() ?>
        <div class="field"><label>فایل‌ها (چندتایی)</label><input type="file" name="files[]" multiple required><div class="hint">MP4, MP3, PDF, JPG, PNG, DOC(X), PPT(X), XLS(X) — حداکثر <?= fa(setting('max_upload_mb')) ?> مگابایت برای هر فایل (محدودیت upload_max_filesize سرور نیز اعمال می‌شود)</div></div>
        <div class="field"><label>پوشه (اختیاری)</label><input type="text" name="folder" list="folders"><datalist id="folders"><?php foreach ($folders as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist></div>
        <div class="flex gap-2 mb-2"><label class="switch"><input type="checkbox" name="viewable" value="1" checked> مشاهده آنلاین</label><label class="switch"><input type="checkbox" name="downloadable" value="1"> اجازه دانلود</label></div>
        <button class="btn btn-primary"><?= icon('upload') ?> آپلود</button>
    </form>
</dialog>
<?php endif; ?>
