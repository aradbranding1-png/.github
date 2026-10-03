<?php
/** @var array $u @var array $counters @var array $wallet @var array $ledger @var array $pages @var int $proposalCount @var array $roles
 *  @var array $assigned @var array $countries @var array $logins @var array $errors @var array $perms */
use App\Modules\Admin\UserAdminController;
use App\Modules\Wallet\WalletService;
$has = static fn (string $p): bool => isset($perms[$p]);
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="alert alert-error" role="alert">' . e($errors[$k]) . '</div>' : '';
$av = media($u['avatar_path']);
$pw = '<input class="input narrow-wide" type="password" name="password" placeholder="رمز عبور شما" autocomplete="current-password" dir="ltr" required aria-label="رمز عبور شما">';
?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'users']) ?>
<section class="panel owner-row admin-user-head">
  <?php if ($av): ?><img class="avatar avatar-lg" src="<?= e($av) ?>" alt=""><?php else: ?><span class="avatar avatar-lg"><?= e(initials($u['first_name'], $u['last_name'])) ?></span><?php endif; ?>
  <span class="grow">
    <b><?= e($u['first_name'] . ' ' . $u['last_name']) ?></b>
    <span class="muted"><?= flag($u['country_code']) ?> <?= e($u['country_fa']) ?> · <?= e($u['language_fa']) ?> · <bdi><?= e($u['email']) ?></bdi> · <bdi>+<?= e($u['phone_cc'] . $u['phone']) ?></bdi></span>
    <span class="muted">عضویت <?= e(fa_date($u['created_at'])) ?> · آخرین فعالیت <?= e(fa_date($u['last_active_at'])) ?></span>
  </span>
  <span class="chip<?= (int) $u['status'] === 1 ? ' chip-ok' : '' ?>"><?= e(UserAdminController::STATUS[(int) $u['status']] ?? '') ?></span>
  <?php if ($u['handle']): ?><a class="btn btn-ghost btn-sm" href="/p/<?= e($u['handle']) ?>" target="_blank" rel="noopener">صفحه عمومی</a><?php endif; ?>
</section>

<section class="stats">
  <div class="stat"><b><?= fa_int((int) $wallet['balance']) ?></b><span>موجودی Stars</span></div>
  <div class="stat"><b><?= fa_int((int) ($counters['connections'] ?? 0)) ?></b><span>ارتباطات</span></div>
  <div class="stat"><b><?= fa_int($proposalCount) ?></b><span>پیشنهاد</span></div>
  <div class="stat"><b><?= fa_int((int) ($counters['letters_sent'] ?? 0)) ?></b><span>نامه ارسالی</span></div>
</section>

<?php if ($has('users.edit')): ?>
<section class="panel form">
  <h2>وضعیت حساب</h2>
  <?= $err('status') ?>
  <?php if ((int) $u['status'] !== 1 && ($u['status_reason'] ?? '') !== ''): ?>
    <p class="trust-reason">دلیل فعلی: <?= e((string) $u['status_reason']) ?><?php if (!empty($u['suspended_until'])): ?> · تا <?= e(fa_date((string) $u['suspended_until'])) ?><?php endif; ?></p>
  <?php endif; ?>
  <?php if ($reportCount > 0): ?><p class="muted"><a href="/admin/trust?user=<?= e($u['id']) ?>">⚑ <?= fa_int($reportCount) ?> گزارش تخلف درباره این کاربر</a></p><?php endif; ?>
  <form class="trust-status" method="post" action="/admin/users/<?= e($u['id']) ?>/status">
    <?= csrf_field() ?>
    <select class="select" name="status" aria-label="وضعیت جدید">
      <?php foreach ([1, 2, 3, 4] as $s): ?><option value="<?= $s ?>"<?= (int) $u['status'] === $s ? ' selected' : '' ?>><?= e(UserAdminController::STATUS[$s]) ?></option><?php endforeach; ?>
    </select>
    <select class="select" name="days" aria-label="مدت تعلیق">
      <option value="0">مدت تعلیق: تا برداشتن دستی</option>
      <?php foreach (UserAdminController::SUSPEND_DAYS as $d): ?><option value="<?= $d ?>"><?= fa_int($d) ?> روز</option><?php endforeach; ?>
    </select>
    <input class="input trust-reason-input" name="reason" maxlength="255" placeholder="دلیل (کاربر آن را می‌بیند)" aria-label="دلیل">
    <?= $pw ?>
    <button class="btn btn-sm" type="submit">ثبت وضعیت</button>
  </form>
  <p class="hint muted">«محدود»: فقط مشاهده؛ ارسال نامه، پیشنهاد و انتشار بسته است. «معلق»: خروج از همه دستگاه‌ها و پنهان‌شدن صفحه و پیشنهادها؛ اگر مدت انتخاب شود، پس از پایان آن با اولین ورود خودکار برداشته می‌شود. «مسدود»: مثل تعلیق اما دائمی. برای تعلیق و مسدودکردن رمز عبور لازم است.</p>
  <form method="post" action="/admin/users/<?= e($u['id']) ?>/verify"><?= csrf_field() ?>
    <button class="btn btn-ghost btn-sm" type="submit"><?= $u['business_verified_at'] ? 'برداشتن نشان «کسب‌وکار تأییدشده»' : 'دادن نشان «کسب‌وکار تأییدشده»' ?></button>
  </form>
