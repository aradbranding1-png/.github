<?php
$s = fn($k) => (string)setting($k, '');
$runId = (int)($_GET['run'] ?? 0);
$ed = $edit;
?>
<div class="page-head"><div><h1>خدمات و اتصال به سامانه فروش</h1><div class="sub">فهرست خدمات نظام رشد، نگاشت نام خدمات سامانه فروش و بروزرسانی خدمات خریداری‌شده مشتریان</div></div></div>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="grid g-main mb-3">
    <div class="card" id="sync">
        <h3><?= icon('refresh-cw') ?> بروزرسانی خدمات تمام مشتریان</h3>
        <p class="small muted">اطلاعات خدمات هنگام نمایش نظام رشد از API خوانده نمی‌شود؛ با این دکمه برای همه تاجران (همه شماره‌های ثبت‌شده هر نفر) از سامانه فروش دریافت و در پایگاه داده سامانه ذخیره می‌شود. برای یک تاجر خاص از صفحه مسیر رشد همان تاجر «بروزرسانی خدمات» را بزنید.</p>
        <div class="tg-kpi mb-2">
            <div><div class="small faint">تاجران دارای اطلاعات خدمات</div><b><?= nf((int)($stats['users'] ?? 0)) ?></b></div>
            <div><div class="small faint">هرگز بروزرسانی نشده</div><b><?= nf($never) ?></b></div>
            <div><div class="small faint">آخرین ارتباط با API</div><b class="small"><?= !empty($stats['last_api']) ? jdatetime($stats['last_api']) : '—' ?></b></div>
        </div>
        <?php if ($run): ?>
            <div data-sync-run="<?= (int)$run['id'] ?>" data-step-url="<?= url('/admin/growth/sync/' . $run['id'] . '/step') ?>" data-autostart="<?= $runId === (int)$run['id'] ? '1' : '0' ?>">
                <div class="flex between small"><span>در حال بروزرسانی… <b data-sync-done><?= nf($run['done']) ?></b> از <b data-sync-total><?= nf($run['total']) ?></b> تاجر</span><span data-sync-pct><?= fa($run['total'] ? round($run['done'] * 100 / $run['total']) : 0) ?>٪</span></div>
                <div class="pbar pbar-primary tg-sync mt-1"><i data-sync-bar style="width:<?= $run['total'] ? round($run['done'] * 100 / $run['total'], 1) : 0 ?>%"></i></div>
                <div class="small faint mt-1">موفق: <span data-sync-ok><?= fa($run['ok']) ?></span> · ناموفق: <span data-sync-failed><?= fa($run['failed']) ?></span> · خدمات دریافت‌شده: <span data-sync-svc><?= fa($run['services']) ?></span></div>
                <div class="flex mt-2"><button type="button" class="btn btn-primary btn-sm" data-sync-go><?= icon('play') ?> ادامه در همین صفحه</button>
                    <form method="post" action="<?= url('/admin/growth/sync/' . $run['id'] . '/cancel') ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= icon('x') ?> توقف</button></form></div>
                <div class="hint">اگر صفحه را ببندید، ادامه کار با Cron (هر ۱۵ دقیقه) انجام می‌شود.</div>
            </div>
        <?php elseif (can('growth.run')): ?>
            <form method="post" action="<?= url('/admin/growth/sync') ?>" data-confirm="خدمات همه تاجران از سامانه فروش بروزرسانی شود؟ بسته به تعداد تاجران چند دقیقه طول می‌کشد."><?= csrf_field() ?>
                <button class="btn btn-grad"<?= $configured ? '' : ' disabled' ?>><?= icon('refresh-cw') ?> بروزرسانی خدمات تمام مشتریان</button>
                <?php if (!$configured): ?><span class="small faint">ابتدا اتصال را در پایین همین صفحه تنظیم و فعال کنید.</span><?php endif; ?>
            </form>
        <?php endif; ?>
        <?php if ($lastRun && $lastRun['status'] !== 'running'): ?><div class="small faint mt-2"><?= icon('history') ?> آخرین اجرا: <?= jdatetime($lastRun['started_at']) ?> · <?= ['done' => 'کامل شد', 'cancelled' => 'متوقف شد'][$lastRun['status']] ?? $lastRun['status'] ?> · <?= fa($lastRun['done']) ?> تاجر (<?= fa($lastRun['ok']) ?> موفق، <?= fa($lastRun['failed']) ?> ناموفق)</div><?php endif; ?>
    </div>
    <div class="card" id="unmatched">
        <h3><?= icon('link') ?> نام‌های بدون نگاشت</h3>
        <p class="small muted">این خدمات از سامانه فروش آمده‌اند ولی به هیچ خدمت نظام رشد وصل نیستند. هر کدام را به خدمت موجود وصل کنید یا به‌عنوان خدمت جدید اضافه کنید.</p>
        <?php if (!$unmatched): ?><div class="faint small">موردی وجود ندارد.</div><?php endif; ?>
        <div class="stack">
        <?php foreach ($unmatched as $un): ?>
            <form method="post" action="<?= url('/admin/growth/services/map') ?>" class="list-item" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="service_name" value="<?= e($un['service_name']) ?>">
                <div class="grow"><b class="small"><?= e($un['service_name']) ?></b><div class="small faint"><?= fa($un['users']) ?> نفر<?= $un['service_code'] ? ' · کد ' . e($un['service_code']) : '' ?></div></div>
                <?php if (can('growth.edit')): ?><select name="service_id" style="width:auto;max-width:170px"><option value="">+ خدمت جدید</option><?php foreach ($catalog as $c): ?><option value="<?= (int)$c['id'] ?>">← <?= e($c['name']) ?></option><?php endforeach; ?></select><button class="btn btn-xs btn-outline"><?= icon('check') ?></button><?php endif; ?>
            </form>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid g-main mb-3" id="catalog">
    <div class="card flush">
        <div class="card-head"><h3><?= icon('package') ?> فهرست خدمات نظام رشد</h3><?php if (can('growth.edit')): ?><span class="small faint"><?= icon('grip-vertical') ?> برای تغییر ترتیب، ردیف‌ها را بکشید</span><?php endif; ?><span class="small faint"><?= fa(count($catalog)) ?> خدمت</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th style="width:36px"></th><th>خدمت</th><th>نام این خدمت در سامانه فروش</th><th>در مراحل</th><th>دریافت‌کنندگان</th><th></th></tr></thead>
            <tbody<?= can('growth.edit') ? ' data-sortable="' . url('/admin/growth/services/order') . '"' : '' ?>>
            <?php foreach ($catalog as $c): ?>
                <tr data-id="<?= (int)$c['id'] ?>"<?= (int)$c['active'] ? '' : ' style="opacity:.55"' ?>>
                    <td><?php if (can('growth.edit')): ?><span class="drag-h" title="برای تغییر ترتیب بکشید"><?= icon('grip-vertical') ?></span><?php endif; ?></td>
                    <td><b class="small"><?= e($c['name']) ?></b><?= $c['category'] ? '<div class="small faint">' . e($c['category']) . '</div>' : '' ?></td>
                    <td class="small faint"><?= nl2br(e(str_limit(str_replace("\n", ' | ', (string)$c['match_keys']), 90))) ?></td>
                    <td class="small"><?= isset($usage[(int)$c['id']]) ? e(implode('، ', $usage[(int)$c['id']])) : '<span class="faint">—</span>' ?></td>
                    <td class="num"><?= fa($c['users']) ?></td>
                    <td class="nowrap"><?php if (can('growth.edit')): ?><a class="btn btn-xs btn-ghost" href="<?= url('/admin/growth/services', ['edit' => $c['id']]) ?>#catalog"><?= icon('pencil') ?></a><?php endif; ?>
                        <?php if (can('growth.delete')): ?><form class="inline" method="post" action="<?= url('/admin/growth/services/' . $c['id'] . '/delete') ?>" data-confirm="خدمت «<?= e($c['name']) ?>» حذف شود؟<?= isset($usage[(int)$c['id']]) ? ' این خدمت از الزامات ' . e(implode('، ', $usage[(int)$c['id']])) . ' هم برداشته می‌شود.' : '' ?>"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('trash-2') ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php if (can('growth.edit')): ?>
    <form class="card" method="post" action="<?= url($ed ? '/admin/growth/services/' . $ed['id'] : '/admin/growth/services') ?>"><?= csrf_field() ?>
        <h3><?= icon($ed ? 'pencil' : 'plus') ?> <?= $ed ? 'ویرایش خدمت' : 'خدمت جدید' ?></h3>
        <div class="field"><label>نام خدمت <span class="req">*</span></label><input type="text" name="name" value="<?= e($ed['name'] ?? '') ?>" required></div>
        <div class="field"><label>دسته</label><input type="text" name="category" value="<?= e($ed['category'] ?? '') ?>" placeholder="مثلاً: برندسازی"></div>
        <div class="field"><label>نام یا کد همین خدمت در سامانه فروش (اگر با نام بالا فرق دارد — هر خط یکی)</label><textarea name="match_keys" rows="4" class="ltr-auto" placeholder="طراحی لوگو و هویت بصری&#10;BRD-01&#10;طراحی برند*"><?= e($ed['match_keys'] ?? '') ?></textarea>
            <div class="hint">نام خود خدمت همیشه بررسی می‌شود. اگر انتهای یک خط <b>*</b> بگذارید، هر خدمتی که آن عبارت را داشته باشد هم پذیرفته می‌شود.</div></div>
        <div class="field"><label>لینک خرید / درخواست (اختیاری)</label><input type="url" class="ltr" name="buy_url" value="<?= e($ed['buy_url'] ?? '') ?>" placeholder="https://…"><div class="hint">برای خدمات دریافت‌نشده به تاجر نمایش داده می‌شود.</div></div>
        <div class="field"><label>توضیح کوتاه</label><input type="text" name="description" value="<?= e($ed['description'] ?? '') ?>"></div>
        <input type="hidden" name="sort" value="<?= (int)($ed['sort'] ?? 0) ?>"><div class="form-grid"><div class="field"><label>وضعیت</label><select name="active"><option value="1">فعال</option><option value="0"<?= selected('0', (string)($ed['active'] ?? '1')) ?>>غیرفعال</option></select></div></div>
        <button class="btn btn-primary w-100"><?= icon('save') ?> ذخیره</button>
        <?php if ($ed): ?><a class="btn btn-ghost btn-sm w-100 mt-1" href="<?= url('/admin/growth/services') ?>#catalog">انصراف</a><?php endif; ?>
    </form>
    <?php endif; ?>
