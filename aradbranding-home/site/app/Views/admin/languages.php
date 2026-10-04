<?php
/**
 * زبان‌ها — interface languages and the wording of every string in each language.
 * @var array $locales @var string $locale @var string $filter @var string $q @var int $page @var int $pages @var int $total
 * @var array $rows @var array $stats @var array $enabled @var string $default @var bool $detect
 */
$qs = static fn (array $over): string => '/admin/languages?' . http_build_query(array_filter($over + ['locale' => $locale, 'show' => $filter, 'q' => $q],
    static fn ($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 1));
$dir = $locales[$locale]['dir'];
$pct = static fn (array $s): int => $s['total'] > 0 ? (int) floor($s['done'] * 100 / $s['total']) : 100;
?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'languages']) ?>
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>زبان‌های سامانه</h2>
      <p class="muted">صفحه اصلی، ورود و عضویت و پنل کاربران به این زبان‌ها نمایش داده می‌شوند. فارسی زبان اصلی است و همیشه روشن است؛ بخش مدیریت همیشه فارسی می‌ماند.</p>
    </div>
  </div>
  <form class="form" method="post" action="/admin/languages/settings">
    <?= csrf_field() ?>
    <div class="lang-admin-grid">
      <?php foreach (\App\Core\I18n\I18n::LOCALES as $code => $l): $s = $stats[$code] ?? null; ?>
        <label class="lang-admin-card<?= isset($enabled[$code]) ? ' is-on' : '' ?>">
          <span class="lang-admin-top">
            <input type="checkbox" name="enabled[]" value="<?= e($code) ?>"<?= isset($enabled[$code]) ? ' checked' : '' ?><?= $code === 'fa' ? ' disabled' : '' ?>>
            <b><?= e($l['fa']) ?></b>
            <span class="muted" lang="<?= e($code) ?>" dir="<?= e($l['dir']) ?>"><?= e($l['native']) ?></span>
            <span class="chip"><?= $l['dir'] === 'rtl' ? 'راست‌به‌چپ' : 'چپ‌به‌راست' ?></span>
          </span>
          <?php if ($s !== null): ?>
            <span class="lang-admin-bar"><i data-w="<?= $pct($s) ?>"></i></span>
            <small class="muted"><?= fa_int($s['done']) ?> از <?= fa_int($s['total']) ?> عبارت ترجمه شده (<?= fa_int($pct($s)) ?>٪)<?= $s['edited'] > 0 ? ' · ' . fa_int($s['edited']) . ' ویرایش دستی' : '' ?></small>
          <?php else: ?>
            <small class="muted">زبان اصلی متن‌ها</small>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="row row-2">
      <div class="field">
        <label for="lg-default">زبان پیش‌فرض برای کشورهای دیگر</label>
        <select class="select" id="lg-default" name="default">
          <?php foreach (\App\Core\I18n\I18n::LOCALES as $code => $l): ?><option value="<?= e($code) ?>"<?= $default === $code ? ' selected' : '' ?>><?= e($l['fa'] . ' · ' . $l['native']) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">برای بازدیدکننده‌ای که کشور و زبانش جزو زبان‌های روشن نیست (مثلاً از آلمان یا ژاپن).</div>
      </div>
      <div class="field">
        <span class="label">تشخیص خودکار زبان</span>
        <label class="check"><input type="checkbox" name="detect" value="1"<?= $detect ? ' checked' : '' ?>> زبان هر بازدیدکننده خودکار انتخاب شود</label>
        <div class="hint">ترتیب تشخیص: انتخاب خود کاربر ← زبان اصلی مرورگر ← منطقه زمانی دستگاه (حتی با VPN درست است؛ مثلاً ساعت ایران = فارسی) ← کشورِ IP ← پیش‌فرض. اگر خاموش باشد همه زبان پیش‌فرض را می‌بینند و فقط با انتخاب خودشان عوض می‌شود.</div>
      </div>
    </div>
    <div class="form-actions"><button class="btn" type="submit">ذخیره تنظیمات</button></div>
  </form>
  <p class="hint muted">کشورها: فارسی ← ایران و افغانستان · عربی ← کشورهای عرب‌زبان · ترکی ← ترکیه و جمهوری آذربایجان · روسی ← روسیه و کشورهای آسیای میانه، بلاروس، ارمنستان و مولداوی · فرانسوی ← فرانسه، بلژیک، لوکزامبورگ و کشورهای فرانسوی‌زبان آفریقا · بقیه ← زبان پیش‌فرض. داده کشورِ IP: ip-location-db (geo-whois-asn-country)، مجوز CC BY 4.0، منبع: <bdi>Number Resource Organization (nro.net)</bdi>.</p>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>ترجمه عبارت‌ها</h2>
      <p class="muted">همه متن‌هایی که کاربران می‌بینند. ترجمه آماده سامانه پیش‌فرض است؛ هر جا متن دیگری بنویسید، همان نمایش داده می‌شود. صفحه یا متن تازه‌ای که به سامانه اضافه شود، خودکار در این فهرست می‌آید و اگر ترجمه نداشته باشد در «بدون ترجمه» دیده می‌شود.</p>
    </div>
  </div>
  <nav class="seg-tabs" aria-label="زبان">
    <?php foreach ($locales as $code => $l): ?>
      <a href="<?= e($qs(['locale' => $code, 'page' => null])) ?>"<?= $locale === $code ? ' aria-current="page"' : '' ?>><?= e($l['fa']) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="lang-filter" method="get" action="/admin/languages">
    <input type="hidden" name="locale" value="<?= e($locale) ?>">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="جستجو در متن فارسی، ترجمه یا محل استفاده…" aria-label="جستجو">
    <select class="select" name="show" aria-label="نمایش">
      <option value="all"<?= $filter === 'all' ? ' selected' : '' ?>>همه عبارت‌ها</option>
      <option value="missing"<?= $filter === 'missing' ? ' selected' : '' ?>>بدون ترجمه</option>
      <option value="edited"<?= $filter === 'edited' ? ' selected' : '' ?>>ویرایش‌شده دستی</option>
    </select>
    <button class="btn btn-sm" type="submit">نمایش</button>
  </form>
  <p class="muted"><?= fa_int($total) ?> عبارت<?= $pages > 1 ? ' · صفحه ' . fa_int($page) . ' از ' . fa_int($pages) : '' ?></p>

  <?php if ($rows === []): ?>
    <div class="empty"><p><?= $filter === 'missing' ? 'همه عبارت‌ها ترجمه دارند.' : 'عبارتی با این جستجو پیدا نشد.' ?></p></div>
  <?php else: ?>
    <ul class="lang-rows">
      <?php foreach ($rows as $r): $id = substr($r['hash'], 0, 12); $current = $r['override'] ?? $r['shipped']; ?>
        <li class="lang-row<?= $r['missing'] ? ' is-missing' : '' ?>" id="row-<?= e($id) ?>">
          <div class="lang-src">
            <p><?= e($r['source']) ?></p>
            <small class="muted ltr"><?= e(implode(' · ', $r['refs'])) ?></small>
            <span class="lang-flags">
              <?php if ($r['missing']): ?><span class="chip chip-warn">بدون ترجمه</span><?php endif; ?>
              <?php if ($r['override'] !== null): ?><span class="chip chip-gold">ویرایش دستی</span><?php endif; ?>
              <?php if ($r['seen']): ?><span class="chip"><?= fa_int((int) $r['seen']['hits']) ?> بار نمایش بدون ترجمه</span><?php endif; ?>
            </span>
          </div>
          <form class="lang-edit" method="post" action="/admin/languages/translate">
            <?= csrf_field() ?>
            <input type="hidden" name="locale" value="<?= e($locale) ?>">
            <input type="hidden" name="source" value="<?= e($r['source']) ?>">
            <input type="hidden" name="hash" value="<?= e($r['hash']) ?>">
            <input type="hidden" name="show" value="<?= e($filter) ?>">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <input type="hidden" name="page" value="<?= (int) $page ?>">
            <textarea class="textarea" name="text" rows="<?= mb_strlen($r['source']) > 90 ? 4 : 2 ?>" lang="<?= e($locale) ?>" dir="<?= e($dir) ?>" aria-label="ترجمه" placeholder="ترجمه <?= e($locales[$locale]['fa']) ?>…"><?= e($current) ?></textarea>
            <?php if ($r['override'] !== null && $r['shipped'] !== ''): ?><small class="muted">ترجمه آماده سامانه: <bdi lang="<?= e($locale) ?>" dir="<?= e($dir) ?>"><?= e($r['shipped']) ?></bdi></small><?php endif; ?>
            <div class="form-actions">
              <button class="btn btn-sm" type="submit">ذخیره</button>
              <?php if ($r['override'] !== null): ?><button class="btn btn-quiet btn-sm" type="submit" formaction="/admin/languages/reset">بازگشت به ترجمه آماده</button><?php endif; ?>
            </div>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($pages > 1): ?>
      <nav class="form-actions form-actions-center" aria-label="صفحه‌ها">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page - 1])) ?>">صفحه قبل</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page + 1])) ?>">صفحه بعد</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="/admin/languages/misses/clear" class="form-actions">
    <?= csrf_field() ?>
    <button class="btn btn-quiet btn-sm" type="submit">پاک‌کردن عبارت‌های بی‌ترجمه‌ای که ۳۰ روز دیده نشده‌اند</button>
  </form>
  <p class="hint muted">در ترجمه، نشانه‌هایی مثل <code dir="ltr">:name</code>، <code dir="ltr">:n</code> و <code dir="ltr">{paid}</code> را نگه دارید؛ سامانه جای آن‌ها نام، عدد یا عبارت مناسب می‌گذارد. برای جمع و مفرد می‌توانید از قالب <code dir="ltr">{n, plural, one {# item} other {# items}}</code> استفاده کنید.</p>
</section>
<?= $this->partial('admin/_wrap_end') ?>
