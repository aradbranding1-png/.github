<?php /** @var array $announcements @var array $old @var array $errors */
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$aud = ($old['audience'] ?? 'all') === 'selected' ? 'selected' : 'all'; ?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'official', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<form class="panel form" method="post" action="/admin/letters">
  <?= csrf_field() ?>
  <div><h2>نامه جدید از طرف آراد برندینگ</h2><p class="muted">رایگان است. نامه «همه کاربران» در تب «آراد برندینگ» صندوق نامه همه نمایش داده می‌شود.</p></div>
  <div class="field">
    <span class="label">گیرندگان</span>
    <label class="check"><input type="radio" name="audience" value="all"<?= $aud === 'all' ? ' checked' : '' ?>> همه کاربران</label>
    <label class="check"><input type="radio" name="audience" value="selected"<?= $aud === 'selected' ? ' checked' : '' ?>> کاربران منتخب (می‌توانند پاسخ دهند)</label>
  </div>
  <div class="field<?= isset($errors['handles']) ? ' has-error' : '' ?>"><label for="f-handles">نشانی صفحه کاربران منتخب</label><textarea class="textarea" id="f-handles" name="handles" rows="2" dir="ltr" placeholder="aradtrade, examplecorp"><?= e($old['handles'] ?? '') ?></textarea><?= $err('handles') ?></div>
  <div class="field<?= isset($errors['subject']) ? ' has-error' : '' ?>"><label for="f-subject">موضوع</label><input class="input" id="f-subject" name="subject" maxlength="150" value="<?= e($old['subject'] ?? '') ?>"><?= $err('subject') ?></div>
  <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="f-body">متن</label><textarea class="textarea" id="f-body" name="body" rows="8"><?= e($old['body'] ?? '') ?></textarea><?= $err('body') ?></div>
  <div class="form-actions"><button class="btn" type="submit"><svg class="icon"><use href="#i-send"/></svg>ارسال</button></div>
</form>

<section class="panel">
  <h2>نامه‌های منتشرشده برای همه</h2>
  <?php if ($announcements === []): ?><div class="empty"><p>هنوز نامه‌ای منتشر نشده است.</p></div><?php else: ?>
    <ul class="list">
      <?php foreach ($announcements as $a): ?>
        <li class="list-row">
          <div class="grow"><a class="title" href="/letters/official/<?= e($a['uid']) ?>"><?= e($a['subject']) ?></a><div class="meta"><span><?= e(fa_date($a['published_at'])) ?></span></div></div>
          <form class="inline-form" method="post" action="/admin/letters/<?= e($a['uid']) ?>/delete" data-confirm="این نامه از صندوق همه کاربران حذف شود؟"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">حذف</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

  </div>
</div>