</div>

<div class="grid g-main" id="api">
    <form class="card" method="post" action="<?= url('/admin/growth/services/api') ?>"><?= csrf_field() ?>
        <h3><?= icon('link') ?> تنظیمات اتصال به سامانه خدمات/فروش (API)</h3>
        <label class="switch mb-2"><input type="checkbox" name="growth_api_enabled" value="1"<?= checked($s('growth_api_enabled') === '1') ?>> اتصال فعال باشد</label>
        <div class="field"><label>آدرس API</label><input type="url" class="ltr" name="growth_api_url" value="<?= e($s('growth_api_url')) ?>" placeholder="https://sales.aradbranding.me/api/customer-services"><div class="hint">اگر آدرس شامل <code>{mobile}</code> یا <code>{name}</code> باشد، همان‌جا جایگزین می‌شود؛ در غیر این صورت به‌صورت پارامتر ارسال می‌شود.</div></div>
        <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div class="field"><label>متد</label><select name="growth_api_method"><option>GET</option><option<?= $s('growth_api_method') === 'POST' ? ' selected' : '' ?>>POST</option></select><div class="hint">POST با بدنه JSON</div></div>
            <div class="field"><label>پارامتر موبایل</label><input type="text" class="ltr" name="growth_api_phone_param" value="<?= e($s('growth_api_phone_param') ?: 'mobile') ?>"></div>
            <div class="field"><label>پارامتر نام</label><input type="text" class="ltr" name="growth_api_name_param" value="<?= e($s('growth_api_name_param')) ?>" placeholder="name (خالی = ارسال نشود)"></div>
            <div class="field"><label>قالب موبایل</label><select name="growth_api_phone_format"><?php foreach (['09' => '09121234567', '98' => '989121234567', '+98' => '+989121234567', '9' => '9121234567'] as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $s('growth_api_phone_format') ?: '09') ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>مهلت پاسخ (ثانیه)</label><input type="number" name="growth_api_timeout" min="3" max="60" value="<?= e($s('growth_api_timeout') ?: '15') ?>"></div>
        </div>
        <details class="mb-2"><summary class="small" style="cursor:pointer;color:var(--primary)"><?= icon('settings') ?> نگاشت پاسخ (اختیاری — معمولاً خودکار تشخیص داده می‌شود)</summary>
            <div class="form-grid mt-2" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
                <div class="field"><label>مسیر فهرست در JSON</label><input type="text" class="ltr" name="growth_api_list_path" value="<?= e($s('growth_api_list_path')) ?>" placeholder="data.services"></div>
                <div class="field"><label>فیلد نام خدمت</label><input type="text" class="ltr" name="growth_api_field_name" value="<?= e($s('growth_api_field_name')) ?>" placeholder="title"></div>
                <div class="field"><label>فیلد کد خدمت</label><input type="text" class="ltr" name="growth_api_field_code" value="<?= e($s('growth_api_field_code')) ?>" placeholder="code"></div>
                <div class="field"><label>فیلد وضعیت</label><input type="text" class="ltr" name="growth_api_field_status" value="<?= e($s('growth_api_field_status')) ?>" placeholder="status"></div>
                <div class="field"><label>فیلد تاریخ</label><input type="text" class="ltr" name="growth_api_field_date" value="<?= e($s('growth_api_field_date')) ?>" placeholder="paid_at"></div>
                <div class="field"><label>وضعیت‌های «خریداری‌شده»</label><input type="text" class="ltr" name="growth_api_ok_statuses" value="<?= e($s('growth_api_ok_statuses')) ?>" placeholder="paid,active,done"><div class="hint">خالی = همه ردیف‌ها خرید حساب می‌شوند.</div></div>
            </div>
        </details>
        <div class="alert alert-<?= $tokenSet ? 'success' : 'info' ?>"><?= icon('key-round') ?><div>کلید امنیتی API <?= $tokenSet ? 'در فایل .env تنظیم شده است.' : 'تنظیم نشده.' ?> برای امنیت، کلید فقط در فایل <code>.env</code> نگهداری می‌شود: <code class="ltr">SERVICES_API_TOKEN=…</code> (به‌صورت <code>Authorization: Bearer</code> ارسال می‌شود؛ برای هدر دیگر: <code class="ltr">SERVICES_API_HEADER=X-API-Key</code>).</div></div>
        <?php if (can('growth.edit')): ?><button class="btn btn-primary"><?= icon('save') ?> ذخیره تنظیمات</button><?php endif; ?>
    </form>
    <div class="stack">
        <form class="card" method="post" action="<?= url('/admin/growth/services/api-test') ?>"><?= csrf_field() ?>
            <h3><?= icon('terminal') ?> تست اتصال</h3>
            <div class="field"><label>شماره موبایل</label><input type="text" class="ltr" name="phone" value="<?= e($test['phone'] ?? '') ?>" placeholder="09121234567" required></div>
            <div class="field"><label>نام مشتری (اختیاری)</label><input type="text" name="name" value="<?= e($test['name'] ?? '') ?>"></div>
            <button class="btn btn-outline w-100"><?= icon('send') ?> ارسال درخواست آزمایشی</button>
            <div class="hint">چیزی ذخیره نمی‌شود؛ فقط پاسخ و خدمات تشخیص‌داده‌شده نمایش داده می‌شود.</div>
        </form>
        <div class="card small muted"><b><?= icon('info') ?> قالب پیشنهادی پاسخ</b>
