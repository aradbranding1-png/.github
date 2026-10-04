<?php
/**
 * «گزارش تخلف» — a small pop-over form. Works on any page that has a session (CSRF).
 * @var string $type user|page|proposal|message @var int $id @var string $back
 * @var ?int $blockUser offer «این تاجر را هم مسدود کن» @var ?string $label @var bool $compact icon-only trigger
 */
use App\Modules\Trust\TrustService;
$label ??= t('گزارش تخلف');
$compact ??= false;
$blockUser ??= null;
$uid = 'rp-' . $type . '-' . $id;
?>
<details class="report-pop<?= $compact ? ' is-compact' : '' ?>" <?= \App\Core\I18n\I18n::langAttrs() ?>>
  <summary class="report-trigger" title="<?= te($label) ?>" aria-label="<?= te($label) ?>"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 21V4m0 0h11l-2 4 2 4H5"/></svg><?php if (!$compact): ?><span><?= te($label) ?></span><?php endif; ?></summary>
  <form class="report-form" method="post" action="/reports">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <b class="report-title"><?= te($label) ?></b>
    <p class="muted"><?= te('گزارش شما محرمانه است و فقط تیم بررسی آراد برندینگ آن را می‌بیند.') ?></p>
    <fieldset class="report-reasons">
      <legend class="sr-only"><?= te('دلیل') ?></legend>
      <?php foreach (TrustService::REASONS as $key => $text): ?>
        <label class="report-reason"><input type="radio" name="reason" value="<?= e($key) ?>" required><span><?= te($text) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="sr-only" for="<?= e($uid) ?>-d"><?= te('توضیح') ?></label>
    <textarea class="textarea" id="<?= e($uid) ?>-d" name="details" rows="2" maxlength="1000" placeholder="<?= te('توضیح کوتاه (اختیاری)') ?>"></textarea>
    <?php if ($blockUser !== null): ?>
      <label class="check report-block"><input type="checkbox" name="block" value="1"> <?= te('این تاجر را مسدود کن (دیگر نمی‌تواند برایم نامه یا پیشنهاد بفرستد)') ?></label>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-sm report-submit" type="submit"><?= te('ثبت گزارش') ?></button></div>
  </form>
</details>
