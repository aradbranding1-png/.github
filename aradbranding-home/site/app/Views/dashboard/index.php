<?php /** @var int $balance @var array $user @var array $counters @var array $pages @var array $steps */
$stat = static fn (string $k): string => fa_num((int) ($counters[$k] ?? 0));
$pending = array_filter($steps, static fn (array $s): bool => !$s['done']);
?>
<div class="stack">
  <section class="welcome gilded">
    <div>
      <h2>سلام <?= e($user['first_name']) ?></h2>
      <p class="muted">خلاصه فعالیت تجاری شما در یک نگاه.</p>
    </div>
    <div class="welcome-actions">
      <a class="btn" href="/proposals">کشف فرصت‌ها</a>
      <a class="btn btn-ghost" href="/letters/send">ارسال نامه</a>
    </div>
  </section>

  <section class="stats" aria-label="آمار">
    <a class="stat" href="/connections"><b><?= $stat('connections') ?></b><span>ارتباطات تجاری</span></a>
    <div class="stat"><b><?= $stat('page_views_received') ?></b><span>بازدید صفحه‌ها</span></div>
    <a class="stat" href="/proposals/received"><b><?= $stat('proposals') ?></b><span>پیشنهادهای دریافتی</span></a>
    <a class="stat" href="/letters"><b><?= $stat('replies') ?></b><span>پاسخ‌های دریافتی</span></a>
  </section>

  <a class="panel wallet-strip" href="/wallet">
    <span><span aria-hidden="true">⭐</span> موجودی Stars <b><?= fa_int($balance) ?></b></span>
    <span class="muted">کیف پول ←</span>
  </a>

  <?php if ($pending !== []): ?>
  <section class="panel">
    <div class="panel-head"><h2>آماده‌سازی حساب</h2><span class="chip"><?= fa_num(count($steps) - count($pending)) ?> از <?= fa_num(count($steps)) ?></span></div>
    <ul class="steps">
      <?php foreach ($steps as $s): ?>
        <li class="<?= $s['done'] ? 'done' : '' ?>">
          <a href="<?= e($s['href']) ?>">
            <span class="dot"><?php if ($s['done']): ?><svg class="icon"><use href="#i-check"/></svg><?php endif; ?></span>
            <span><?= e($s['label']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <h2>صفحه‌های تجاری</h2>
      <?php if ($pages !== []): ?><a class="btn btn-ghost btn-sm" href="/pages">مدیریت</a><?php endif; ?>
    </div>
    <?php if ($pages === []): ?>
      <div class="empty">
        <svg class="icon"><use href="#i-page"/></svg>
        <h3>هنوز صفحه تجاری ندارید</h3>
        <p>صفحه تجاری مثل یک وب‌سایت کوچک است: تجار کشورهای دیگر با آن شما، محصولات و بازارهایتان را می‌شناسند.</p>
        <a class="btn" href="/pages/new">ساخت صفحه تجاری</a>
      </div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($pages as $p): ?>
          <li class="list-row">
            <span class="lang-badge"><?= e($p['lang_code']) ?></span>
            <div class="grow">
              <div class="title"><?= e($p['title']) ?></div>
              <div class="meta">
                <span><?= e($p['lang_name_fa']) ?></span>
                <?php if ((int) $p['status'] === 2): ?><span class="chip chip-ok">منتشرشده</span><?php else: ?><span class="chip">پیش‌نویس</span><?php endif; ?>
                <?php if ($p['is_default']): ?><span class="chip chip-gold">پیش‌فرض</span><?php endif; ?>
              </div>
            </div>
            <a class="btn btn-quiet btn-sm" href="/pages/<?= e($p['uid']) ?>/edit">ویرایش</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
