<?php
/**
 * کیف پول مشتریان — search, pick a customer, adjust Stars with a reason.
 * @var string $q @var array $results @var array|null $customer @var array $ledger @var bool $canCredit @var bool $canDebit
 * @var string $token @var array $errors @var array $old @var array $perms
 */
use App\Modules\Wallet\WalletService;

$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$cls = static fn (string $k): string => isset($errors[$k]) ? ' has-error' : '';
$name = static fn (array $u): string => trim(($u['company_name'] ?? '') !== '' && $u['company_name'] !== null
    ? $u['first_name'] . ' ' . $u['last_name'] . ' · ' . $u['company_name'] : $u['first_name'] . ' ' . $u['last_name']);
$avatar = static function (array $u): string {
    $url = media($u['avatar_path'] ?? null);
    return $url ? '<img class="avatar" src="' . e($url) . '" alt="">' : '<span class="avatar">' . e(initials($u['first_name'] ?? '', $u['last_name'] ?? '')) . '</span>';
};
$dir = ($old['direction'] ?? '') === 'debit' ? 'debit' : ($canCredit ? 'credit' : 'debit');
?>
<div class="mailbox">
  <?= $this->partial('admin/_nav', ['perms' => $perms, 'active' => 'wallet']) ?>
  <div class="mail-content stack">
    <section class="panel">
      <div class="panel-head">
        <div><h2>کیف پول مشتریان</h2><p class="muted">مشتری را با نام، نام شرکت، نشانی صفحه، ایمیل یا شماره موبایل پیدا کنید و موجودی Stars او را با ذکر توضیح افزایش یا کاهش دهید. هر تغییر در گردش حساب مشتری با همین توضیح دیده می‌شود و برایش اعلان می‌رود.</p></div>
      </div>
      <form class="wa-search" method="get" action="/admin/wallet" role="search">
        <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="نام، ایمیل، موبایل یا نشانی صفحه…" aria-label="جستجوی مشتری" autocomplete="off" enterkeyhint="search" minlength="2">
        <button class="btn btn-sm" type="submit">جستجو</button>
      </form>
    </section>

    <?php if ($customer !== null): ?>
      <section class="panel wa-customer">
        <div class="wa-head">
          <?= $avatar($customer) ?>
          <div class="grow">
            <h3><?= e($name($customer)) ?></h3>
            <div class="meta"><span><?= e(flag($customer['country_code']) . ' ' . $customer['country_fa']) ?></span><span class="ltr"><?= e($customer['email']) ?></span><span class="ltr">+<?= e($customer['phone_cc']) ?> <?= e($customer['phone']) ?></span></div>
          </div>
          <a class="btn btn-ghost btn-sm" href="/admin/users/<?= e($customer['id']) ?>">پرونده کاربر</a>
        </div>
        <div class="wa-stats">
          <div><small>موجودی فعلی</small><b>⭐ <?= fa_int((int) $customer['balance']) ?></b></div>
          <div><small>خرید/شارژ کل</small><b><?= fa_int((int) $customer['bought']) ?></b></div>
          <div><small>مصرف کل</small><b><?= fa_int((int) $customer['spent']) ?></b></div>
        </div>

        <?php if ($canCredit || $canDebit): ?>
          <form class="form wa-form" method="post" action="/admin/wallet/<?= e($customer['id']) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <fieldset class="ff-group">
              <legend>نوع تغییر</legend>
              <div class="wa-dir">
                <?php if ($canCredit): ?><label class="wa-opt wa-plus"><input type="radio" name="direction" value="credit"<?= $dir === 'credit' ? ' checked' : '' ?>><span><b>+</b> افزایش موجودی</span></label><?php endif; ?>
                <?php if ($canDebit): ?><label class="wa-opt wa-minus"><input type="radio" name="direction" value="debit"<?= $dir === 'debit' ? ' checked' : '' ?>><span><b>−</b> کاهش موجودی</span></label><?php endif; ?>
              </div>
            </fieldset>
            <div class="row row-2">
              <div class="field<?= $cls('stars') ?>"><label for="wa-stars">تعداد Star</label><input class="input" id="wa-stars" name="stars" inputmode="numeric" dir="ltr" value="<?= e($old['stars'] ?? '') ?>" required><?= $err('stars') ?></div>
              <div class="field<?= $cls('password') ?>"><label for="wa-pw">رمز عبور شما</label><input class="input" id="wa-pw" name="password" type="password" autocomplete="current-password" dir="ltr" required><div class="hint">برای تأیید تغییر مالی.</div><?= $err('password') ?></div>
            </div>
            <div class="field<?= $cls('note') ?>"><label for="wa-note">توضیح</label><textarea class="textarea" id="wa-note" name="note" rows="2" maxlength="255" required placeholder="مثلاً: شارژ بابت سفارش شماره ۱۲۴۵ / جبران خطای سامانه"><?= e($old['note'] ?? '') ?></textarea><div class="hint">مشتری این توضیح را در گردش حساب و اعلان خود می‌بیند.</div><?= $err('note') ?></div>
            <div class="form-actions"><button class="btn" type="submit">ثبت تغییر موجودی</button></div>
          </form>
        <?php endif; ?>

        <h3 class="wa-sub">آخرین تراکنش‌ها</h3>
        <?php if ($ledger === []): ?><p class="muted">تراکنشی ندارد.</p><?php else: ?>
          <ul class="list">
            <?php foreach ($ledger as $t): $a = (int) $t['amount']; $byAdmin = in_array((int) $t['type'], [WalletService::T_ADMIN_CREDIT, WalletService::T_ADMIN_DEBIT], true); ?>
              <li class="list-row">
                <div class="grow">
                  <div class="title"><?= e($byAdmin ? WalletService::TYPE_LABELS[(int) $t['type']] : (WalletService::REASON_LABELS[$t['reason']] ?? $t['reason'])) ?></div>
                  <?php if (($t['note'] ?? '') !== ''): ?><div class="tx-note">توضیح: <?= e((string) $t['note']) ?></div><?php endif; ?>
                  <div class="meta"><span><?= e(fa_date($t['created_at'])) ?></span><span>مانده <?= fa_int((int) $t['balance_after']) ?></span><?php if ($t['actor_first'] !== null): ?><span>ثبت: <?= e(trim($t['actor_first'] . ' ' . $t['actor_last'])) ?></span><?php endif; ?></div>
                </div>
                <b class="amount <?= $a >= 0 ? 'plus' : 'minus' ?>" dir="ltr"><?= $a >= 0 ? '+' : '−' ?><?= fa_int(abs($a)) ?></b>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php if ($q !== ''): ?>
    <section class="panel">
      <h3 class="wa-sub wa-sub-top">نتیجه جستجو<?= $results !== [] ? ' (' . fa_int(count($results)) . ')' : '' ?></h3>
        <?php if ($results === []): ?>
          <p class="muted wa-none">مشتری‌ای با «<?= e($q) ?>» پیدا نشد.</p>
        <?php else: ?>
          <ul class="list wa-results">
            <?php foreach ($results as $u): ?>
              <li><a class="list-row<?= $customer !== null && (int) $customer['id'] === (int) $u['id'] ? ' is-picked' : '' ?>" href="/admin/wallet?<?= e(http_build_query(['q' => $q, 'user' => $u['id']])) ?>">
                <?= $avatar($u) ?>
                <span class="grow"><span class="title"><?= e($name($u)) ?></span>
                  <span class="meta"><span><?= e(flag($u['country_code'])) ?></span><?php if ($u['handle']): ?><bdi>@<?= e($u['handle']) ?></bdi><?php endif; ?><span class="ltr">+<?= e($u['phone_cc']) ?> <?= e($u['phone']) ?></span></span></span>
                <b class="wa-bal">⭐ <?= fa_int((int) $u['balance']) ?></b>
              </a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
          </section>
    <?php endif; ?>
  </div>
</div>
