<?php foreach (['countries', 'languages', 'categories'] as $__ref) { if (isset($$__ref) && is_array($$__ref)) { $$__ref = localized_rows($$__ref, 'name_fa', $__ref !== 'categories'); } } unset($__ref); ?>
<?php
/** @var array $countries @var array $languages @var array $categories @var array $proposals @var array $prices @var array $old @var array $errors */
use App\Modules\Letters\CampaignService;
use App\Modules\Letters\LetterService;
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$sel = static fn (string $k, int $id): string => (int) ($old[$k] ?? 0) === $id ? ' selected' : '';
$kind = (int) ($old['kind'] ?? LetterService::T_PUBLIC);
$hints = [
    LetterService::T_PUBLIC => t('یک متن برای همه؛ هر گیرنده می‌تواند جداگانه پاسخ دهد.'),
    LetterService::T_PRIVATE => t('برای هر گیرنده یک نامه اختصاصی جداگانه؛ در صندوق او در دسته «اختصاصی» دیده می‌شود.'),
    LetterService::T_PROPOSAL => t('یکی از پیشنهادهای منتشرشده خود را برای همه گیرندگان بفرستید. کسانی که قبلاً این پیشنهاد را دریافت کرده‌اند حساب نمی‌شوند.'),
];
?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'compose', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<form class="stack" method="post" action="/letters/send" data-send-form>
  <?= csrf_field() ?>
  <?php if (isset($errors['filter'])): ?><div class="alert alert-error" role="alert"><?= e($errors['filter']) ?></div><?php endif; ?>

  <section class="panel form">
    <div><h2><span class="step"><?= fa_num(1) ?></span> <?= te('گیرندگان') ?></h2><p class="muted"><?= te('فیلترها با هم ترکیب می‌شوند و نامه به همه تجار فعالِ منطبق با آن‌ها می‌رسد.') ?></p></div>
    <div class="row row-2">
      <div class="field"><label for="f-country"><?= te('کشور') ?></label>
        <select class="select" id="f-country" name="country"><option value=""><?= te('همه کشورها') ?></option>
          <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en']) ?>"<?= $sel('country', $c['id']) ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="f-language"><?= te('زبان') ?></label>
        <select class="select" id="f-language" name="language"><option value=""><?= te('همه زبان‌ها') ?></option>
          <?php foreach ($languages as $l): ?><option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= $sel('language', $l['id']) ?>><?= e($l['name_fa']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label for="f-category"><?= te('حوزه فعالیت') ?></label>
      <select class="select" id="f-category" name="category"><option value=""><?= te('همه حوزه‌ها') ?></option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en']) ?>"<?= $sel('category', $c['id']) ?>><?= e($c['name_fa']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="f-handles"><?= te('کاربران منتخب') ?> <span class="muted"><?= te('(اختیاری)') ?></span></label>
      <textarea class="textarea handles-box" id="f-handles" name="handles" rows="5" dir="ltr" spellcheck="false" autocapitalize="off" placeholder="aradtrade&#10;examplecorp&#10;…"><?= e($old['handles'] ?? '') ?></textarea>
      <div class="hint"><?= te('نشانی صفحه هر تاجر را در یک خط بنویسید و برای نفر بعدی Enter بزنید (حداکثر ۲۰۰ نفر). نشانی کامل صفحه مثل aradbranding.app/p/aradtrade هم پذیرفته می‌شود. برای ارسال به یک نفر، فقط نشانی همان یک نفر را بنویسید.') ?> <b class="handles-count" aria-live="polite"></b></div></div>
  </section>

  <section class="panel form">
    <h2><span class="step"><?= fa_num(2) ?></span> <?= te('چه چیزی ارسال شود؟') ?></h2>
    <?= $err('kind') ?>
    <div class="kind-cards">
      <?php foreach (CampaignService::KINDS as $k => $meta): $p = $prices[$k]; ?>
        <label class="kind-card">
          <input type="radio" name="kind" value="<?= $k ?>"<?= $kind === $k ? ' checked' : '' ?>>
          <span class="kind-body">
            <b><?= te($meta['label']) ?></b>
            <span class="muted"><?= e($hints[$k]) ?></span>
            <span class="kind-price"><?= te('هر گیرنده: :d ⭐ داخلی · :i ⭐ بین‌المللی', ['d' => fa_int($p['domestic']), 'i' => fa_int($p['international'])]) ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel form">
    <h2><span class="step"><?= fa_num(3) ?></span> <?= te('محتوا') ?></h2>
    <div class="field<?= isset($errors['proposal']) ? ' has-error' : '' ?>" data-kind-only="<?= LetterService::T_PROPOSAL ?>">
      <span class="label"><?= te('کدام پیشنهاد ارسال شود؟') ?></span>
      <?php if ($proposals === []): ?>
        <p class="muted need-proposal"><?= te('هنوز پیشنهادی نساخته‌اید.') ?> <a class="rg-link" href="/proposals/new"><svg class="icon"><use href="#i-plus"/></svg><?= te('ساخت پیشنهاد تجاری') ?></a></p>
      <?php else: ?>
        <div class="proposal-picks">
          <?php foreach ($proposals as $i => $p): $thumb = media($p['thumb_path']); $st = (int) $p['status']; ?>
            <label class="proposal-pick">
              <input type="radio" name="proposal" value="<?= e($p['uid']) ?>"<?= (($old['proposal'] ?? '') === $p['uid'] || (!isset($old['proposal']) && $i === 0)) ? ' checked' : '' ?>>
              <span class="proposal-pick-body">
                <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb-sm"></span><?php endif; ?>
                <span class="grow"><b><?= e($p['title']) ?></b>
                  <span class="muted"><?= te(\App\Modules\Proposals\ProposalService::TYPES[(int) $p['type']] ?? '') ?> · <?= te(\App\Modules\Proposals\ProposalService::STATUS_LABELS[$st] ?? '') ?><?= $st !== 2 ? t(' (هنگام ارسال منتشر می‌شود)') : '' ?></span></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?= $err('proposal') ?>
    </div>
    <div class="field<?= isset($errors['subject']) ? ' has-error' : '' ?>" data-kind-hide="<?= LetterService::T_PROPOSAL ?>"><label for="f-subject"><?= te('موضوع') ?></label><input class="input" id="f-subject" name="subject" maxlength="150" value="<?= e($old['subject'] ?? '') ?>"><?= $err('subject') ?></div>
    <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="f-body"><?= te('متن') ?> <span class="muted" data-kind-only="<?= LetterService::T_PROPOSAL ?>"><?= te('(پیام همراه پیشنهاد، اختیاری)') ?></span></label><textarea class="textarea" id="f-body" name="body" rows="8" maxlength="10000"><?= e($old['body'] ?? '') ?></textarea><?= $err('body') ?></div>
    <div class="form-actions"><button class="btn" type="submit"><?= te('محاسبه گیرندگان و هزینه') ?></button><span class="muted"><?= te('در مرحله بعد تعداد گیرندگان و هزینه را می‌بینید و تأیید می‌کنید.') ?></span></div>
  </section>
</form>

  </div>
</div>
