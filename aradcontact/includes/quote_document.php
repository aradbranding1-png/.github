<?php
/**
 * نمایشِ چاپیِ مشترکِ «پیش‌فاکتور / فاکتور فروش / شرح خدمات».
 * هر سه سند دقیقاً از یک طراحی (لوگو، سربرگ، رنگ‌ها، فونت، حاشیه‌ها و تنظیماتِ چاپ از
 * «تنظیمات فاکتور») استفاده می‌کنند؛ قالبِ جداگانه‌ای برای شرح خدمات ساخته نشده است.
 *
 * $quote: ردیفِ پیش‌فاکتور + customer_name, customer_mobile
 * $items: ردیف‌های quote_items (title_snapshot, unit_snapshot, unit_price_snapshot, quantity, amount, description_snapshot)
 * $o:     mode = quote|invoice|services، order (برای فاکتور فروش)، issuer_name، back_url، back_label،
 *         public (نسخه‌ی مشتری)، paid_amount، note
 */
function quote_doc_titles(string $mode): array
{
    return [
        'quote'    => ['title' => 'پیش‌فاکتور', 'file' => 'پیش‌فاکتور'],
        'invoice'  => ['title' => 'فاکتور فروش', 'file' => 'فاکتور'],
        'services' => ['title' => 'شرح خدمات', 'file' => 'شرح-خدمات'],
    ][$mode] ?? ['title' => 'پیش‌فاکتور', 'file' => 'پیش‌فاکتور'];
}

function quote_doc_qty(array $it): string
{
    $q = (float) $it['quantity'];
    $qStr = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
    return to_persian_digits($qStr) . ' ' . (string) ($it['unit_snapshot'] ?? '');
}

