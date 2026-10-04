<?php foreach (['countries', 'languages', 'categories'] as $__ref) { if (isset($$__ref) && is_array($$__ref)) { $$__ref = localized_rows($$__ref, 'name_fa', $__ref !== 'categories'); } } unset($__ref); ?>
<?php
/** @var array $user @var array $pages @var array $rules @var array $countries @var array $languages @var array $errors */
$baseUrl = rtrim((string) \App\Core\Env::get('APP_URL', ''), '/');
$langById = $languages;
$usedIds = array_map('intval', array_column($pages, 'language_id'));
?>
<div class="stack">
  <?php if ($user['handle']): ?>
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2><?= te('نشانی عمومی شما') ?></h2>
          <p class="muted"><?= te('این نشانی را روی کارت ویزیت، ایمیل و شبکه‌های اجتماعی بگذارید. هر بازدیدکننده صفحه مناسب زبان خودش را می‌بیند.') ?></p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/p/<?= e($user['handle']) ?>" target="_blank" rel="noopener"><svg class="icon"><use href="#i-link"/></svg><?= te('مشاهده') ?></a>
      </div>
      <div class="ltr"><b><?= e(preg_replace('~^https?://~', '', $baseUrl)) ?>/p/<?= e($user['handle']) ?></b></div>
    </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <h2><?= te('صفحه‌ها') ?></h2>
      <a class="btn btn-sm" href="/pages/new"><svg class="icon"><use href="#i-plus"/></svg><?= te('صفحه به زبان جدید') ?></a>
    </div>
    <?php if ($pages === []): ?>
      <div class="empty">
        <svg class="icon"><use href="#i-page"/></svg>
        <h3><?= te('هنوز صفحه‌ای نساخته‌اید') ?></h3>
        <p><?= te('برای هر زبان یک صفحه بسازید. اولین صفحه، صفحه پیش‌فرض شما خواهد بود.') ?></p>
        <a class="btn" href="/pages/new"><?= te('ساخت اولین صفحه') ?></a>
      </div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($pages as $p): $st = (int) $p['status']; $on = $st === 2; ?>
          <li class="list-row page-row">
            <div class="page-row-main">
              <span class="lang-badge"><?= e($p['lang_code']) ?></span>
              <div class="grow">
                <div class="title"><?= e($p['title']) ?></div>
                <div class="meta">
                  <span><?= te($p['lang_name_fa']) ?></span>
                  <?php if ($st !== 0 && $st !== 2): ?><span class="chip chip-warn"><?= te('متوقف‌شده توسط مدیر') ?></span><?php endif; ?>
                  <?php if ($p['is_default']): ?><span class="chip chip-gold"><?= te('پیش‌فرض') ?></span><?php endif; ?>
                </div>
              </div>
            </div>
            <div class="page-row-actions">
              <?php if ($st === 0 || $st === 2): ?>
                <form class="inline-form" method="post" action="/pages/<?= e($p['uid']) ?>/toggle">
                  <?= csrf_field() ?>
                  <button class="onoff<?= $on ? ' is-on' : '' ?>" type="submit" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= $on ? t('غیرفعال‌کردن صفحه') : t('فعال‌کردن صفحه') ?>" title="<?= $on ? t('فعال — برای خاموش‌کردن بزنید') : t('غیرفعال — برای روشن‌کردن بزنید') ?>">
                    <span class="onoff-track" aria-hidden="true"><i></i></span><span class="onoff-label"><?= $on ? t('فعال') : t('غیرفعال') ?></span>
                  </button>
                </form>
              <?php endif; ?>
              <span class="page-row-end">
                <?php if (!$p['is_default']): ?>
                  <form class="inline-form" method="post" action="/pages/<?= e($p['uid']) ?>/default">
                    <?= csrf_field() ?><button class="btn btn-quiet btn-sm" type="submit"><?= te('پیش‌فرض شود') ?></button>
                  </form>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="/pages/<?= e($p['uid']) ?>/edit"><?= te('ویرایش') ?></a>
              </span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($pages !== []): ?>
  <section class="panel" id="routing">
    <div class="panel-head">
      <div>
        <h2><?= te('قوانین نمایش') ?></h2>
        <p class="muted"><?= te('تعیین کنید تاجر هر کشور و زبان کدام صفحه را ببیند. بدون قانون، هر تاجر صفحه هم‌زبان خودش را می‌بیند و اگر نبود، صفحه پیش‌فرض را.') ?></p>
      </div>
    </div>
    <?php if (isset($errors['rule'])): ?><div class="alert alert-error" role="alert"><?= e($errors['rule']) ?></div><?php endif; ?>

    <?php if ($rules !== []): ?>
      <ul class="list">
        <?php foreach ($rules as $r):
            $c = $r['country_id'] !== null ? ($countries[(int) $r['country_id']] ?? null) : null;
            $l = $r['language_id'] !== null ? ($langById[(int) $r['language_id']] ?? null) : null; ?>
          <li class="list-row">
            <svg class="icon"><use href="#i-route"/></svg>
            <div class="grow">
              <div class="title">
                <?= $c ? e(flag($c['code']) . ' ' . t($c['name_fa'])) : t('هر کشوری') ?>
                <?= te('،') ?>

                <?= $l ? te('زبان :lang', ['lang' => t($l['name_fa'])]) : t('هر زبانی') ?>
              </div>
              <div class="meta"><?= te('نمایش صفحه') ?> <b class="ltr"><?= e(strtoupper($r['target_lang'])) ?></b></div>
            </div>
            <form class="inline-form" method="post" action="/pages/routes/<?= e($r['id']) ?>/delete" data-confirm="<?= te('این قانون حذف شود؟') ?>">
              <?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit"><?= te('حذف') ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form class="form" method="post" action="/pages/routes">
      <?= csrf_field() ?>
      <div class="row row-2">
        <div class="field">
          <label for="r-country"><?= te('اگر کشور بازدیدکننده') ?></label>
          <select class="select" id="r-country" name="country_id">
            <option value=""><?= te('هر کشوری') ?></option>
            <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en'] ?? '') ?>"><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="r-lang"><?= te('و زبان بازدیدکننده') ?></label>
          <select class="select" id="r-lang" name="language_id">
            <option value=""><?= te('هر زبانی') ?></option>
            <?php foreach ($languages as $l): ?><option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"><?= e($l['name_fa']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="r-target"><?= te('این صفحه نمایش داده شود') ?></label>
        <select class="select" id="r-target" name="target" required>
          <?php foreach ($pages as $p): ?><option value="<?= e($p['uid']) ?>"><?= e(t($p['lang_name_fa']) . ' · ' . $p['title']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-actions"><button class="btn btn-ghost" type="submit"><?= te('افزودن قانون') ?></button></div>
    </form>
  </section>
  <?php endif; ?>
</div>
