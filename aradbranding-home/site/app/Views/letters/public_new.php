<?php
/** @var array $countries @var array $languages @var array $categories @var array $old @var array $errors */
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$sel = static fn (string $k, int $id): string => (int) ($old[$k] ?? 0) === $id ? ' selected' : '';
?>
<form class="stack" method="post" action="/letters/public">
  <?= csrf_field() ?>
  <?php if (isset($errors['filter'])): ?><div class="alert alert-error" role="alert"><?= e($errors['filter']) ?></div><?php endif; ?>
  <section class="panel form">
    <div><h2>گیرندگان</h2><p class="muted">فیلترها با هم ترکیب می‌شوند و نامه به همه تجار فعالِ منطبق با آن‌ها می‌رسد. قبل از ارسال، تعداد و هزینه را می‌بینید.</p></div>
    <div class="row row-2">
      <div class="field"><label for="f-country">کشور</label>
        <select class="select" id="f-country" name="country"><option value="">همه کشورها</option>
          <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en']) ?>"<?= $sel('country', $c['id']) ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="f-language">زبان</label>
        <select class="select" id="f-language" name="language"><option value="">همه زبان‌ها</option>
          <?php foreach ($languages as $l): ?><option value="<?= e($l['id']) ?>" data-search="<?= e($l['name'] . ' ' . $l['code']) ?>"<?= $sel('language', $l['id']) ?>><?= e($l['name_fa']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label for="f-category">حوزه فعالیت</label>
      <select class="select" id="f-category" name="category"><option value="">همه حوزه‌ها</option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en']) ?>"<?= $sel('category', $c['id']) ?>><?= e($c['name_fa']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="f-handles">کاربران منتخب <span class="muted">(اختیاری)</span></label>
      <textarea class="textarea" id="f-handles" name="handles" rows="2" dir="ltr" placeholder="aradtrade, examplecorp"><?= e($old['handles'] ?? '') ?></textarea>
      <div class="hint">نشانی صفحه تجار را با ویرگول جدا کنید (حداکثر ۲۰۰). اگر پر شود، فقط همین افراد (با سایر فیلترها) دریافت می‌کنند.</div></div>
  </section>
  <section class="panel form">
    <h2>نامه</h2>
    <div class="field<?= isset($errors['subject']) ? ' has-error' : '' ?>"><label for="f-subject">موضوع</label><input class="input" id="f-subject" name="subject" maxlength="150" value="<?= e($old['subject'] ?? '') ?>" required><?= $err('subject') ?></div>
    <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="f-body">متن</label><textarea class="textarea" id="f-body" name="body" rows="8" maxlength="10000" required><?= e($old['body'] ?? '') ?></textarea><?= $err('body') ?></div>
    <div class="form-actions"><button class="btn" type="submit">محاسبه گیرندگان و هزینه</button></div>
  </section>
</form>
