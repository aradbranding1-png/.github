<?php
/**
 * /admin/home — editor for every section and item of the public home page.
 * Lists: existing rows + empty rows to add new ones; "ترتیب" orders rows, "حذف" removes them.
 * @var array $home @var array $errors @var array $perms @var int $maxKb
 */
use App\Modules\System\HomeContent;

$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$n = static fn (string ...$path): string => 'home[' . implode('][', $path) . ']';
$id = static fn (string ...$path): string => 'h-' . implode('-', $path);

$text = static function (array $path, string $value, string $label, string $kind = 'text', string $hint = '') use ($n, $id): string {
    $name = $n(...$path);
    $fid = $id(...$path);
    $dir = in_array($kind, ['href', 'ltr'], true) ? ' dir="ltr"' : '';
    $input = $kind === 'long'
        ? '<textarea class="textarea" id="' . e($fid) . '" name="' . e($name) . '" rows="3">' . e($value) . '</textarea>'
        : '<input class="input" id="' . e($fid) . '" name="' . e($name) . '" value="' . e($value) . '"' . $dir
            . ($kind === 'href' ? ' placeholder="/register یا #how یا https://…"' : '') . '>';
    return '<div class="field"><label for="' . e($fid) . '">' . e($label) . '</label>' . $input
        . ($hint !== '' ? '<div class="hint">' . e($hint) . '</div>' : '') . '</div>';
};
$select = static function (array $path, string $value, string $label, array $options) use ($n, $id): string {
    $html = '<div class="field"><label for="' . e($id(...$path)) . '">' . e($label) . '</label><select class="select" id="' . e($id(...$path)) . '" name="' . e($n(...$path)) . '">';
    foreach ($options as $k => $v) {
        $html .= '<option value="' . e($k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $html . '</select></div>';
};
$check = static function (array $path, bool $on, string $label) use ($n): string {
    return '<label class="check"><input type="checkbox" name="' . e($n(...$path)) . '" value="1"' . ($on ? ' checked' : '') . '> ' . e($label) . '</label>';
};

/**
 * Repeatable list. $cols: field => [label, kind, options?]. kind: text|long|href|ltr|icon|art|metric|mode|bool|num|image
 */
$rows = static function (string $list, array $items, array $cols, int $blank = 2) use ($text, $select, $check, $n, $err, $maxKb): string {
    $max = HomeContent::LISTS[$list] ?? 12;
    $tpl = HomeContent::defaults()[$list][0];
    $count = count($items);
    $total = min($max, $count + $blank);
    $html = '<div class="hp-rows">';
    for ($i = 0; $i < $total; $i++) {
        $isNew = $i >= $count;
        $row = $isNew ? array_map(static fn ($v) => is_bool($v) ? false : (is_string($v) ? '' : $v), $tpl) : $items[$i];
        if ($isNew) {
            foreach (['icon', 'art', 'metric', 'mode'] as $keep) {
                if (isset($tpl[$keep])) {
                    $row[$keep] = $tpl[$keep];
                }
            }
            if (isset($row['source'])) {
                $row['source'] = 'manual';
            }
            foreach (['lat', 'lon'] as $num) {
                if (isset($row[$num])) {
                    $row[$num] = '';
                }
            }
        }
        $k = (string) $i;
        $html .= '<fieldset class="hp-row' . ($isNew ? ' is-new' : '') . '"><legend>' . ($isNew ? 'ردیف جدید' : 'ردیف ' . fa_num($i + 1)) . '</legend><div class="hp-grid">';
        foreach ($cols as $field => $def) {
            [$label, $kind] = $def;
            $v = $row[$field] ?? '';
            $html .= match ($kind) {
                'icon' => $select([$list, $k, $field], (string) $v, $label, HomeContent::ICONS),
                'art' => $select([$list, $k, $field], (string) $v, $label, HomeContent::ARTS),
                'metric' => $select([$list, $k, $field], (string) $v, $label, HomeContent::METRICS),
                'source' => $select([$list, $k, $field], (string) $v, $label, HomeContent::SOURCES),
                'mode' => $select([$list, $k, $field], (string) $v, $label, ['sea' => 'دریایی', 'air' => 'هوایی']),
                'bool' => '<div class="field hp-bool">' . $check([$list, $k, $field], (bool) $v, $label) . '</div>',
                'num' => $text([$list, $k, $field], $v === '' ? '' : (string) $v, $label, 'ltr'),
                'image' => '<div class="field hp-image"><label for="img_' . e($list . '_' . $k) . '">' . e($label) . '</label>'
                    . ((string) $v !== '' ? '<img src="' . e(media((string) $v)) . '" alt="" loading="lazy">' . $check([$list, $k, '_remove_image'], false, 'حذف تصویر') : '<span class="hint">بدون تصویر؛ طرح پیش‌فرض نمایش داده می‌شود.</span>')
                    . '<input type="hidden" name="' . e($n($list, $k, 'image')) . '" value="' . e((string) $v) . '">'
                    . '<input class="input" type="file" id="img_' . e($list . '_' . $k) . '" name="img_' . e($list . '_' . $k) . '" accept="image/jpeg,image/png,image/webp">'
                    . '<div class="hint">JPG، PNG یا WebP تا ' . fa_num($maxKb) . ' کیلوبایت</div>' . $err('img_' . $list . '_' . $k) . '</div>',
                default => $text([$list, $k, $field], (string) $v, $label, $kind),
            };
        }
        $html .= '</div><div class="hp-row-foot">'
            . '<label class="hp-order">ترتیب <input class="input narrow" name="' . e($n($list, $k, '_order')) . '" value="' . e(fa_num($i + 1)) . '" inputmode="numeric" aria-label="ترتیب"></label>'
            . ($isNew ? '<span class="hint">برای افزودن، این ردیف را پر کنید.</span>' : $check([$list, $k, '_delete'], false, 'حذف این ردیف'))
            . '</div></fieldset>';
    }
    return $html . '</div>' . ($count + $blank > $max ? '' : '') . '<p class="hint">حداکثر ' . fa_num($max) . ' ردیف. برای ردیف‌های بیشتر، ذخیره کنید تا ردیف خالی تازه اضافه شود.</p>';
};
$h = $home['hero'];
?>
<link rel="stylesheet" href="<?= e(asset('admin-home.css')) ?>">
<div class="mailbox">
  <?= $this->partial('admin/_nav', ['perms' => $perms, 'active' => 'home']) ?>
  <div class="mail-content stack hp">
    <div class="panel hp-intro">
      <div>
        <h2>صفحه اصلی سایت</h2>
        <p class="muted">همه متن‌ها، دکمه‌ها، پیوندها، کارت‌ها، تصاویر و بخش‌های صفحه اصلی از اینجا تغییر می‌کند. پس از ذخیره، حداکثر یک دقیقه طول می‌کشد تا برای همه نمایش داده شود. نمایش آمار واقعی از «تنظیمات» روشن یا خاموش می‌شود.</p>
      </div>
      <a class="btn btn-ghost btn-sm" href="/" target="_blank" rel="noopener">مشاهده صفحه اصلی</a>
    </div>

<form class="stack" method="post" action="/admin/home" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <?php if ($errors): ?><div class="alert alert-error" role="alert">برخی تصاویر بارگذاری نشد. موارد قرمز را بررسی کنید؛ تغییرات دیگر هنوز ذخیره نشده‌اند.</div><?php endif; ?>

  <details class="panel form hp-sec" open>
    <summary><h2>نمایش بخش‌ها</h2><span class="muted">هر بخش را می‌توانید پنهان یا آشکار کنید.</span></summary>
    <div class="hp-switches">
      <?php foreach (HomeContent::SECTIONS as $key => $label): ?>
      <label class="switch"><input type="checkbox" name="<?= e($n('show', $key)) ?>" value="1" role="switch"<?= !empty($home['show'][$key]) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>سربرگ و منوی اصلی</h2><span class="muted">پیوندهای منو، متن جستجو و دکمه‌های ورود و ثبت‌نام</span></summary>
    <div class="settings-grid">
      <?= $text(['header', 'search_placeholder'], $home['header']['search_placeholder'], 'متن راهنمای جستجو') ?>
      <?= $text(['header', 'login_label'], $home['header']['login_label'], 'متن دکمه ورود') ?>
      <?= $text(['header', 'register_label'], $home['header']['register_label'], 'متن دکمه ثبت‌نام') ?>
    </div>
    <h3>پیوندهای منو</h3>
    <p class="hint">پنج پیوند اول همیشه دیده می‌شوند؛ بقیه در صفحه‌های عریض و در منوی موبایل.</p>
    <?= $rows('nav', $home['nav'], ['label' => ['عنوان', 'text'], 'href' => ['پیوند', 'href']]) ?>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>بخش اول (کره زمین)</h2><span class="muted">تیتر، توضیح، دکمه‌ها و ستون آمار کنار کره</span></summary>
    <div class="settings-grid">
      <?= $text(['hero', 'badge'], $h['badge'], 'نشان طلایی بالای تیتر') ?>
      <?= $text(['hero', 'title'], $h['title'], 'تیتر اصلی (طلایی)') ?>
      <?= $text(['hero', 'subtitle'], $h['subtitle'], 'تیتر دوم (سفید)') ?>
    </div>
    <?= $text(['hero', 'lead'], $h['lead'], 'توضیح', 'long') ?>
    <div class="settings-grid">
      <?= $text(['hero', 'cta1_label'], $h['cta1_label'], 'دکمه طلایی — متن') ?>
      <?= $text(['hero', 'cta1_href'], $h['cta1_href'], 'دکمه طلایی — پیوند', 'href') ?>
      <?= $text(['hero', 'cta2_label'], $h['cta2_label'], 'دکمه دوم — متن') ?>
      <?= $text(['hero', 'cta2_href'], $h['cta2_href'], 'دکمه دوم — پیوند', 'href') ?>
    </div>
    <div class="settings-grid">
      <?= $text(['hero', 'note'], $h['note'], 'متن کوچک زیر دکمه‌ها') ?>
      <?= $text(['hero', 'hint'], $h['hint'], 'راهنمای پایین کره') ?>
    </div>
    <h3>ستون آمار کنار کره (فقط صفحه‌های عریض)</h3>
    <p class="hint">عددها ثابت‌اند و فقط از همین‌جا تغییر می‌کنند. مقدارهای پیش‌فرض از «چشم‌انداز تجارت جهانی» مارس ۲۰۲۶ سازمان تجارت جهانی (WTO) و «بررسی حمل‌ونقل دریایی ۲۰۲۵» آنکتاد (UNCTAD) است؛ با انتشار گزارش سال بعد می‌توانید به‌روزشان کنید.</p>
    <?= $rows('rail', $home['rail'], ['icon' => ['نماد', 'icon'], 'label' => ['عنوان', 'text'], 'value' => ['مقدار', 'text']], 1) ?>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>نوار آمار</h2><span class="muted">آمار واقعی یا مقادیر ثابت</span></summary>
    <h3>وقتی «نمایش آمار عمومی» روشن است (اعداد واقعی سامانه)</h3>
    <p class="hint">«حداقل نمایش» اختیاری است: تا وقتی عدد واقعی کمتر از آن باشد، همین حداقل نمایش داده می‌شود و پس از آن عدد واقعی. برای سایت تازه‌راه‌اندازی‌شده مفید است.</p>
    <?= $rows('stats_live', $home['stats_live'], ['metric' => ['آمار', 'metric'], 'icon' => ['نماد', 'icon'], 'label' => ['عنوان', 'text'], 'min' => ['حداقل نمایش', 'num']], 0) ?>
    <h3>وقتی آمار عمومی خاموش است</h3>
    <p class="hint">«منبع عدد» تعیین می‌کند عدد از کجا بیاید: از داده‌های واقعی سامانه (مثلاً تعداد کشورهای قابل انتخاب، زبان‌های صفحه تجاری یا انواع فرصت تجاری، که خودکار به‌روز می‌شوند) یا «دستی» که همان «مقدار» نوشته‌شده نمایش داده می‌شود. با «حداقل نمایش»، تا وقتی عدد واقعی کمتر است همان حداقل نمایش داده می‌شود. اگر مقدار عدد باشد با شمارنده متحرک نمایش داده می‌شود؛ در حالت دستی می‌توانید متن هم بنویسید (مثلاً «رایگان»). برای علامت + جلوی عدد، مقدار را با + شروع کنید.</p>
    <?= $rows('stats_static', $home['stats_static'], ['icon' => ['نماد', 'icon'], 'source' => ['منبع عدد', 'source'], 'value' => ['مقدار (حالت دستی)', 'text'], 'min' => ['حداقل نمایش', 'num'], 'label' => ['عنوان', 'text']], 0) ?>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>کشورها و بازارهای هدف</h2><span class="muted">کارت‌های روی کره و کارت‌های بخش بازار</span></summary>
    <div class="settings-grid">
      <?= $text(['finder', 'title'], $home['finder']['title'], 'عنوان جستجوی بازار') ?>
      <?= $text(['finder', 'button'], $home['finder']['button'], 'متن دکمه جستجو') ?>
    </div>
    <?= $text(['finder', 'text'], $home['finder']['text'], 'توضیح جستجوی بازار', 'long') ?>
    <h3>بازارها</h3>
    <p class="hint">کد کشور دوحرفی استاندارد است (مثل CN، IN، AE). پیوند هر کارت خودکار به صفحه تجار همان کشور می‌رود. عرض و طول جغرافیایی جای کارت روی کره را تعیین می‌کند.</p>
    <?= $rows('markets', $home['markets'], [
        'code' => ['کد کشور', 'ltr'], 'name' => ['نام', 'text'], 'lat' => ['عرض جغرافیایی', 'num'], 'lon' => ['طول جغرافیایی', 'num'],
        'port' => ['بندر اصلی', 'text'], 'currency' => ['واحد پول', 'text'], 'timezone' => ['منطقه زمانی', 'ltr'],
        'globe' => ['نمایش روی کره', 'bool'], 'card' => ['نمایش در بخش بازارها', 'bool'], 'secondary' => ['در موبایل پنهان شود', 'bool'],
        'image' => ['تصویر کارت (اختیاری)', 'image'],
    ]) ?>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>فرصت‌های تجاری</h2><span class="muted">میان‌برهای جستجوی محصول ← کشور</span></summary>
    <div class="settings-grid">
      <?= $text(['opps_head', 'title'], $home['opps_head']['title'], 'عنوان') ?>
      <?= $text(['opps_head', 'subtitle'], $home['opps_head']['subtitle'], 'زیرعنوان') ?>
      <?= $text(['opps_head', 'link_label'], $home['opps_head']['link_label'], 'متن پیوند «مشاهده همه»') ?>
      <?= $text(['opps_head', 'link_href'], $home['opps_head']['link_href'], 'پیوند «مشاهده همه»', 'href') ?>
    </div>
    <p class="hint">هر ردیف به جستجوی همان محصول در پیشنهادهای منتشرشده کشور انتخاب‌شده می‌رود.</p>
    <?= $rows('opps', $home['opps'], [
        'product' => ['محصول', 'text'], 'market' => ['نام کشور مقصد', 'text'], 'code' => ['کد کشور', 'ltr'], 'tag' => ['برچسب', 'text'],
        'mode' => ['نوع حمل', 'mode'], 'art' => ['طرح پیش‌فرض', 'art'], 'image' => ['تصویر (اختیاری)', 'image'],
    ]) ?>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>بخش‌های سامانه و بنر</h2><span class="muted">کارت‌های میان‌بر و بنر تجارت بین‌المللی</span></summary>
    <?= $rows('modules', $home['modules'], ['icon' => ['نماد', 'icon'], 'title' => ['عنوان', 'text'], 'text' => ['توضیح', 'text'], 'href' => ['پیوند', 'href']]) ?>
    <h3>بنر</h3>
    <div class="settings-grid">
      <?= $text(['banner', 'title'], $home['banner']['title'], 'عنوان بنر') ?>
      <?= $text(['banner', 'subtitle'], $home['banner']['subtitle'], 'زیرعنوان') ?>
      <?= $text(['banner', 'href'], $home['banner']['href'], 'پیوند', 'href') ?>
    </div>
    <div class="field hp-image">
      <label for="img_banner">تصویر بنر (اختیاری، افقی)</label>
      <?php if ($home['banner']['image'] !== ''): ?><img src="<?= e(media($home['banner']['image'])) ?>" alt="" loading="lazy"><?= $check(['banner', '_remove_image'], false, 'حذف تصویر') ?><?php else: ?><span class="hint">بدون تصویر؛ طرح کشتی کانتینری نمایش داده می‌شود.</span><?php endif; ?>
      <input type="hidden" name="<?= e($n('banner', 'image')) ?>" value="<?= e($home['banner']['image']) ?>">
      <input class="input" type="file" id="img_banner" name="img_banner" accept="image/jpeg,image/png,image/webp">
      <div class="hint">JPG، PNG یا WebP تا <?= e(fa_num($maxKb)) ?> کیلوبایت (به ۱۶۰۰×۶۰۰ تبدیل می‌شود)</div><?= $err('img_banner') ?>
    </div>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>چطور کار می‌کند و امکانات</h2><span class="muted">مراحل شروع و کارت‌های امکانات</span></summary>
    <div class="settings-grid">
      <?= $text(['how', 'kicker'], $home['how']['kicker'], 'برچسب بالای عنوان') ?>
      <?= $text(['how', 'title'], $home['how']['title'], 'عنوان') ?>
    </div>
    <?= $text(['how', 'intro'], $home['how']['intro'], 'توضیح', 'long') ?>
    <h3>مراحل</h3>
    <?= $rows('steps', $home['steps'], ['title' => ['عنوان', 'text'], 'text' => ['توضیح', 'text']], 1) ?>
    <h3>امکانات</h3>
    <div class="settings-grid">
      <?= $text(['features_head', 'kicker'], $home['features_head']['kicker'], 'برچسب بالای عنوان') ?>
      <?= $text(['features_head', 'title'], $home['features_head']['title'], 'عنوان') ?>
    </div>
    <?= $rows('features', $home['features'], ['icon' => ['نماد', 'icon'], 'title' => ['عنوان', 'text'], 'text' => ['توضیح', 'long']], 1) ?>
  </details>

  <details class="panel form hp-sec">
    <summary><h2>Stars، کشورها، پرسش‌ها و دعوت پایانی</h2><span class="muted">بخش‌های پایین صفحه</span></summary>
    <h3>Stars</h3>
    <div class="settings-grid">
      <?= $text(['stars', 'kicker'], $home['stars']['kicker'], 'برچسب بالای عنوان') ?>
      <?= $text(['stars', 'title'], $home['stars']['title'], 'عنوان') ?>
    </div>
    <?= $text(['stars', 'text'], $home['stars']['text'], 'توضیح', 'long', '{paid} با فهرست به‌روز قابلیت‌های پولی (بر اساس تنظیمات) جایگزین می‌شود.') ?>
    <?= $rows('stars_items', $home['stars_items'], ['text' => ['مورد', 'text']], 1) ?>
    <h3>کشورها</h3>
    <div class="settings-grid">
      <?= $text(['countries', 'kicker'], $home['countries']['kicker'], 'برچسب بالای عنوان') ?>
      <?= $text(['countries', 'title'], $home['countries']['title'], 'عنوان') ?>
      <?= $text(['countries', 'more'], $home['countries']['more'], 'متن آخرین برچسب') ?>
    </div>
    <?= $text(['countries', 'codes'], $home['countries']['codes'], 'کد کشورها (با کاما جدا کنید)', 'ltr', 'مثلاً IR,AE,TR,CN — نام فارسی و پیوند هر کشور خودکار ساخته می‌شود.') ?>
    <h3>پرسش‌های پرتکرار</h3>
    <div class="settings-grid">
      <?= $text(['faq_head', 'kicker'], $home['faq_head']['kicker'], 'برچسب بالای عنوان') ?>
      <?= $text(['faq_head', 'title'], $home['faq_head']['title'], 'عنوان') ?>
    </div>
    <p class="hint">در پاسخ‌ها، {paid} با فهرست به‌روز قابلیت‌های پولی جایگزین می‌شود تا با تنظیمات قیمت همیشه هم‌خوان باشد.</p>
    <?= $rows('faq', $home['faq'], ['q' => ['پرسش', 'text'], 'a' => ['پاسخ', 'long']], 1) ?>
    <h3>دعوت پایانی</h3>
    <div class="settings-grid">
      <?= $text(['final', 'title'], $home['final']['title'], 'عنوان') ?>
      <?= $text(['final', 'text'], $home['final']['text'], 'توضیح') ?>
      <?= $text(['final', 'cta1_label'], $home['final']['cta1_label'], 'دکمه اول — متن') ?>
      <?= $text(['final', 'cta1_href'], $home['final']['cta1_href'], 'دکمه اول — پیوند', 'href') ?>
      <?= $text(['final', 'cta2_label'], $home['final']['cta2_label'], 'دکمه دوم — متن') ?>
      <?= $text(['final', 'cta2_href'], $home['final']['cta2_href'], 'دکمه دوم — پیوند', 'href') ?>
    </div>
  </details>

  <div class="form-actions sticky-actions"><button class="btn" type="submit">ذخیره صفحه اصلی</button></div>
</form>

<form class="panel hp-reset" method="post" action="/admin/home/reset" data-confirm="همه متن‌ها، فهرست‌ها و تصاویر صفحه اصلی به حالت پیش‌فرض برمی‌گردد. ادامه می‌دهید؟">
  <?= csrf_field() ?>
  <div><h2>بازگشت به حالت پیش‌فرض</h2><p class="muted">همه تغییرات صفحه اصلی پاک می‌شود و محتوای اولیه برمی‌گردد.</p></div>
  <button class="btn btn-danger btn-sm" type="submit">بازگشت به پیش‌فرض</button>
</form>
  </div>
</div>
