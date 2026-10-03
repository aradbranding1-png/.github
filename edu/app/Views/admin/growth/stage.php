<?php
use App\Services\TraderGrowth;
use App\Services\EventService;
$v = fn($k, $d = '') => old($k, $s[$k] ?? $d);
$trackNames = array_column($tracks, 'name', 'id');
$kindLbl = TraderGrowth::KINDS;
$canEdit = can($s ? 'growth.edit' : 'growth.create');
$linked = array_flip(array_map(fn($i) => $i['kind'] . ':' . $i['ref_id'], $items));
$trackSel = function (?int $cur = null, string $name = 'track_id') use ($tracks) {
    $h = '<select name="' . $name . '"><option value="">همه مسیرها</option>';
    foreach ($tracks as $t) $h .= '<option value="' . (int)$t['id'] . '"' . selected($t['id'], $cur) . '>فقط ' . e($t['name']) . '</option>';
    return $h . '</select>';
};
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/growth') ?>">نظام رشد تاجر</a></div>
        <h1><?= $s ? 'مرحله ' . fa($no) . ': ' . e($s['title']) : 'مرحله جدید' ?></h1>
        <?php if ($s): ?><div class="sub"><?= nf($traders) ?> تاجر در این مرحله هستند · <?= fa(count($items)) ?> مورد در الزامات</div><?php endif; ?></div>
    <?php if ($s && can('growth.delete')): ?><form method="post" action="<?= url('/admin/growth/stages/' . $s['id'] . '/delete') ?>" data-confirm="این مرحله حذف شود؟ (اگر سابقه تأیید داشته باشد غیرفعال می‌شود)"><?= csrf_field() ?><button class="btn btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?> حذف مرحله</button></form><?php endif; ?>
</div>

