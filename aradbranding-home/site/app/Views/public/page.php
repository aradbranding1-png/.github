<?php
/** @var array $page @var bool $full @var bool $isOwner @var array $alternates @var string $handle @var array $labels */
$c = $page['content'];
$name = $page['company_name'] ?: $page['title'];
$logo = media($page['avatar_path']);
$cover = media($page['cover_path']);
$countryName = $page['lang_code'] === 'fa' ? $page['country_fa'] : $page['country_en'];
?>
<main class="pub">
  <?php foreach (($flashes ?? []) as $type => $message): ?><div class="alert alert-<?= e($type) ?> pub-alert" role="status"><?= e($message) ?></div><?php endforeach; ?>
  <div class="pub-cover"><?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" width="1600" height="600" fetchpriority="high"><?php endif; ?></div>

  <section class="pub-card">
    <div class="pub-avatar"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($name) ?>" width="112" height="112"><?php else: ?><div aria-hidden="true"><?= e(mb_substr($name, 0, 1)) ?></div><?php endif; ?></div>
    <h1 class="pub-name"><?= e($name) ?></h1>
    <?php if ($page['company_name'] && $page['company_name'] !== $page['title']): ?><p class="pub-title"><?= e($page['title']) ?></p><?php endif; ?>
    <div class="pub-meta">
      <span class="chip"><span class="flag" aria-hidden="true"><?= flag($page['country_code']) ?></span><?= e($countryName) ?></span>
      <?php if ($page['verified']): ?><span class="chip chip-gold"><svg class="icon"><use href="#i-check"/></svg><?= e($labels['verified']) ?></span><?php endif; ?>
      <?php if ($isOwner): ?><a class="chip chip-gold" href="/pages" lang="fa"><?= e($labels['edit']) ?></a><?php endif; ?>
    </div>
    <?php if ($viewer && !$isOwner): ?>
      <div class="form-actions pub-cta" lang="fa" dir="rtl">
        <a class="btn btn-sm" href="/letters/new?to=<?= e($handle) ?>"><svg class="icon"><use href="#i-letter"/></svg>ارسال نامه</a>
        <a class="btn btn-ghost btn-sm" href="/proposals/send?to=<?= e($handle) ?>"><svg class="icon"><use href="#i-send"/></svg>ارسال پیشنهاد تجاری</a>
      </div>
    <?php endif; ?>
    <p class="pub-teaser"><?= rich_text($page['teaser']) ?></p>

    <?php if (count($alternates) > 1): ?>
      <nav class="pub-langs" aria-label="<?= e($labels['languages']) ?>">
        <?php foreach ($alternates as $code): ?>
          <a class="chip" href="/p/<?= e($handle) ?>/<?= e($code) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === $page['lang_code'] ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </section>

  <div class="pub-body">
    <?php if (!$full && $unlock !== null): $enough = $unlock['balance'] >= $unlock['price']; ?>
      <section class="pub-section pub-locked span">
        <svg class="icon"><use href="#i-lock"/></svg>
        <h2><?= e($labels['unlock_title']) ?></h2>
        <p><?= e($labels['unlock_text']) ?></p>
        <?php if ($enough): ?>
          <form method="post" action="<?= e($unlock['action']) ?>" class="form-actions form-actions-center">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($unlock['token']) ?>">
            <button class="btn" type="submit"><span aria-hidden="true">⭐</span> <?= e(str_replace(':n', (string) $unlock['price'], $labels['unlock_btn'])) ?></button>
          </form>
        <?php elseif (!empty($buyEnabled)): ?>
          <div class="form-actions form-actions-center">
            <a class="btn" href="/wallet?need=<?= e($unlock['price'] - $unlock['balance']) ?>&amp;next=<?= e(rawurlencode($unlock['action'] === '' ? '/' : substr($unlock['action'], 0, -7))) ?>"><?= e($labels['buy']) ?></a>
          </div>
        <?php endif; ?>
        <p class="pub-price-note"><?= e(str_replace(':n', (string) $unlock['balance'], $labels['balance'])) ?> · <?= e($unlock['domestic'] ? $labels['domestic'] : $labels['international']) ?></p>
      </section>
    <?php elseif (!$full): ?>
      <section class="pub-section pub-locked span">
        <svg class="icon"><use href="#i-lock"/></svg>
        <h2><?= e($labels['locked_title']) ?></h2>
        <p><?= e($labels['locked_text']) ?></p>
        <div class="form-actions form-actions-center">
          <a class="btn" href="/login?next=<?= e(rawurlencode('/p/' . $handle . '/' . $page['lang_code'])) ?>"><?= e($labels['login']) ?></a>
          <a class="btn btn-ghost" href="/register"><?= e($labels['register']) ?></a>
        </div>
      </section>
    <?php else: ?>
      <?php if (!empty($page['about'])): ?>
        <section class="pub-section span"><h2><?= e($labels['about']) ?></h2><div class="prose"><?= rich_text($page['about']) ?></div></section>
      <?php endif; ?>
      <?php foreach (['products', 'services', 'markets'] as $key): if (!empty($c[$key])): ?>
        <section class="pub-section">
          <h2><?= e($labels[$key]) ?></h2>
          <ul class="pub-list"><?php foreach ($c[$key] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
        </section>
      <?php endif; endforeach; ?>
      <?php if ($viewer && !$isOwner): ?>
        <section class="pub-section span pub-reach" lang="fa" dir="rtl">
          <h2><?= e($labels['contact']) ?></h2>
          <p class="muted"><?= e($labels['contact_note']) ?></p>
          <div class="form-actions">
            <a class="btn btn-sm" href="/letters/new?to=<?= e($handle) ?>"><svg class="icon"><use href="#i-letter"/></svg>ارسال نامه</a>
            <a class="btn btn-ghost btn-sm" href="/proposals/send?to=<?= e($handle) ?>"><svg class="icon"><use href="#i-send"/></svg>ارسال پیشنهاد تجاری</a>
          </div>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <p class="pub-foot"><?= e($labels['network']) ?> · aradbranding.app</p>
</main>
