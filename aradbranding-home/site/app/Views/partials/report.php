<?php
/**
 * «گزارش تخلف» — a small pop-over form. Works on any page that has a session (CSRF).
 * @var string $type user|page|proposal|message @var int $id @var string $back
 * @var ?int $blockUser offer «این تاجر را هم مسدود کن» @var ?string $label @var bool $compact icon-only trigger
 */
use App\Modules\Trust\TrustService;
$label ??= 'گزارش تخلف';
$compact ??= false;
$blockUser ??= null;
$uid = 'rp-' . $type . '-' . $id;
?>
<details class="report-pop<?= $compact ? ' is-compact' : '' ?>" lang="fa" dir="rtl">
  <summary class="report-trigger" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 21V4m0 0h11l-2 4 2 4H5"/></svg><?php if (!$compact): ?><span><?= e($label) ?></span><?php endif; ?></summary>
  <form class="report-form" method="post" action="/reports">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <b class="report-title"><?= e($label) ?></b>
    <p class="muted">گزارش شما محرمانه است و فقط تیم بررسی آراد برندینگ آن را می‌بیند.</p>
    <fieldset class="report-reasons">
      <legend class="sr-only">دلیل</legend>
      <?php foreach (TrustService::REASONS as $key => $text): ?>
        <label class="report-reason"><input type="radio" name="reason" value="<?= e($key) ?>" required><span><?= e($text) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="sr-only" for="<?= e($uid) ?>-d">توضیح</label>
    <textarea class="textarea" id="<?= e($uid) ?>-d" name="details" rows="2" maxlength="1000" placeholder="توضیح کوتاه (اختیاری)"></textarea>
    <?php if ($blockUser !== null): ?>
      <label class="check report-block"><input type="checkbox" name="block" value="1"> این تاجر را مسدود کن (دیگر نمی‌تواند برایم نامه یا پیشنهاد بفرستد)</label>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-sm report-submit" type="submit">ثبت گزارش</button></div>
  </form>
</details>
