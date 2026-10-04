<?php /** @var array $user @var array $rows @var bool $canWrite @var array $prefill */ ?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'updates', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
    <?php if ($canWrite): ?>
      <form class="panel form" method="post" action="/updates">
        <?= csrf_field() ?>
        <div><h2><?= te('یادداشت بروزرسانی جدید') ?></h2><p class="muted"><?= te('بنویسید در این بروزرسانی چه امکاناتی به سامانه اضافه شده است.') ?></p></div>
        <div class="row row-2">
          <div class="field"><label for="u-title"><?= te('عنوان') ?></label><input class="input" id="u-title" name="title" maxlength="150" required></div>
          <div class="field"><label for="u-version"><?= te('نسخه') ?> <span class="muted"><?= te('(اختیاری)') ?></span></label><input class="input" id="u-version" name="version" maxlength="40" dir="ltr" value="<?= e($prefill['version'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="u-body"><?= te('چه چیزهایی اضافه یا بهتر شد؟') ?></label><textarea class="textarea" id="u-body" name="body" rows="8" required><?= e($prefill['body'] ?? '') ?></textarea><div class="hint"><?= te('برای پررنگ‌کردن: **متن**') ?></div></div>
        <label class="check"><input type="checkbox" name="visible" value="1" checked> <?= te('در «تازه‌های سامانه» برای همه نمایش داده شود') ?></label>
        <label class="check"><input type="checkbox" name="announce" value="1"> <?= te('به‌صورت نامه رسمی آراد برندینگ هم برای همه کاربران ارسال شود (اطلاع‌رسانی عمومی)') ?></label>
        <div class="form-actions"><button class="btn" type="submit"><?= te('انتشار') ?></button></div>
      </form>
    <?php endif; ?>

    <?php if ($rows === []): ?>
      <section class="panel empty"><svg class="icon"><use href="#i-spark"/></svg><h3><?= te('هنوز یادداشتی منتشر نشده است') ?></h3><p><?= te('امکانات تازه سامانه اینجا معرفی می‌شوند.') ?></p></section>
    <?php else: foreach ($rows as $r): ?>
      <article class="panel release<?= (int) $r['visible'] === 0 ? ' is-hidden' : '' ?>">
        <div class="panel-head">
          <div>
            <?php if ($r['version']): ?><span class="chip chip-gold"><?= te('نسخه') ?> <bdi><?= e($r['version']) ?></bdi></span><?php endif; ?>
            <?php if ((int) $r['visible'] === 0): ?><span class="chip"><?= te('مخفی') ?></span><?php endif; ?>
            <?php if ((int) $r['announced'] === 1): ?><span class="chip"><?= te('اطلاع‌رسانی‌شده') ?></span><?php endif; ?>
            <h2><?= e($r['title']) ?></h2>
            <span class="muted"><?= e(fa_date($r['published_at'], false)) ?></span>
          </div>
          <?php if ($canWrite): ?>
            <form method="post" action="/updates/<?= e($r['id']) ?>/toggle"><?= csrf_field() ?><button class="btn btn-quiet btn-sm" type="submit"><?= (int) $r['visible'] === 1 ? t('مخفی کردن') : t('نمایش') ?></button></form>
          <?php endif; ?>
        </div>
        <div class="prose"><?= rich_text($r['body']) ?></div>
      </article>
    <?php endforeach; endif; ?>
  </div>
</div>
