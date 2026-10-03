<div class="page-head"><div><h1>بانک سؤال</h1><div class="sub">سؤالات قابل استفاده مجدد با دسته‌بندی، سطح دشواری، برچسب و وضعیت فعال/غیرفعال</div></div>
<?php if (can('questions.create')): ?><a class="btn btn-grad" href="<?= url('/admin/questions/create') ?>"><?= icon('plus') ?> سؤال جدید</a><?php endif; ?></div>
<div class="grid g-main">
    <div class="stack">
        <form class="card filters" method="get" action="<?= url('/admin/questions') ?>">
            <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="متن یا برچسب"></div>
            <div class="field"><label>نوع</label><select name="type"><option value="">همه</option><?php foreach (App\Core\Labels::QTYPE as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $_GET['type'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>دشواری</label><select name="difficulty"><option value="">همه</option><?php foreach (App\Core\Labels::DIFFICULTY as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $_GET['difficulty'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>دسته</label><select name="category"><option value="">همه</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($c['id'], $_GET['category'] ?? '') ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>وضعیت</label><select name="active"><option value="">همه</option><option value="1"<?= selected('1', $_GET['active'] ?? '') ?>>فعال</option><option value="0"<?= selected('0', $_GET['active'] ?? '') ?>>غیرفعال</option></select></div>
            <button class="btn btn-primary"><?= icon('filter') ?></button>
        </form>
        <div class="card flush"><div class="table-wrap"><table class="table">
            <thead><tr><th>سؤال</th><th>نوع</th><th>دسته</th><th>دشواری</th><th>استفاده</th><th>درصد پاسخ صحیح</th><th></th></tr></thead><tbody>
            <?php foreach ($page['rows'] as $q): ?>
                <tr style="<?= $q['is_active'] ? '' : 'opacity:.55' ?>"><td class="small"><?= e(str_limit($q['text'], 100)) ?><?= $q['tags'] ? '<div class="faint">#' . e(str_replace(',', ' #', $q['tags'])) . '</div>' : '' ?></td>
                <td><span class="badge badge-gray"><?= e(label('qtype', $q['type'])) ?></span></td><td class="small"><?= e($q['cat_name'] ?? '—') ?></td>
                <td><span class="badge badge-<?= ['', 'success', 'warning', 'danger'][(int)$q['difficulty']] ?? 'gray' ?>"><?= e(App\Core\Labels::DIFFICULTY[(int)$q['difficulty']] ?? '') ?></span></td>
                <td class="num"><?= fa($q['used']) ?> آزمون</td><td style="min-width:100px"><?= $q['rate'] !== null ? progress_bar((float)$q['rate'] * 100) : '<span class="faint">—</span>' ?></td>
                <td class="actions"><?php if (can('questions.edit')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/questions/' . $q['id'] . '/edit') ?>"><?= icon('pencil') ?></a><?php endif; ?><?php if (can('questions.delete')): ?><form class="inline" method="post" action="<?= url('/admin/questions/' . $q['id'] . '/delete') ?>" data-confirm="سؤال حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$page['rows']): ?><tr><td colspan="7"><div class="empty"><?= icon('circle-help') ?><div>سؤالی یافت نشد</div></div></td></tr><?php endif; ?>
            </tbody></table></div></div>
        <?= paginate_links($page) ?>
    </div>
    <div class="card"><h3><?= icon('folder-tree') ?> دسته‌های سؤال</h3>
        <?php foreach ($cats as $c): ?><div class="list-item"><a class="grow" href="<?= url('/admin/questions', ['category' => $c['id']]) ?>"><?= e($c['name']) ?></a><span class="badge badge-gray"><?= fa($c['n']) ?></span><?php if (can('questions.delete')): ?><form method="post" action="<?= url('/admin/question-categories/' . $c['id'] . '/delete') ?>" data-confirm="دسته حذف شود؟ سؤالات بدون دسته می‌شوند."><?= csrf_field() ?><button class="btn btn-xs btn-ghost"><?= icon('x') ?></button></form><?php endif; ?></div><?php endforeach; ?>
        <?php if (can('questions.create')): ?><form class="flex mt-1" method="post" action="<?= url('/admin/question-categories') ?>"><?= csrf_field() ?><input type="text" name="name" placeholder="دسته جدید" required><button class="btn btn-sm btn-primary"><?= icon('plus') ?></button></form><?php endif; ?>
    </div>
</div>
