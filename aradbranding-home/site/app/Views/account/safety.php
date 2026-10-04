<?php
/** حریم و امنیت — blocked traders and the member's own reports. @var array $blocked @var array $reports */
use App\Modules\Trust\TrustService;
$name = static fn (array $u): string => ($u['company_name'] ?? '') !== '' && $u['company_name'] !== null ? $u['company_name'] : trim($u['first_name'] . ' ' . $u['last_name']);
?>
<div class="stack safety">
  <section class="panel">
    <div class="panel-head">
      <div>
        <h2><?= te('تاجران مسدودشده') ?></h2>
        <p class="muted"><?= te('تاجری که مسدود کنید نمی‌تواند برایتان نامه، پاسخ یا پیشنهاد بفرستد و نامه‌های عمومی‌اش به شما نمی‌رسد؛ شما هم تا رفع مسدودی نمی‌توانید با او مکاتبه کنید. او از مسدودشدن باخبر نمی‌شود.') ?></p>
      </div>
    </div>
    <?php if ($blocked === []): ?>
      <p class="muted"><?= te('کسی را مسدود نکرده‌اید. از صفحه هر تاجر یا داخل هر گفتگو می‌توانید او را مسدود یا گزارش کنید.') ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($blocked as $b): $av = media($b['avatar_path']); ?>
          <li class="list-row">
            <?php if ($av): ?><img class="avatar" src="<?= e($av) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($b['first_name'], $b['last_name'])) ?></span><?php endif; ?>
            <div class="grow">
              <div class="title"><?= e($name($b)) ?> <?= flag($b['country_code']) ?></div>
              <div class="meta"><?php if ($b['handle']): ?><bdi>@<?= e($b['handle']) ?></bdi><?php endif; ?><span><?= te('مسدود از :date', ['date' => fa_date($b['created_at'])]) ?></span></div>
            </div>
            <form method="post" action="/blocks/<?= (int) $b['blocked_id'] ?>/delete"><?= csrf_field() ?><input type="hidden" name="back" value="/account/safety">
              <button class="btn btn-ghost btn-sm" type="submit"><?= te('رفع مسدودی') ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2><?= te('گزارش‌های من') ?></h2>
    <?php if ($reports === []): ?>
      <p class="muted"><?= te('گزارشی ثبت نکرده‌اید. اگر نامه، پیشنهاد یا صفحه‌ای مزاحم، فریبنده یا نامناسب دیدید، با دکمه «گزارش تخلف» کنار آن ما را باخبر کنید.') ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($reports as $r): $st = (int) $r['status']; ?>
          <li class="list-row">
            <div class="grow">
              <div class="title"><?= te(TrustService::TARGETS[(int) $r['target_type']] ?? '') ?> · <?= e($name($r)) ?></div>
              <div class="meta"><span><?= te(TrustService::REASONS[$r['reason']] ?? $r['reason']) ?></span><span><?= e(fa_date($r['created_at'])) ?></span></div>
            </div>
            <span class="chip<?= $st === TrustService::OPEN ? ' chip-warn' : ($st === TrustService::ACTIONED ? ' chip-ok' : '') ?>"><?= te(TrustService::STATUS[$st] ?? '') ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
