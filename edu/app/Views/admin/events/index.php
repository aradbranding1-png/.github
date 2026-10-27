<?php use App\Services\EventService; use App\Services\Credit;
$qs = fn(array $over) => url('/admin/events/' . $type, array_filter(array_merge(['q' => $q, 'when' => $when, 'status' => $status], $over), fn($x) => $x !== '' && $x !== null));
$phases = ['upcoming' => ['پیش رو', 'info'], 'live' => ['در حال برگزاری', 'danger'], 'ended' => ['برگزار شده', 'gray'], 'unscheduled' => ['بدون زمان', 'gray']];
?>
<div class="page-head">
    <div><h1><?= icon($t['icon']) ?> مدیریت <?= e($t['plural']) ?></h1>
        <div class="sub"><?= match ($type) {
            'webinar' => 'هر وبینار به اندازه مدتش (دقیقه) از «اعتبار وبینار» ثبت‌نام‌کننده کم می‌کند. «توقف» آن را از پیشخوان و فهرست مخاطبان برمی‌دارد.',
            'workshop' => 'هر ثبت‌نام در کارگاه یک عدد از «اعتبار کارگاه آنلاین» فراگیر کم می‌کند. «توقف» آن را از دید مخاطبان پنهان می‌کند.',
            default => 'میتینگ‌ها برای کسانی که اشتراک فعال میتینگ دارند و در گروه هدف هستند نمایش داده می‌شوند. «توقف» آن را از دید مخاطبان پنهان می‌کند.',
        } ?></div></div>
    <?php if (can('events.create')): ?><a class="btn btn-grad" href="<?= url('/admin/events/' . $type . '/create') ?>"><?= icon('plus') ?> <?= e($t['label']) ?> جدید</a><?php endif; ?>
</div>

<div class="grid g-4 mb-3 eva-stats">
    <a class="card stat tone-info" href="<?= $qs(['when' => 'upcoming', 'status' => 'active']) ?>"><div class="bubble"><?= icon('calendar') ?></div><div><div class="v"><?= fa((int)($counts['upcoming'] ?? 0)) ?></div><div class="l">پیش رو (فعال)</div></div></a>
    <a class="card stat tone-danger" href="<?= $qs(['when' => 'upcoming', 'status' => 'active']) ?>"><div class="bubble"><?= icon('radio') ?></div><div><div class="v"><?= fa((int)($counts['live'] ?? 0)) ?></div><div class="l">در حال برگزاری</div></div></a>
    <a class="card stat tone-warning" href="<?= $qs(['when' => 'all', 'status' => 'inactive']) ?>"><div class="bubble"><?= icon('circle-pause') ?></div><div><div class="v"><?= fa((int)($counts['stopped'] ?? 0)) ?></div><div class="l">متوقف (پنهان از مخاطبان)</div></div></a>
    <a class="card stat tone-primary" href="<?= $qs(['when' => 'all', 'status' => '']) ?>"><div class="bubble"><?= icon($t['icon']) ?></div><div><div class="v"><?= fa((int)($counts['total'] ?? 0)) ?></div><div class="l">همه <?= e($t['plural']) ?></div></div></a>
</div>

<form class="card eva-filters mb-3" method="get" action="<?= url('/admin/events/' . $type) ?>">
    <div class="eva-search"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="جست‌وجو در عنوان…"></div>
    <div class="seg"><?php foreach (['upcoming' => 'پیش رو', 'past' => 'برگزار شده', 'all' => 'همه'] as $k => $l): ?><a class="<?= $when === $k ? 'active' : '' ?>" href="<?= $qs(['when' => $k]) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="seg"><?php foreach (['' => 'همه وضعیت‌ها', 'active' => 'فعال', 'inactive' => 'متوقف'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="<?= $qs(['status' => $k]) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <input type="hidden" name="when" value="<?= e($when) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
    <button class="btn btn-primary"><?= icon('filter') ?> اعمال</button>
</form>

<?php if (!$page['rows']): ?><div class="card empty"><?= icon($t['icon']) ?><h3>موردی یافت نشد</h3><?php if (can('events.create')): ?><a class="btn btn-primary" href="<?= url('/admin/events/' . $type . '/create') ?>">ایجاد <?= e($t['label']) ?></a><?php endif; ?></div><?php endif; ?>