<pre class="tg-json mt-1">{"ok": true, "data": [
  {"title": "طراحی برند", "code": "BRD-01",
   "status": "paid", "paid_at": "2026-05-10"},
  {"title": "سایت تجاری", "status": "paid"}
]}</pre>قالب‌های دیگر (فهرست ساده، data.services، orders و…) هم با تنظیم «نگاشت پاسخ» پشتیبانی می‌شوند.</div>
    </div>
</div>

<?php if ($test): $r = $test['result']; ?>
<div class="card mt-3" id="test-result">
    <h3><?= icon($r['ok'] ? 'circle-check' : 'circle-x') ?> نتیجه تست <?= $r['http'] ? '<span class="badge badge-gray ltr">HTTP ' . (int)$r['http'] . '</span>' : '' ?></h3>
    <?php if ($r['url']): ?><div class="small faint ltr mb-1"><?= e($r['url']) ?></div><?php endif; ?>
    <?php if (!$r['ok']): ?><div class="alert alert-danger"><?= icon('triangle-alert') ?><div><?= e($r['error']) ?></div></div><?php else: ?>
        <?php if (!empty($r['not_found'])): ?>
        <div class="alert alert-info"><?= icon('info') ?><div>ارتباط و کلید API درست است، ولی این شماره در سامانه فروش مشتری ثبت‌شده‌ای ندارد. شماره مشتری‌ای را امتحان کنید که خرید داشته است.</div></div>
        <?php else: ?>
        <div class="alert alert-success"><?= icon('check') ?><div>ارتباط برقرار شد؛ <?= fa(count($r['items'])) ?> خدمت تشخیص داده شد.</div></div>
        <?php endif; ?>
        <?php if ($r['items']): ?><div class="table-wrap"><table class="table"><thead><tr><th>نام</th><th>کد</th><th>وضعیت</th><th>تاریخ</th><th>خریداری‌شده؟</th><th>خدمت نظام رشد</th></tr></thead><tbody>
            <?php foreach ($r['items'] as $it): ?><tr><td class="small"><?= e($it['name']) ?></td><td class="small ltr"><?= e($it['code'] ?? '') ?></td><td class="small"><?= e($it['status'] ?? '') ?></td><td class="small"><?= $it['date'] ? jdate($it['date']) : '—' ?></td><td><?= $it['active'] ? '<span class="tg-yes">بله ✓</span>' : '<span class="tg-no">خیر</span>' ?></td><td class="small"><?= $it['match'] ? e($it['match']) : '<span class="badge badge-gray">بدون نگاشت</span>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($r['body'] !== ''): ?><details class="mt-2"><summary class="small" style="cursor:pointer">پاسخ خام</summary><pre class="tg-json"><?= e($r['body']) ?></pre></details><?php endif; ?>
</div>
<?php endif; ?>
