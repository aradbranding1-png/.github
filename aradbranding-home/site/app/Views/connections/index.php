<?php /** @var array $rows @var array $cards @var ?string $next */ ?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'connections', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<section class="panel">
  <div class="panel-head"><div><h2><?= te('ارتباطات تجاری') ?></h2><p class="muted"><?= te('هر بار تاجری به نامه یا پیشنهاد شما پاسخ دهد (یا شما به او)، یک ارتباط تجاری ثبت می‌شود.') ?></p></div></div>
  <?php if ($rows === []): ?>
    <div class="empty"><svg class="icon"><use href="#i-route"/></svg><h3><?= te('هنوز ارتباطی ثبت نشده است') ?></h3><p><?= te('یک پیشنهاد یا نامه بفرستید؛ اولین پاسخ، اولین ارتباط تجاری شماست.') ?></p><a class="btn" href="/proposals"><?= te('کشف فرصت‌ها') ?></a></div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $r): $u = $cards[(int) $r['peer_id']] ?? null; if (!$u) { continue; } $av = media($u['avatar_path']); ?>
        <li class="list-row">
          <?php if ($av): ?><img class="avatar" src="<?= e($av) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= e(initials($u['first_name'], $u['last_name'])) ?></span><?php endif; ?>
          <div class="grow">
            <div class="title"><?= e($u['company_name'] ?: trim($u['first_name'] . ' ' . $u['last_name'])) ?> <?= flag($u['country_code']) ?></div>
            <div class="meta"><span><?= te('از :date', ['date' => fa_date($r['created_at'], false)]) ?></span></div>
          </div>
          <?php if ($u['handle']): ?><a class="btn btn-ghost btn-sm" href="/letters/new?to=<?= e($u['handle']) ?>"><?= te('نامه') ?></a><a class="btn btn-quiet btn-sm" href="/p/<?= e($u['handle']) ?>"><?= te('صفحه') ?></a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/connections?before=<?= e(rawurlencode($next)) ?>"><?= te('بیشتر') ?></a></div><?php endif; ?>
  <?php endif; ?>
</section>

  </div>
</div>
