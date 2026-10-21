<?php
$backUrl = $fromLesson ? url('/admin/lessons/' . $fromLesson . '/edit') . '#lesson-extras' : url('/admin/courses/' . $c['id'], ['tab' => 'exercises']);
$ex = $x;
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/courses') ?>">دوره‌ها</a> / <a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a> / <a href="<?= url('/admin/courses/' . $c['id'], ['tab' => 'exercises']) ?>">تمرین‌ها</a></div>
        <h1>ویرایش تمرین</h1>
        <div class="sub flex flex-wrap"><?= status_badge($x['status']) ?><?= $x['is_required'] ? '<span class="badge badge-danger">الزامی</span>' : '<span class="badge badge-gray">اختیاری</span>' ?></div>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline" href="<?= url('/learn/exercise/' . $x['id']) ?>" target="_blank"><?= icon('eye') ?> نمایش برای فراگیر</a>
        <a class="btn btn-ghost" href="<?= $backUrl ?>"><?= icon('chevron-right') ?> بازگشت</a>
    </div>
</div>
<div class="grid g-main">
    <form class="card" method="post" action="<?= url('/admin/exercises/' . $x['id']) ?>"><?= csrf_field() ?>
        <?php if ($fromLesson): ?><input type="hidden" name="return_lesson" value="<?= (int)$fromLesson ?>"><?php endif; ?>
        <?php include __DIR__ . '/_exercise_fields.php'; ?>
        <div class="exl-foot"><a class="btn btn-ghost" href="<?= $backUrl ?>">انصراف</a><button class="btn btn-primary"><?= icon('save') ?> ذخیره تغییرات</button></div>
    </form>
    <div class="stack">
        <div class="card">
            <h3><?= icon('send') ?> پاسخ‌های فراگیران</h3>
            <div class="exs-stats">
                <div><b><?= fa((int)$subs['n']) ?></b><span>کل ارسال‌ها</span></div>
                <div class="is-warn"><b><?= fa((int)$subs['pending']) ?></b><span>منتظر بررسی</span></div>
                <div class="is-ok"><b><?= fa((int)$subs['accepted']) ?></b><span>پذیرفته</span></div>
            </div>
            <?php if (can('reviews.view') && (int)$subs['pending']): ?><a class="btn btn-primary w-100 mt-2" href="<?= url('/admin/reviews') ?>"><?= icon('list-checks') ?> بررسی و تصحیح</a><?php endif; ?>
        </div>
        <?php if (can('lessons.delete')): ?>
        <div class="card">
            <h3><?= icon('trash-2') ?> حذف تمرین</h3>
            <p class="small muted">سوابق ارسال فراگیران حفظ می‌شود، ولی تمرین دیگر نمایش داده نمی‌شود.</p>
            <form method="post" action="<?= url('/admin/exercises/' . $x['id'] . '/delete') ?>" data-confirm="تمرین حذف شود؟"><?= csrf_field() ?><button class="btn btn-outline w-100" style="color:var(--danger);border-color:var(--danger)"><?= icon('trash-2') ?> حذف این تمرین</button></form>
        </div>
        <?php endif; ?>
    </div>
</div>
