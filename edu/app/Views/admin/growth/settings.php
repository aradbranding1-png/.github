<?php
use App\Services\TraderGrowth;
$segs = TraderGrowth::segments();
?>
<div class="page-head"><div><h1>تنظیمات نظام رشد</h1><div class="sub">مخاطبان، رتبه‌ها و ستاره‌ها، مسیرهای تجاری و فصل‌ها</div></div></div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="grid g-main mb-3">
    <form class="card" method="post" action="<?= url('/admin/growth/settings') ?>"><?= csrf_field() ?>
        <h3><?= icon('settings') ?> عمومی</h3>
        <div class="field"><label>نظام رشد برای چه کاربرانی فعال باشد؟</label>
            <div class="chip-select"><?php foreach (['merchant' => 'تاجران', 'agent' => 'نمایندگان', 'employee' => 'کارمندان', 'custom' => 'سایر'] as $k => $l): ?><label><input type="checkbox" name="growth_segments[]" value="<?= $k ?>"<?= checked(in_array($k, $segs, true)) ?>><span><?= $l ?></span></label><?php endforeach; ?></div></div>
        <label class="switch mb-1"><input type="checkbox" name="growth_track_self" value="1"<?= checked(setting('growth_track_self', '1') === '1') ?>> تاجر خودش بتواند مسیر تجاری (داخلی/صادرات/بین‌الملل) را انتخاب یا تغییر دهد</label>
        <label class="switch mb-2"><input type="checkbox" name="growth_show_badge" value="1"<?= checked(setting('growth_show_badge', '1') === '1') ?>> نمایش رتبه و ستاره کنار نام کاربران در همه بخش‌ها</label>
        <div class="form-grid"><div class="field"><label>واحد پول معاملات</label><input type="text" name="growth_currency" value="<?= e(setting('growth_currency', 'تومان')) ?>"></div></div>
        <div class="field"><label>انواع مدارک معامله (هر خط یکی)</label><textarea name="growth_deal_docs" rows="6"><?= e(setting('growth_deal_docs')) ?></textarea><div class="hint">هنگام ثبت معامله، برای هر مورد یک محل آپلود نمایش داده می‌شود.</div></div>
        <button class="btn btn-primary"><?= icon('save') ?> ذخیره</button>
    </form>
    <div class="card" id="ranks">
        <h3><?= icon('star') ?> رتبه‌ها و ستاره‌ها</h3>
        <p class="small muted">رتبه بر اساس مرحله فعلی تاجر تعیین می‌شود و کنار نام او نمایش داده می‌شود.</p>
        <div class="stack">
            <?php foreach ($ranks as $r): ?>
                <details class="list-item" style="display:block">
                    <summary class="flex between" style="cursor:pointer"><span><?= TraderGrowth::badge((int)$r['from_stage'], true, 'lg') ?></span><span class="small faint">مراحل <?= fa($r['from_stage']) ?> تا <?= fa($r['to_stage']) ?></span></summary>
                    <form method="post" action="<?= url('/admin/growth/ranks/' . $r['id']) ?>" class="mt-2"><?= csrf_field() ?>
                        <?php $rk = $r; include __DIR__ . '/_rank_fields.php'; ?>
                        <div class="flex between"><button class="btn btn-sm btn-primary"><?= icon('save') ?> ذخیره</button></div>
                    </form>
                    <form method="post" action="<?= url('/admin/growth/ranks/' . $r['id'] . '/delete') ?>" data-confirm="این رتبه حذف شود؟" class="mt-1"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف</button></form>
                </details>
            <?php endforeach; ?>
        </div>
        <details class="mt-2"><summary class="small" style="cursor:pointer;color:var(--primary)"><?= icon('plus') ?> رتبه جدید</summary>
            <form method="post" action="<?= url('/admin/growth/ranks') ?>" class="mt-2"><?= csrf_field() ?><?php $rk = ['title' => '', 'stars' => 1, 'filled' => 1, 'from_stage' => 1, 'to_stage' => $n, 'color' => '#f59e0b']; include __DIR__ . '/_rank_fields.php'; ?><button class="btn btn-sm btn-primary"><?= icon('plus') ?> افزودن</button></form>
        </details>
    </div>
</div>
<div class="grid g-2">
    <div class="card" id="tracks">
        <h3><?= icon('route') ?> مسیرهای تجاری</h3>
        <p class="small muted">الزامات هر مرحله را می‌توانید مخصوص یک مسیر کنید (مثلاً دوره‌های مرحله ۲ برای «صادرات» با «تجارت داخلی» متفاوت باشد).</p>
        <?php foreach ($tracks as $t): ?>
            <form method="post" action="<?= url('/admin/growth/tracks/' . $t['id']) ?>" class="list-item" style="flex-wrap:wrap"><?= csrf_field() ?>
                <input type="text" name="name" value="<?= e($t['name']) ?>" class="grow" style="min-width:140px">
                <input type="number" name="sort" value="<?= (int)$t['sort'] ?>" style="width:64px" title="ترتیب">
                <select name="active" style="width:auto"><option value="1">فعال</option><option value="0"<?= selected('0', (string)$t['active']) ?>>غیرفعال</option></select>
                <span class="small faint"><?= fa((int)($trackUse[$t['id']] ?? 0)) ?> نفر</span>
                <button class="btn btn-xs btn-outline"><?= icon('save') ?></button>
                <button class="btn btn-xs btn-ghost" style="color:var(--danger)" formaction="<?= url('/admin/growth/tracks/' . $t['id'] . '/delete') ?>" data-confirm="این مسیر حذف شود؟"><?= icon('trash-2') ?></button>
            </form>
        <?php endforeach; ?>
        <form method="post" action="<?= url('/admin/growth/tracks') ?>" class="flex mt-2"><?= csrf_field() ?><input type="text" name="name" placeholder="مسیر جدید" required><input type="hidden" name="sort" value="<?= count($tracks) + 1 ?>"><button class="btn btn-sm btn-primary"><?= icon('plus') ?></button></form>
    </div>
    <div class="card" id="seasons">
        <h3><?= icon('layers') ?> فصل‌ها</h3>
        <?php foreach ($seasons as $i => $se): ?>
            <form method="post" action="<?= url('/admin/growth/seasons/' . $se['id']) ?>" class="list-item" style="flex-wrap:wrap"><?= csrf_field() ?>
                <span class="badge badge-gray">فصل <?= fa($i + 1) ?></span>
                <input type="text" name="title" value="<?= e($se['title']) ?>" class="grow" style="min-width:140px">
                <input type="number" name="sort" value="<?= (int)$se['sort'] ?>" style="width:64px" title="ترتیب">
                <input type="color" name="color" value="<?= e($se['color']) ?>" style="width:46px;height:36px;padding:.1rem">
                <button class="btn btn-xs btn-outline"><?= icon('save') ?></button>
                <button class="btn btn-xs btn-ghost" style="color:var(--danger)" formaction="<?= url('/admin/growth/seasons/' . $se['id'] . '/delete') ?>" data-confirm="این فصل حذف شود؟"><?= icon('trash-2') ?></button>
            </form>
        <?php endforeach; ?>
        <form method="post" action="<?= url('/admin/growth/seasons') ?>" class="flex mt-2"><?= csrf_field() ?><input type="text" name="title" placeholder="فصل جدید" required><input type="hidden" name="sort" value="<?= count($seasons) + 1 ?>"><button class="btn btn-sm btn-primary"><?= icon('plus') ?></button></form>
    </div>
</div>