function quote_doc_render(array $inv, array $quote, array $items, array $o = []): void
{
    $mode = $o['mode'] ?? 'quote';
    $clr = $inv['colors'];
    $order = $o['order'] ?? null;
    $t = quote_doc_titles($mode);
    // شرحِ خدمات هم وقتی فاکتور داده شود، پیوستِ همان فاکتور است (شماره و تاریخِ فاکتور)
    $byInvoice = in_array($mode, ['invoice', 'services'], true) && $order;
    $docNumber = $byInvoice ? (string) $order['order_number'] : (string) $quote['quote_number'];
    $docDate = $byInvoice
        ? to_jalali(date('Y-m-d', strtotime((string) ($order['decided_at'] ?? $order['created_at']))))
        : to_jalali(date('Y-m-d', strtotime((string) ($quote['locked_at'] ?? $quote['created_at']))));
    $fileTitle = $t['file'] . '-' . preg_replace('/[^0-9A-Za-z\-]/', '', $docNumber);
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($fileTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Vazirmatn', Tahoma, sans-serif; color: <?= e($clr['text_color']) ?>; margin: 0; padding: 28px; background: <?= e($clr['row_stripe']) ?>; direction: rtl; }
  .sheet { max-width: 860px; margin: 0 auto; background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(30, 58, 138, 0.08); overflow: hidden; }
  .accent-bar { height: 8px; background: linear-gradient(90deg, <?= e($clr['accent_start']) ?>, <?= e($clr['accent_mid']) ?>, <?= e($clr['accent_end']) ?>); }
  .header { display: flex; justify-content: space-between; align-items: center; padding: 28px 32px 20px; border-bottom: 2px solid <?= e($clr['border_color']) ?>; }
  .header .logo-wrap { flex-shrink: 0; }
  .header img.logo { max-height: 72px; }
  .header .titles { text-align: left; }
  .header .titles h1 { font-family: 'Vazirmatn', Tahoma, sans-serif; font-weight: 800; font-size: 26px; margin: 0 0 8px; color: <?= e($clr['title_color']) ?>; letter-spacing: 1px; }
  .header .titles div { font-size: 13.5px; color: <?= e($clr['muted_color']) ?>; line-height: 2; font-weight: 500; }
  .header .titles b { color: <?= e($clr['text_color']) ?>; font-weight: 700; }
  .meta { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px; padding: 20px 32px; background: <?= e($clr['row_stripe']) ?>; }
  .meta .box { font-size: 13.5px; line-height: 2.1; }
  .meta .box b.label { color: <?= e($clr['label_color']) ?>; font-weight: 600; }
  .meta .company { text-align: left; color: <?= e($clr['muted_color']) ?>; }
  .meta .company .company-name { font-weight: 700; color: <?= e($clr['text_color']) ?>; font-size: 15px; }
  .meta .customer { text-align: right; }
  .items-wrap { padding: 24px 32px 8px; }
  table.items { width: 100%; border-collapse: collapse; }
  table.items th { background: <?= e($clr['table_header_bg']) ?>; color: <?= e($clr['table_header_text']) ?>; font-weight: 600; padding: 11px 8px; font-size: 13px; text-align: center; }
  table.items th:first-child { border-radius: 0 10px 10px 0; }
  table.items th:last-child { border-radius: 10px 0 0 10px; }
  table.items td { padding: 10px 8px; font-size: 13px; text-align: center; border-bottom: 1px solid <?= e($clr['border_color']) ?>; }
  table.items tr:nth-child(even) td { background: <?= e($clr['row_stripe']) ?>; }
  .financial-wrap { padding: 20px 32px 28px; display: flex; justify-content: flex-end; }
  table.financial-table { width: 340px; border-collapse: collapse; font-size: 13.5px; }
  table.financial-table td { padding: 7px 4px; }
  table.financial-table td:first-child { text-align: right; color: <?= e($clr['muted_color']) ?>; }
  table.financial-table td:last-child { text-align: left; font-weight: 600; color: <?= e($clr['text_color']) ?>; }
  table.financial-table tr.total td { border-top: 2px solid <?= e($clr['total_color']) ?>; padding-top: 12px; font-size: 17px; font-weight: 800; color: <?= e($clr['total_color']) ?>; }
  /* شرح خدمات — با همان رنگ‌ها و فاصله‌های جدولِ پیش‌فاکتور */
  .svc-wrap { padding: 24px 32px 28px; }
  .svc { border: 1px solid <?= e($clr['border_color']) ?>; border-radius: 12px; margin-bottom: 16px; overflow: hidden; page-break-inside: avoid; break-inside: avoid; }
  .svc-h { display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: <?= e($clr['table_header_bg']) ?>; color: <?= e($clr['table_header_text']) ?>; }
  .svc-h .num { width: 26px; height: 26px; border-radius: 50%; background: rgba(255,255,255,.22); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; }
  .svc-h .ttl { font-weight: 700; font-size: 14.5px; flex: 1; }
  .svc-h .qty { font-size: 12.5px; background: rgba(255,255,255,.18); border-radius: 20px; padding: 3px 12px; white-space: nowrap; }
  .svc-d { padding: 14px 16px; font-size: 13.5px; line-height: 2.1; text-align: justify; }
  .svc-d.empty { color: <?= e($clr['muted_color']) ?>; font-style: italic; }
  .svc-note { font-size: 12px; color: <?= e($clr['muted_color']) ?>; padding: 0 32px 24px; line-height: 2; }
  .letterhead { width: 100%; display: block; }
  /* جای امضا (پیش‌فاکتور و فاکتور) */
  .sign-wrap { display: flex; gap: 24px; padding: 8px 32px 30px; page-break-inside: avoid; break-inside: avoid; }
  .sign-box { flex: 1; border: 1.5px dashed <?= e($clr['border_color']) ?>; border-radius: 12px; min-height: 120px; padding: 12px 14px; display: flex; flex-direction: column; }
  .sign-box .t { font-weight: 700; font-size: 13.5px; color: <?= e($clr['text_color']) ?>; }
  .sign-box .s { margin-top: auto; font-size: 11.5px; color: <?= e($clr['muted_color']) ?>; border-top: 1px solid <?= e($clr['border_color']) ?>; padding-top: 6px; }
  .no-print { text-align: center; margin-bottom: 16px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
  .no-print button, .no-print a { font-family: 'Vazirmatn', Tahoma, sans-serif; padding: 10px 22px; background: <?= e($clr['print_button_bg']) ?>; color: <?= e($clr['print_button_text']) ?>; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; }
  .no-print a.back { background: #fff; color: <?= e($clr['text_color']) ?>; border: 1px solid <?= e($clr['border_color']) ?>; }
  @media (max-width: 640px) {
    body { padding: 10px; }
    .header, .meta, .items-wrap, .financial-wrap, .svc-wrap { padding-left: 14px; padding-right: 14px; }
    .items-wrap { overflow-x: auto; }
    table.financial-table { width: 100%; }
  }
  @media print {
    .no-print { display: none; }
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; border-radius: 0; max-width: 100%; }
  }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
</style>
</head>
<body>

<div class="no-print">
  <?php if (!empty($o['back_url'])): ?><a class="back" href="<?= e($o['back_url']) ?>">→ <?= e($o['back_label'] ?? 'برگشت') ?></a><?php endif; ?>
  <button onclick="window.print()"><?= !empty($o['public']) ? 'دانلود / ذخیره PDF' : 'چاپ / ذخیره PDF' ?></button>
  <button type="button" id="saveJpg" data-name="<?= e($fileTitle) ?>">ذخیره به‌صورتِ عکس (JPG)</button>
</div>
<script>
// خروجیِ عکسِ JPG از همین برگه (بدونِ دکمه‌ها)
(function () {
  var btn = document.getElementById('saveJpg');
  if (!btn) return;
  function load(cb) {
    if (window.html2canvas) return cb();
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    s.onload = cb;
    s.onerror = function () { btn.disabled = false; btn.textContent = 'ذخیره به‌صورتِ عکس (JPG)'; alert('ابزارِ ساختِ عکس بارگذاری نشد؛ اتصالِ اینترنت را بررسی کنید.'); };
    document.head.appendChild(s);
  }
  btn.addEventListener('click', function () {
    btn.disabled = true; btn.textContent = 'در حالِ ساختِ عکس…';
    load(function () {
      var el = document.querySelector('.sheet');
      // فاصله‌ی حروف، حروفِ فارسی را در عکس جدا می‌کند
      var fixed = [];
      el.querySelectorAll('*').forEach(function (n) {
        var ls = getComputedStyle(n).letterSpacing;
        if (ls && ls !== 'normal' && ls !== '0px') { fixed.push([n, n.style.letterSpacing]); n.style.letterSpacing = 'normal'; }
      });
      var done = function () { fixed.forEach(function (f) { f[0].style.letterSpacing = f[1]; }); btn.disabled = false; btn.textContent = 'ذخیره به‌صورتِ عکس (JPG)'; };
      (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () {
        return window.html2canvas(el, { scale: 2, useCORS: true, backgroundColor: '#ffffff', scrollX: 0, scrollY: -window.scrollY });
      }).then(function (canvas) {
        return new Promise(function (ok) { canvas.toBlob(ok, 'image/jpeg', 0.92); });
      }).then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.download = (btn.getAttribute('data-name') || 'document') + '.jpg';
        a.href = url;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        done();
      }).catch(function (e) { done(); alert('ساختِ عکس ناموفق بود: ' + (e && e.message ? e.message : e)); });
    });
  });
})();
</script>

<div class="sheet">
  <div class="accent-bar"></div>
  <?php if (!empty($inv['letterhead_path'])): ?>
    <img src="<?= e(($o['asset_base'] ?? '') . $inv['letterhead_path']) ?>" class="letterhead" alt="سربرگ">
  <?php endif; ?>
  <div class="header">
    <div class="logo-wrap">
      <?php if (!empty($inv['logo_path'])): ?>
        <img src="<?= e(($o['asset_base'] ?? '') . $inv['logo_path']) ?>" class="logo" alt="لوگو">
      <?php else: ?>
        <div style="font-weight:800; font-size:18px; color:<?= e($clr['title_color']) ?>;"><?= e($inv['company_name'] ?? 'آراد برندینگ') ?></div>
      <?php endif; ?>
    </div>
    <div class="titles">
      <h1><?= e($t['title']) ?></h1>
      <?php if ($mode === 'invoice' && $order): ?>
        <div>شماره فاکتور: <b><?= to_persian_digits($docNumber) ?></b></div>
        <div>تاریخ: <b><?= $docDate ?></b></div>
        <?php if (function_exists('orders_statuses')): ?><div>وضعیت: <b><?= e(orders_statuses()[$order['status']]['label'] ?? $order['status']) ?></b></div><?php endif; ?>
      <?php elseif ($mode === 'services'): ?>
        <div><?= $byInvoice ? 'پیوستِ فاکتور شماره' : 'پیوستِ پیش‌فاکتور شماره' ?>: <b><?= to_persian_digits($docNumber) ?></b></div>
        <div>تاریخ: <b><?= $docDate ?></b></div>
      <?php else: ?>
        <div>شماره: <b><?= to_persian_digits($docNumber) ?></b></div>
        <div>تاریخ: <b><?= $docDate ?></b></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="meta">
    <div class="box customer">
      <div><b class="label">کارفرما:</b> <?= e((string) $quote['customer_name']) ?></div>
      <div><b class="label">شماره تماس:</b> <span dir="ltr"><?= e((string) $quote['customer_mobile']) ?></span></div>
      <?php if (!empty($o['issuer_name'])): ?><div><b class="label">صادرکننده:</b> <?= e((string) $o['issuer_name']) ?></div><?php endif; ?>
    </div>
    <div class="box company">
      <div class="company-name"><?= e($inv['company_name'] ?? 'آراد برندینگ') ?></div>
      <div><?= nl2br(e($inv['company_details'] ?? '')) ?></div>
    </div>
  </div>

  <?php if ($mode === 'services'): ?>
    <div class="svc-wrap">
      <?php foreach ($items as $i => $it): $desc = trim((string) ($it['description_snapshot'] ?? '')); ?>
        <div class="svc">
          <div class="svc-h">
            <span class="num"><?= to_persian_digits((string) ($i + 1)) ?></span>
            <span class="ttl"><?= e((string) $it['title_snapshot']) ?></span>
            <span class="qty">مقدار: <?= e(quote_doc_qty($it)) ?></span>
          </div>
          <?php if ($desc !== ''): ?>
            <div class="svc-d"><?= nl2br(e($desc)) ?></div>
          <?php else: ?>
            <div class="svc-d empty">برای این خدمت شرحی ثبت نشده است.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="svc-note">تعداد، مقدار و دفعاتِ ارائه‌ی هر خدمت، همان است که در <?= $byInvoice ? 'فاکتور' : 'پیش‌فاکتور' ?> شماره‌ی <?= to_persian_digits($docNumber) ?> درج شده است.</div>
  <?php else: ?>
    <div class="items-wrap">
      <table class="items">
        <thead><tr><th>ردیف</th><th>عنوان خدمت</th><th>واحد</th><th>مقدار</th><th>قیمت واحد (تومان)</th><th>مبلغ (تومان)</th></tr></thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
            <tr>
              <td><?= to_persian_digits((string) ($i + 1)) ?></td>
              <td><?= e((string) $it['title_snapshot']) ?></td>
              <td><?= e((string) $it['unit_snapshot']) ?></td>
              <td><?= to_persian_digits((string) (float) $it['quantity']) ?></td>
              <td><?= format_toman_number((int) $it['unit_price_snapshot']) ?></td>
              <td><?= format_toman_number((int) $it['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="financial-wrap">
      <table class="financial-table">
        <tr><td>مجموع خدمات</td><td><?= format_toman((int) $quote['subtotal']) ?></td></tr>
        <?php // تخفیف و خدماتِ رایگان فقط وقتی ثبت شده باشند نمایش داده می‌شوند ?>
        <?php if ((int) $quote['discount_amount'] > 0): ?><tr><td>مبلغ تخفیف (<?= to_persian_digits((string) (float) $quote['discount_percent']) ?>٪)</td><td>- <?= format_toman((int) $quote['discount_amount']) ?></td></tr><?php endif; ?>
        <?php if ((int) $quote['free_amount'] > 0): ?><tr><td>مبلغ خدماتِ رایگان</td><td>- <?= format_toman((int) $quote['free_amount']) ?></td></tr><?php endif; ?>
        <tr><td>مبلغ قبل از مالیات</td><td><?= format_toman((int) $quote['taxable_amount']) ?></td></tr>
        <tr><td>مبلغ مالیات (<?= to_persian_digits((string) (float) $quote['tax_percent']) ?> درصد)</td><td>+ <?= format_toman((int) $quote['tax_amount']) ?></td></tr>
        <tr class="total"><td>مبلغ نهایی</td><td><?= format_toman((int) $quote['total_amount']) ?></td></tr>
        <?php if ($mode === 'invoice' && $order): ?>
          <tr><td>مبلغ پرداخت‌شده</td><td><?= format_toman((int) ($order['confirmed_amount'] ?? $order['paid_amount'])) ?></td></tr>
          <?php if (!empty($order['payment_ref'])): ?><tr><td>شماره پیگیری پرداخت</td><td dir="ltr"><?= e((string) $order['payment_ref']) ?></td></tr><?php endif; ?>
        <?php endif; ?>
      </table>
    </div>
    <div class="sign-wrap">
      <div class="sign-box"><div class="t">امضای کارفرما</div><div class="s">نام و نام خانوادگی / مهر و امضا</div></div>
      <div class="sign-box"><div class="t">امضای شرکت</div><div class="s">مهر و امضای آراد برندینگ</div></div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
<?php
}
