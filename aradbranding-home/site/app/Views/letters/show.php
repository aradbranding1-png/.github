<?php
/** @var string $orgName @var array $user @var array $thread @var array $messages @var array $cards @var ?array $peer @var ?array $proposal */
use App\Modules\Letters\LetterService;
$officialThread = (int) $thread['type'] === LetterService::T_OFFICIAL;
$baseName = static fn (?array $u): string => $u ? ($u['company_name'] ?: trim($u['first_name'] . ' ' . $u['last_name'])) : '—';
// In an official thread the organisation's account is shown as the organisation, not as a person.
$name = static fn (?array $u): string => $officialThread && $u && (int) $u['id'] === (int) $thread['created_by'] ? $orgName : $baseName($u);
$archived = (int) $thread['folder'] === LetterService::ARCHIVE;
$count = count($messages['rows']);
?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'inbox', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<div class="thread">
  <header class="thread-head panel">
    <div class="grow">
      <span class="chip"><?= e(LetterService::TYPE_LABELS[(int) $thread['type']] ?? '') ?></span>
      <h2><?= e($thread['subject']) ?></h2>
      <?php if ($peer): ?><p class="muted">گفتگو با <?= e($name($peer)) ?><?php if (!($officialThread && (int) $peer['id'] === (int) $thread['created_by'])): ?> <?= flag($peer['country_code']) ?><?php if ($peer['handle']): ?> · <a href="/p/<?= e($peer['handle']) ?>">صفحه تجاری</a><?php endif; ?><?php endif; ?></p><?php endif; ?>
    </div>
    <form method="post" action="/letters/<?= e($thread['uid']) ?>/archive"><?= csrf_field() ?>
      <button class="btn btn-ghost btn-sm" type="submit"><svg class="icon"><use href="#i-archive"/></svg><?= $archived ? 'خروج از بایگانی' : 'بایگانی' ?></button>
    </form>
    <?php if (!$officialThread && $peer): ?>
      <form method="post" action="/blocks/<?= (int) $peer['id'] ?><?= $blockedByMe ? '/delete' : '' ?>"><?= csrf_field() ?><input type="hidden" name="back" value="/letters/<?= e($thread['uid']) ?>">
        <button class="btn btn-quiet btn-sm" type="submit"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M6.5 6.5l11 11"/></svg><?= $blockedByMe ? 'رفع مسدودی' : 'مسدودکردن' ?></button>
      </form>
    <?php endif; ?>
  </header>

  <?php if ($proposal): ?>
    <a class="owner-row" href="/proposals/<?= e($proposal['uid']) ?>">
      <?php if ($proposal['thumb_path']): ?><img class="thumb-sm" src="<?= e(media($proposal['thumb_path'])) ?>" alt=""><?php endif; ?>
      <span class="grow"><b><?= e($proposal['title']) ?></b><span class="muted">مشاهده پیشنهاد</span></span>
    </a>
  <?php endif; ?>

  <?php if ($messages['older']): ?><div class="form-actions form-actions-center"><a class="btn btn-quiet btn-sm" href="/letters/<?= e($thread['uid']) ?>?before=<?= e($messages['older']) ?>">پیام‌های قبلی</a></div><?php endif; ?>

  <ol class="messages">
    <?php foreach ($messages['rows'] as $i => $m): $mine = (int) $m['sender_id'] === (int) $user['id']; $author = $cards[(int) $m['sender_id']] ?? null; ?>
      <li class="msg<?= $mine ? ' mine' : '' ?>"<?= $i === $count - 1 ? ' id="last"' : '' ?>>
        <div class="msg-meta"><b><?= $mine ? 'شما' : e($name($author)) ?></b><span><?= e(fa_date($m['created_at'])) ?></span>
          <?php if (!$mine && !$officialThread && $m['hidden_at'] === null): ?><?= $this->partial('partials/report', ['type' => 'message', 'id' => (int) $m['id'], 'back' => '/letters/' . $thread['uid'], 'blockUser' => $blockedByMe ? null : (int) $m['sender_id'], 'label' => 'گزارش این نامه', 'compact' => true]) ?><?php endif; ?>
        </div>
        <?php if ($m['hidden_at'] !== null): ?>
          <div class="msg-body msg-hidden">این پیام به دلیل نقض قوانین آراد برندینگ توسط تیم بررسی پنهان شد.</div>
        <?php else: ?>
          <div class="msg-body"><?= nl2br(e((string) $m['body']), false) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($blocked): ?>
  <div class="panel reply-closed" id="reply"><?= $blockedByMe ? 'این تاجر را مسدود کرده‌اید؛ برای پاسخ، ابتدا رفع مسدودی کنید.' : 'امکان پاسخ در این گفتگو وجود ندارد.' ?></div>
  <?php else: ?>
  <form class="panel form reply" method="post" action="/letters/<?= e($thread['uid']) ?>/reply" id="reply">
    <?= csrf_field() ?>
    <label for="f-body">پاسخ <span class="muted">(رایگان)</span></label>
    <textarea class="textarea" id="f-body" name="body" rows="4" maxlength="10000" required></textarea>
    <div class="form-actions"><button class="btn" type="submit"><svg class="icon"><use href="#i-send"/></svg>ارسال پاسخ</button></div>
  </form>
  <?php endif; ?>
</div>

  </div>
</div>