<form method="post" id="stage-form" action="<?= url($s ? '/admin/growth/stages/' . $s['id'] : '/admin/growth/stages') ?>"><?= csrf_field() ?>
<div class="grid g-main mb-3">
    <div class="stack">
        <div class="card">
            <h3><?= icon('flag') ?> مشخصات مرحله</h3>
            <div class="form-grid">
                <div class="field"><label>عنوان <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required maxlength="150"></div>
                <div class="field"><label>فصل</label><select name="season_id"><option value="">—</option><?php foreach ($seasons as $i => $se): ?><option value="<?= (int)$se['id'] ?>"<?= selected($se['id'], $v('season_id')) ?>>فصل <?= fa($i + 1) ?>: <?= e($se['title']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label>هدف مرحله</label><textarea name="goal" rows="2"><?= e($v('goal')) ?></textarea><div class="hint">در صفحه تاجر بالای جزئیات مرحله نمایش داده می‌شود.</div></div>
            <div class="field"><label>توضیحات تکمیلی (اختیاری)</label><textarea name="description" rows="4"><?= e($v('description')) ?></textarea><div class="hint">متن ساده، HTML پایه یا Markdown.</div></div>
            <div class="form-grid">
                <div class="field"><label>آیکون</label><select name="icon"><?php foreach (TraderGrowth::ICONS as $ic): ?><option value="<?= $ic ?>"<?= selected($ic, $v('icon', 'flag')) ?>><?= $ic ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>رنگ</label><input type="color" name="color" value="<?= e($v('color', '#4f46e5')) ?>" style="height:42px;padding:.2rem"></div>
                <div class="field"><label>وضعیت</label><select name="active"><option value="1"<?= selected('1', (string)$v('active', '1')) ?>>فعال</option><option value="0"<?= selected('0', (string)$v('active', '1')) ?>>غیرفعال (از مسیر حذف می‌شود)</option></select></div>
            </div>
        </div>
        <div class="card">
            <h3><?= icon('percent') ?> شرط عبور و وزن‌ها</h3>
            <div class="form-grid">
                <div class="field"><label>نوع تأیید مرحله</label><select name="approval"><?php foreach (TraderGrowth::APPROVALS as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $v('approval', 'auto')) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>درصد لازم برای عبور</label><input type="number" name="pass_percent" min="1" max="100" value="<?= e($v('pass_percent', 100)) ?>"><div class="hint">۱۰۰ = همه موارد لازم است.</div></div>
            </div>
            <div class="form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
                <div class="field"><label><?= icon('graduation-cap') ?> وزن آموزش‌ها</label><input type="number" name="edu_weight" min="0" max="1000" value="<?= e($v('edu_weight', 100)) ?>"></div>
                <div class="field"><label><?= icon('package') ?> وزن خدمات</label><input type="number" name="svc_weight" min="0" max="1000" value="<?= e($v('svc_weight', 0)) ?>"></div>
                <div class="field"><label><?= icon('handshake') ?> وزن معاملات</label><input type="number" name="deal_weight" min="0" max="1000" value="<?= e($v('deal_weight', 0)) ?>"></div>
            </div>
            <div class="hint">وزن‌ها نسبی‌اند؛ مثلاً آموزش ۴۰ و خدمات ۶۰. بخشی که موردی در آن تعریف نشده خودکار از محاسبه کنار می‌رود و وزن آن بین بقیه تقسیم می‌شود.</div>
        </div>
        <div class="card">
            <h3><?= icon('handshake') ?> معاملات و مدارک تأیید</h3>
            <div class="form-grid">
                <div class="field"><label>تعداد معاملات تأییدشده لازم</label><input type="number" name="min_deals" min="0" value="<?= e($v('min_deals', 0)) ?>"><div class="hint">۰ = بدون شرط معامله. مثلاً «تجارت اول» = ۱.</div></div>
                <div class="field"><label>&nbsp;</label><label class="switch"><input type="checkbox" name="allow_deals" value="1"<?= checked($v('allow_deals', 0)) ?>> امکان ثبت معامله از این مرحله</label></div>
            </div>
            <div class="field"><label>مدارک لازم برای درخواست تأیید (هر خط یک مدرک)</label><textarea name="request_docs" rows="4" placeholder="ثبت شرکت&#10;اطلاعات حقوقی"><?= e($v('request_docs')) ?></textarea><div class="hint">برای مراحلی که تأیید کارشناس دارند؛ تاجر برای هر مدرک فایل پیوست می‌کند.</div></div>
            <div class="field"><label>راهنمای تاجر برای این مرحله</label><input type="text" name="request_hint" value="<?= e($v('request_hint')) ?>"></div>
        </div>
    </div>
    <div class="stack">
        <?php if ($canEdit): ?><div class="card"><button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> ذخیره مرحله</button><div class="hint mt-1">پس از ذخیره، درصد پیشرفت همه تاجران دوباره محاسبه می‌شود.</div></div><?php endif; ?>
        <div class="card small muted">
            <b><?= icon('info') ?> نحوه محاسبه</b>
            <p class="mt-1">درصد هر بخش = موارد انجام‌شده ÷ کل موارد (با احتساب وزن هر مورد). درصد مرحله = میانگین وزنی بخش‌ها.</p>
            <p>اگر بعداً دوره، جلسه یا خدمت جدیدی به مرحله اضافه کنید، درصد تاجرانی که مرحله را تمام کرده بودند کم می‌شود (مثلاً ۱۰ از ۱۱ = ۹۰٪) و تا انجام موارد جدید، مرحله کامل محسوب نمی‌شود.</p>
            <p class="mb-0">«همه وبینارها/کارگاه‌ها» پویاست: هر جلسه جدیدی که بسازید خودکار جزو الزامات می‌شود.</p>
        </div>
    </div>
</div>
</form>

<?php if ($s): ?>
<div class="grid g-main" id="items">
    <div class="card flush">
        <div class="card-head"><h3><?= icon('list-checks') ?> الزامات این مرحله</h3><span class="small faint"><?= fa(count($items)) ?> مورد</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>نوع</th><th>مورد</th><th>مسیر</th><th>وزن</th><th></th></tr></thead>
            <tbody>
            <?php if (!$items): ?><tr><td colspan="5"><div class="empty"><?= icon('list-checks') ?><div>هنوز موردی اضافه نشده؛ از ستون کناری دوره، جلسه یا خدمت اضافه کنید.</div></div></td></tr><?php endif; ?>
            <?php foreach ($items as $it):
                $t = EventService::TYPES[$it['ref_type'] ?? ''] ?? null;
                $title = match ($it['kind']) {
                    'course', 'event', 'service' => $titles[$it['kind']][(int)$it['ref_id']] ?? '(حذف‌شده)',
                    'event_all' => 'همه ' . ($t['plural'] ?? '') . ' (' . fa((int)($eventCounts[$it['ref_type']] ?? 0)) . ' جلسه فعال)',
                    'event_count' => 'حداقل ' . fa((int)$it['min_count']) . ' ' . ($t['label'] ?? ''),
                    default => $it['kind'],
                }; ?>
                <tr>
                    <td><span class="badge badge-<?= ['course' => 'primary', 'service' => 'warning', 'event' => 'purple', 'event_all' => 'purple', 'event_count' => 'info'][$it['kind']] ?? 'gray' ?>"><?= e($kindLbl[$it['kind']] ?? $it['kind']) ?></span></td>
                    <td><b class="small"><?= e($title) ?></b></td>
                    <td colspan="2">
                        <?php if (can('growth.edit')): ?>
                        <div class="tg-item-form" data-item-row>
                            <?= str_replace('<select ', '<select form="stage-form" ', $trackSel($it['track_id'] !== null ? (int)$it['track_id'] : null, 'items[' . (int)$it['id'] . '][track_id]')) ?>
                            <input form="stage-form" type="number" name="items[<?= (int)$it['id'] ?>][weight]" min="1" max="100" value="<?= (int)$it['weight'] ?>" title="وزن">
                            <?php if ($it['kind'] === 'event_count'): ?><input form="stage-form" type="number" name="items[<?= (int)$it['id'] ?>][min_count]" min="1" value="<?= (int)$it['min_count'] ?>" title="حداقل تعداد"><?php endif; ?>
                            <button form="stage-form" formaction="<?= url('/admin/growth/items/' . $it['id']) ?>" class="btn btn-sm btn-outline" title="ذخیره فقط همین ردیف"><?= icon('save') ?></button>
                        </div>
                        <?php else: ?><?= $it['track_id'] ? e($trackNames[(int)$it['track_id']] ?? '') : 'همه' ?> · وزن <?= fa($it['weight']) ?><?php endif; ?>
                    </td>
                    <td><?php if (can('growth.edit')): ?><form method="post" action="<?= url('/admin/growth/items/' . $it['id'] . '/delete') ?>" data-confirm="این مورد از الزامات مرحله حذف شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($items && can('growth.edit')): ?><div class="card-foot flex between flex-wrap gap-2" style="padding:.8rem 1rem;border-top:1px solid var(--border)"><span class="small faint" data-unsaved-note><?= icon('info') ?> تغییر مسیر و وزن همه ردیف‌ها با «ذخیره همه تغییرات» یا «ذخیره مرحله» یک‌جا ذخیره می‌شود.</span><button form="stage-form" class="btn btn-primary"><?= icon('save') ?> ذخیره همه تغییرات</button></div><?php endif; ?>
    </div>
    <?php if (can('growth.edit')): ?>
    <div class="stack">
        <form class="card" method="post" action="<?= url('/admin/growth/stages/' . $s['id'] . '/items') ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="course">
            <h3><?= icon('book-open') ?> افزودن دوره</h3>
            <input type="search" data-filter-select="add-courses" placeholder="جست‌وجوی دوره…" class="mb-1">
            <select id="add-courses" name="course_ids[]" multiple size="8"><?php foreach ($courses as $c): if (isset($linked['course:' . $c['id']])) continue; ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?><?= $c['status'] !== 'published' ? ' (منتشرنشده)' : '' ?><?= $c['cat'] ? ' — ' . e($c['cat']) : '' ?></option><?php endforeach; ?></select>
            <div class="hint mb-1">با Ctrl/Cmd چند دوره انتخاب کنید. «فایل‌های تجاری مهارت‌محور» همین دوره‌های سامانه هستند.</div>
            <div class="form-grid"><div class="field"><label>مسیر</label><?= $trackSel() ?></div><div class="field"><label>وزن</label><input type="number" name="weight" value="1" min="1" max="100"></div></div>
            <button class="btn btn-primary w-100"><?= icon('plus') ?> افزودن دوره‌ها</button>
        </form>
        <form class="card" method="post" action="<?= url('/admin/growth/stages/' . $s['id'] . '/items') ?>"><?= csrf_field() ?>
            <h3><?= icon('video') ?> وبینار، کارگاه و میتینگ</h3>
            <div class="field"><label>نوع الزام</label><select name="kind" data-kind-switch><option value="event_all">همه جلسات یک نوع (جلسات جدید خودکار اضافه می‌شوند)</option><option value="event_count">حداقل تعداد شرکت در یک نوع جلسه</option><option value="event">جلسه(های) مشخص</option></select></div>
            <div class="field" data-kind-show="event_all event_count"><label>نوع جلسه</label><select name="event_type"><?php foreach (EventService::TYPES as $k => $t): ?><option value="<?= $k ?>"><?= e($t['plural']) ?> (<?= fa((int)($eventCounts[$k] ?? 0)) ?> فعال)</option><?php endforeach; ?></select></div>
            <div class="field" data-kind-show="event_count"><label>حداقل تعداد شرکت</label><input type="number" name="min_count" value="5" min="1"></div>
            <div class="field" data-kind-show="event"><label>جلسات</label><input type="search" data-filter-select="add-events" placeholder="جست‌وجو…" class="mb-1"><select id="add-events" name="event_ids[]" multiple size="6"><?php foreach ($events as $e): if (isset($linked['event:' . $e['id']])) continue; ?><option value="<?= (int)$e['id'] ?>"><?= e(EventService::TYPES[$e['type']]['label'] ?? '') ?>: <?= e($e['title']) ?><?= $e['starts_at'] ? ' — ' . jdate($e['starts_at']) : '' ?></option><?php endforeach; ?></select></div>
            <div class="form-grid"><div class="field"><label>مسیر</label><?= $trackSel() ?></div><div class="field"><label>وزن هر جلسه</label><input type="number" name="weight" value="1" min="1" max="100"></div></div>
            <button class="btn btn-primary w-100"><?= icon('plus') ?> افزودن</button>
        </form>
        <form class="card" method="post" action="<?= url('/admin/growth/stages/' . $s['id'] . '/items') ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="service">
            <h3><?= icon('package') ?> افزودن خدمت</h3>
            <select name="service_ids[]" multiple size="7"><?php foreach ($services as $sv): if (isset($linked['service:' . $sv['id']])) continue; ?><option value="<?= (int)$sv['id'] ?>"><?= e($sv['name']) ?><?= $sv['category'] ? ' — ' . e($sv['category']) : '' ?></option><?php endforeach; ?></select>
            <div class="hint mb-1">خدمت جدید را از <a href="<?= url('/admin/growth/services') ?>">خدمات و سامانه فروش</a> تعریف کنید.</div>
            <div class="form-grid"><div class="field"><label>مسیر</label><?= $trackSel() ?></div><div class="field"><label>وزن</label><input type="number" name="weight" value="1" min="1" max="100"></div></div>
            <button class="btn btn-primary w-100"><?= icon('plus') ?> افزودن خدمات</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
