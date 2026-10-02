<?php /** @var array $a @var string $orgName */ ?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'inbox', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<div class="thread">
  <header class="thread-head panel">
    <span class="avatar org-avatar has-logo"><img src="/assets/brand/logo-192.webp?v=3" alt="" width="48" height="48" decoding="async"></span>
    <div class="grow">
      <span class="chip chip-gold">نامه رسمی <?= e($orgName) ?></span>
      <h2><?= e($a['subject']) ?></h2>
      <p class="muted"><?= e(fa_date($a['published_at'])) ?></p>
    </div>
  </header>
  <article class="panel"><div class="prose"><?= rich_text($a['body']) ?></div></article>
  <div class="form-actions"><a class="btn btn-ghost" href="/letters?type=official">بازگشت به نامه‌ها</a></div>
</div>

  </div>
</div>
