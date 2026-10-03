<?php use App\Services\EventService; ?>
<div class="page-head">
    <div><h1><?= icon($t['icon']) ?> مدیریت <?= e($t['plural']) ?></h1>
        <div class="sub"><?= match ($type) {
            'webinar' => 'هر وبینار به اندازه مدتش از «اعتبار وبینار» (ساعت) فراگیر کم می‌کند.',
            'workshop' => 'هر ثبت‌نام در کارگاه یک عدد از «اعتبار کارگاه آنلاین» فراگیر کم می‌کند.',
            default => 'میتینگ‌ها برای کسانی که اشتراک فعال میتینگ دارند و در گروه هدف هستند نمایش داده می‌شوند؛ ثبت‌نام و کسر اعتبار ندارد.',
        } ?></div></div>
    <?php if (can('events.create')): ?><a class="btn btn-grad" href="<?= url('/admin/events/' . $type . '/create') ?>"><?= icon('plus') ?> <?= e($t['label']) ?> جدید</a><?php endif; ?>
</div>
<form class="card filters" method="get" action="<?= url('/admin/events/' . $type) ?>">
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="عنوان"></div>
    <div class="field"><label>زمان</label><select name="when"><option value="upcoming"<?= selected('upcoming', $when) ?>>پیش رو</option><option value="past"<?= selected('past', $when) ?>>برگزار شده</option><option value="all"<?= selected('all', $when) ?>>همه</option></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>
<?php if (!$page['rows']): ?><div class="card empty"><?= icon($t['icon']) ?><h3>موردی یافت نشد</h3><?php if (can('events.create')): ?><a class="btn btn-primary" href="<?= url('/admin/events/' . $type . '/create') ?>">ایجاد <?= e($t['label']) ?></a><?php endif; ?></div><?php endif; ?>
<div class="ev-admin-list">
<?php foreach ($page['rows'] as $e): $ph = EventService::phase($e); $img = image_url($e['image_file_id'] ?: $e['banner_file_id'], 320); ?>
    <div class="card ev-admin">
        <div class="ev-thumb tone-<?= e($t['tone']) ?>"><?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><?= icon($t['icon']) ?><?php endif; ?></div>
        <div class="grow">
            <div class="flex flex-wrap" style="gap:.35rem">
                <?= $e['status'] === 'active' ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>' ?>
                <span class="badge badge-<?= ['upcoming' => 'info', 'live' => 'danger', 'ended' => 'gray', 'unscheduled' => 'gray'][$ph] ?>"><?= ['upcoming' => 'پیش رو', 'live' => 'در حال برگزاری', 'ended' => 'برگزار شده', 'unscheduled' => 'بدون زمان'][$ph] ?></span>
                <?php if ($e['segments']): foreach (explode(',', $e['segments']) as $sg): ?><span class="badge badge-gray"><?= e(label('segment', $sg)) ?></span><?php endforeach; else: ?><span class="badge badge-gray">همه گروه‌ها</span><?php endif; ?>
                <?php if ($e['group_name']): ?><span class="badge badge-primary"><?= icon('layers') ?> <?= e($e['group_name']) ?></span><?php endif; ?>
            </div>
            <h3 class="mt-1 mb-0"><?= e($e['title']) ?></h3>
            <div class="course-meta mt-1">
                <span><?= icon('calendar') ?> <?= jdate($e['starts_at'], 'l j F Y') ?></span>
                <span><?= icon('clock') ?> <?= jdate($e['starts_at'], 'H:i') ?> · <?= App\Services\Credit::format((int)$e['duration_minutes']) ?></span>
                <?php if ($type !== 'meeting'): ?><span><?= icon('users') ?> <?= fa((int)$e['regs']) ?> ثبت‌نام</span><?php endif; ?>
                <span><?= icon('log-in') ?> <?= fa((int)$e['joined']) ?> نفر وارد شده</span>
            </div>
            <?php if (can('events.edit')): ?>
            <form method="post" action="<?= url('/admin/event/' . $e['id'] . '/link') ?>" class="ev-link-form"><?= csrf_field() ?>
                <?= icon('link') ?><input type="url" name="join_url" class="ltr" value="<?= e($e['join_url']) ?>" placeholder="https://… لینک ورود"><button class="btn btn-sm btn-outline"><?= icon('save') ?> ذخیره لینک</button>
            </form>
            <?php elseif ($e['join_url']): ?><div class="small ltr faint mt-1"><?= e($e['join_url']) ?></div><?php endif; ?>
        </div>
        <div class="ev-actions">
            <?php if (can('events.report')): ?><a class="btn btn-sm btn-ghost" href="<?= url('/admin/event/' . $e['id'] . '/registrations') ?>"><?= icon('users') ?> <?= $type === 'meeting' ? 'حاضران' : 'ثبت‌نام‌ها' ?></a><?php endif; ?>
            <?php if (can('events.edit')): ?><a class="btn btn-sm btn-ghost" href="<?= url('/admin/event/' . $e['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش</a><?php endif; ?>
            <?php if (can('events.create')): ?><a class="btn btn-sm btn-ghost" href="<?= url('/admin/events/' . $type . '/create', ['copy' => $e['id']]) ?>" title="کپی با همان مشخصات برای روز بعد"><?= icon('copy') ?> کپی برای روز بعد</a><?php endif; ?>
            <?php if (can('events.delete')): ?>
                <form method="post" action="<?= url('/admin/event/' . $e['id'] . '/delete') ?>" data-confirm="«<?= e($e['title']) ?>» حذف شود؟<?= (int)$e['regs'] && $type !== 'meeting' ? ' اعتبار ثبت‌نام‌کنندگان برگشت داده می‌شود.' : '' ?>"><?= csrf_field() ?><input type="hidden" name="refund" value="1"><button class="btn btn-sm btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف</button></form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?= paginate_links($page) ?>
