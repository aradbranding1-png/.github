<?php foreach (['countries', 'languages', 'categories'] as $__ref) { if (isset($$__ref) && is_array($$__ref)) { $$__ref = localized_rows($$__ref, 'name_fa', $__ref !== 'categories'); } } unset($__ref); ?>
<?php
/** @var array $countries @var array $languages @var array $categories @var array $old @var array $errors */
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$sel = static fn (string $k, int $id): string => (int) ($old[$k] ?? 0) === $id ? ' selected' : '';
?>
<form class="stack" method="post" action="/letters/public">
  <?= csrf_field() ?>
  <?php if (isset($errors['filter'])): ?><div class="alert alert-error" role="alert"><?= e($errors['filter']) ?></div><?php endif; ?>
  <section class="panel form">
    <div><h2><?= te('گیرندگان') ?></h2><p class="muted"><?= te('فیلترها با هم ترکیب می‌شوند و نامه به همه تجار فعالِ منطبق با آن‌ها می‌رسد. قبل از ارسال، تعداد و هزینه را می‌بینید.') ?></p></div>
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
      <div class="hint"><?= te('نشانی صفحه هر تاجر را در یک خط بنویسید و برای نفر بعدی Enter بزنید (حداکثر ۲۰۰ نفر). اگر پر شود، فقط همین افراد (با سایر فیلترها) دریافت می‌کنند.') ?> <b class="handles-count" aria-live="polite"></b></div></div>
  </section>
  <section class="panel form">
    <h2><?= te('نامه') ?></h2>
    <div class="field<?= isset($errors['subject']) ? ' has-error' : '' ?>"><label for="f-subject"><?= te('موضوع') ?></label><input class="input" id="f-subject" name="subject" maxlength="150" value="<?= e($old['subject'] ?? '') ?>" required><?= $err('subject') ?></div>
    <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="f-body"><?= te('متن') ?></label><textarea class="textarea" id="f-body" name="body" rows="8" maxlength="10000" required><?= e($old['body'] ?? '') ?></textarea><?= $err('body') ?></div>
    <div class="form-actions"><button class="btn" type="submit"><?= te('محاسبه گیرندگان و هزینه') ?></button></div>
  </section>
</form>