</section>
<?php endif; ?>

<?php if ($has('wallet.credit') || $has('wallet.debit')): ?>
<section class="panel form">
  <h2>اصلاح موجودی Stars</h2>
  <?= $err('wallet') ?>
  <form class="filters-form admin-wallet" method="post" action="/admin/users/<?= e($u['id']) ?>/wallet">
    <?= csrf_field() ?>
    <select class="select" name="direction" aria-label="نوع"><?php if ($has('wallet.credit')): ?><option value="credit">افزایش</option><?php endif; ?><?php if ($has('wallet.debit')): ?><option value="debit">کاهش</option><?php endif; ?></select>
    <input class="input" name="stars" inputmode="numeric" placeholder="تعداد Star" aria-label="تعداد Star" required>
    <input class="input" name="note" placeholder="دلیل (در دفتر ثبت می‌شود)" aria-label="دلیل" required>
    <?= $pw ?>
    <button class="btn btn-sm" type="submit">ثبت</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <h2>آخرین تراکنش‌ها</h2>
  <?php if ($ledger === []): ?><p class="muted">تراکنشی ندارد.</p><?php else: ?>
    <ul class="list">
      <?php foreach ($ledger as $t): $a = (int) $t['amount']; ?>
        <li class="list-row"><div class="grow"><div class="title"><?= e(WalletService::REASON_LABELS[$t['reason']] ?? $t['reason']) ?></div><div class="meta"><span><?= e(fa_date($t['created_at'])) ?></span><span>مانده <?= fa_int((int) $t['balance_after']) ?></span></div></div>
          <b class="amount <?= $a >= 0 ? 'plus' : 'minus' ?>" dir="ltr"><?= $a >= 0 ? '+' : '−' ?><?= fa_int(abs($a)) ?></b></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($has('roles.manage')): ?>
<section class="panel form">
  <h2>نقش‌ها و حوزه دسترسی</h2>
  <?= $err('roles') ?>
  <form class="form" method="post" action="/admin/users/<?= e($u['id']) ?>/roles">
    <?= csrf_field() ?>
    <div class="chip-picks">
      <?php foreach ($roles as $r): ?><label class="chip-pick"><input type="checkbox" name="roles[]" value="<?= e($r['id']) ?>"<?= (int) $r['assigned'] ? ' checked' : '' ?>><span><?= e($r['name']) ?></span></label><?php endforeach; ?>
    </div>
    <div class="field">
      <label for="f-countries">کشورهای تحت پوشش (برای نقش‌های تیمی)</label>
      <select class="select" id="f-countries" name="countries[]" multiple size="6">
        <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>"<?= in_array($c['id'], $assigned, true) ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
      </select>
      <div class="hint">با Ctrl یا ⌘ چند کشور انتخاب کنید. کارمندی که دسترسی «تیم» دارد فقط کاربران همین کشورها را می‌بیند.</div>
    </div>
    <div class="form-actions"><?= $pw ?><button class="btn btn-sm" type="submit">ذخیره نقش‌ها</button></div>
  </form>
</section>
<?php endif; ?>

<?php if (isset($perms['users.impersonate']) && (int) $u['id'] !== (int) $user['id']): ?>
<section class="panel form">
  <h2>ورود به جای کاربر (پشتیبانی)</h2>
  <?= $err('impersonate') ?>
  <p class="muted">برای دیدن مشکل کاربر از دید خودش. بنر هشدار نمایش داده می‌شود و شروع و پایان در رویدادها ثبت می‌شود.</p>
  <form class="filters-form admin-wallet" method="post" action="/admin/users/<?= e($u['id']) ?>/impersonate">
    <?= csrf_field() ?>
    <input class="input" name="reason" placeholder="دلیل" aria-label="دلیل" required>
    <?= $pw ?>
    <button class="btn btn-ghost btn-sm" type="submit">ورود به حساب کاربر</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <h2>صفحه‌ها و ورودها</h2>
  <ul class="list">
    <?php foreach ($pages as $p): ?><li class="list-row"><span class="lang-badge"><?= e($p['code']) ?></span><div class="grow"><div class="title"><?= e($p['title']) ?></div></div></li><?php endforeach; ?>
    <?php foreach ($logins as $l): ?><li class="list-row"><div class="grow"><div class="meta"><span>ورود: <?= e($l['result']) ?></span><span><?= e(fa_date($l['created_at'])) ?></span></div></div></li><?php endforeach; ?>
  </ul>
</section>
<?= $this->partial('admin/_wrap_end') ?>