<div class="eva-list">
<?php foreach ($page['rows'] as $e):
    $ph = EventService::phase($e); $on = $e['status'] === 'active'; $img = image_url($e['image_file_id'] ?: $e['banner_file_id'], 480);
    $segs = $e['segments'] ? array_map(fn($s) => label('segment', $s), explode(',', $e['segments'])) : ['همه کاربران']; ?>
    <article class="card eva<?= $on ? '' : ' is-off' ?>">
        <a class="eva-thumb tone-<?= e($t['tone']) ?>" href="<?= url('/learn/event/' . $e['id']) ?>" title="نمایش صفحه مخاطب">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><?= icon($t['icon']) ?><?php endif; ?>
            <?php if (!$on): ?><span class="eva-off"><?= icon('eye-off') ?> متوقف</span><?php elseif ($ph === 'live'): ?><span class="eva-live"><span class="live-dot"></span> زنده</span><?php endif; ?>
        </a>
        <div class="eva-body">
            <div class="eva-top">
                <h3 class="eva-title"><?= e($e['title']) ?></h3>
                <div class="eva-badges">
                    <span class="badge badge-<?= $on ? 'success' : 'warning' ?>"><?= icon($on ? 'eye' : 'eye-off') ?> <?= $on ? 'فعال' : 'متوقف' ?></span>
                    <span class="badge badge-<?= $phases[$ph][1] ?>"><?= $phases[$ph][0] ?></span>
                </div>
            </div>
            <div class="eva-meta">
                <span class="chip"><?= icon('calendar') ?> <?= jdate($e['starts_at'], 'l j F Y') ?></span>
                <span class="chip"><?= icon('clock') ?> ساعت <?= jdate($e['starts_at'], 'H:i') ?></span>
                <span class="chip"><?= icon('hourglass') ?> <?= fa((int)$e['duration_minutes']) ?> دقیقه</span>
                <span class="chip"><?= icon('users') ?> <?= e(implode('، ', $segs)) ?></span>
                <?php if ($e['group_name']): ?><span class="chip"><?= icon('layers') ?> <?= e($e['group_name']) ?></span><?php endif; ?>
                <?php if ($e['host_name']): ?><span class="chip"><?= icon('mic') ?> <?= e($e['host_name']) ?></span><?php endif; ?>
            </div>
            <div class="eva-nums">
                <?php if ($type !== 'meeting'): ?><div><b><?= fa((int)$e['regs']) ?></b><span>ثبت‌نام</span></div><?php endif; ?>
                <div><b><?= fa((int)$e['joined']) ?></b><span>وارد جلسه شده</span></div>
                <?php if ($type === 'webinar'): ?><div><b><?= e(Credit::format((int)$e['duration_minutes'])) ?></b><span>اعتبار هر ثبت‌نام</span></div><?php endif; ?>
            </div>
            <?php if (can('events.edit')): ?>
            <form method="post" action="<?= url('/admin/event/' . $e['id'] . '/link') ?>" class="eva-link"><?= csrf_field() ?>
                <span class="eva-link-ic"><?= icon('link') ?></span><input type="url" name="join_url" class="ltr" value="<?= e($e['join_url']) ?>" placeholder="https://… لینک ورود به جلسه" aria-label="لینک ورود"><button class="btn btn-sm btn-outline"><?= icon('save') ?> ذخیره</button>
            </form>
            <?php elseif ($e['join_url']): ?><div class="small ltr faint"><?= e($e['join_url']) ?></div><?php endif; ?>
        </div>
        <div class="eva-foot">
            <?php if (can('events.edit')): ?>
                <form method="post" action="<?= url('/admin/event/' . $e['id'] . '/toggle') ?>"<?= $on ? ' data-confirm="«' . e($e['title']) . '» متوقف شود؟ دیگر در پیشخوان و فهرست مخاطبان نمایش داده نمی‌شود."' : '' ?>><?= csrf_field() ?>
                    <input type="hidden" name="to" value="<?= $on ? 'inactive' : 'active' ?>">
                    <?php if ($on): ?><button class="btn btn-sm eva-stop"><?= icon('circle-pause') ?> توقف</button>
                    <?php else: ?><button class="btn btn-sm btn-success"><?= icon('circle-play') ?> فعال‌سازی</button><?php endif; ?>
                </form>
            <?php endif; ?>
            <span class="grow"></span>
            <?php if (can('events.report')): ?><a class="btn btn-sm btn-ghost" href="<?= url('/admin/event/' . $e['id'] . '/registrations') ?>"><?= icon('users') ?> <?= $type === 'meeting' ? 'حاضران' : 'ثبت‌نام‌ها' ?></a><?php endif; ?>
            <?php if (can('events.edit')): ?><a class="btn btn-sm btn-ghost" href="<?= url('/admin/event/' . $e['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش</a><?php endif; ?>
            <?php if (can('events.create')): ?><a class="btn btn-sm btn-ghost" href="<?= url('/admin/events/' . $type . '/create', ['copy' => $e['id']]) ?>" title="کپی با همان مشخصات برای روز بعد"><?= icon('copy') ?> کپی</a><?php endif; ?>
            <?php if (can('events.delete')): ?>
                <form method="post" action="<?= url('/admin/event/' . $e['id'] . '/delete') ?>" data-confirm="«<?= e($e['title']) ?>» حذف شود؟<?= (int)$e['regs'] && $type !== 'meeting' ? ' اعتبار ثبت‌نام‌کنندگان برگشت داده می‌شود.' : '' ?>"><?= csrf_field() ?><input type="hidden" name="refund" value="1"><button class="btn btn-sm btn-ghost eva-del" title="حذف"><?= icon('trash-2') ?></button></form>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?= paginate_links($page) ?>
