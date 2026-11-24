<?php
/** @var array $u @var array $counters @var array $wallet @var array $ledger @var array $pages @var int $proposalCount @var array $roles
 *  @var array $assigned @var array $countries @var array $logins @var array $errors @var array $perms
 *  @var array $activeDays @var array $events @var array $titles */
use App\Modules\Admin\UserAdminController;
use App\Modules\Wallet\WalletService;
$has = static fn (string $p): bool => isset($perms[$p]);
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="alert alert-error" role="alert">' . e($errors[$k]) . '</div>' : '';
$av = media($u['avatar_path']);
$pw = '<input class="input narrow-wide" type="password" name="password" placeholder="رمز عبور شما" autocomplete="current-password" dir="ltr" required aria-label="رمز عبور شما">';
?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'users']) ?>
<?php
$st = (int) $u['status'];
$statusClass = [1 => 'ok', 2 => 'warn', 3 => 'bad', 4 => 'bad'][$st] ?? '';
$loginLabels = ['success' => ['ورود موفق', 'ok'], 'bad_password' => ['رمز اشتباه', 'bad'], 'locked' => ['قفل موقت', 'warn'], 'blocked' => ['مسدود', 'bad'], 'unknown' => ['ناشناس', 'warn']];
$pageStatus = [0 => 'پیش‌نویس', 2 => 'منتشرشده', 3 => 'مخفی', 4 => 'ردشده'];
$titleSlugs = \App\Modules\Users\TradeTitles::roleSlugs();
$myRoles = array_values(array_filter($roles, static fn (array $r): bool => (bool) (int) $r['assigned'] && !in_array($r['slug'], $titleSlugs, true)));
$canImpersonate = isset($perms['users.impersonate']) && (int) $u['id'] !== (int) $user['id'];
$canWallet = $has('wallet.credit') || $has('wallet.debit');
$tabs = ['overview' => 'نمای کلی'];
if ($has('roles.manage')) { $tabs['roles'] = 'نقش‌ها و دسترسی'; }
$tabs['wallet'] = 'کیف پول Stars';
if ($has('users.edit') || $canImpersonate) { $tabs['security'] = 'وضعیت و امنیت'; }
$open = 'overview';
foreach (['titles' => 'roles', 'roles' => 'roles', 'wallet' => 'wallet', 'status' => 'security', 'impersonate' => 'security'] as $errKey => $tabKey) {
    if (isset($errors[$errKey]) && isset($tabs[$tabKey])) { $open = $tabKey; break; }
}
?>
<link rel="stylesheet" href="<?= e(asset('admin-user.css')) ?>">
<div class="au">
  <header class="au-hero">
    <?php if ($av): ?><img class="au-avatar" src="<?= e($av) ?>" alt=""><?php else: ?><span class="au-avatar au-avatar-fallback"><?= e(initials($u['first_name'], $u['last_name'])) ?></span><?php endif; ?>
    <div class="au-id">
      <div class="au-name">
        <h1><?= e($u['first_name'] . ' ' . $u['last_name']) ?></h1>
        <span class="au-badge au-badge-<?= $statusClass ?>"><?= e(UserAdminController::STATUS[$st] ?? '') ?></span>
        <?php if ($u['business_verified_at']): ?><span class="au-badge au-badge-gold">✓ تأییدشده</span><?php endif; ?>
      </div>
      <p class="au-sub">
        <?= flag($u['country_code']) ?> <?= e($u['country_fa']) ?> · <?= e($u['language_fa']) ?>
        <?php foreach ($myRoles as $r): ?><span class="au-role-pill" title="<?= e($r['tip']) ?>"><?= e($r['label']) ?></span><?php endforeach; ?>
      </p>
      <?php if ($titles !== []): ?><p class="au-titles"><?php foreach ($titles as $t): ?><span class="au-title-pill"><?= flag($t['country_code']) ?> <?= e($t['text']) ?></span><?php endforeach; ?></p><?php endif; ?>
    </div>
    <div class="au-hero-actions">
      <?php if ($u['handle']): ?><a class="btn btn-ghost btn-sm" href="/p/<?= e($u['handle']) ?>" target="_blank" rel="noopener">صفحه عمومی ↗</a><?php endif; ?>
      <a class="btn btn-quiet btn-sm" href="/admin/users">همه کاربران</a>
    </div>
  </header>

  <?php foreach ($tabs as $key => $label): ?><input class="au-r" type="radio" name="au-tab" id="au-t-<?= $key ?>"<?= $open === $key ? ' checked' : '' ?>><?php endforeach; ?>
  <nav class="au-tabs" aria-label="بخش‌های صفحه کاربر">
    <?php foreach ($tabs as $key => $label): ?><label for="au-t-<?= $key ?>"><?= e($label) ?></label><?php endforeach; ?>
  </nav>

  <div class="au-panels">
    <!-- نمای کلی -->
    <section class="au-p au-p-overview">
      <div class="au-stats">
        <div class="au-stat"><b><?= fa_int((int) $wallet['balance']) ?></b><span>موجودی Stars</span></div>
        <div class="au-stat"><b><?= fa_int((int) ($counters['connections'] ?? 0)) ?></b><span>ارتباطات</span></div>
        <div class="au-stat"><b><?= fa_int($proposalCount) ?></b><span>پیشنهاد</span></div>
        <div class="au-stat"><b><?= fa_int((int) ($counters['letters_sent'] ?? 0)) ?></b><span>نامه ارسالی</span></div>
      </div>
      <div class="au-grid">
        <div class="au-card">
          <h2>اطلاعات حساب</h2>
          <dl class="au-dl">
            <div><dt>ایمیل</dt><dd dir="ltr"><bdi><?= e($u['email']) ?></bdi></dd></div>
            <div><dt>تلفن</dt><dd dir="ltr"><bdi>+<?= e($u['phone_cc'] . $u['phone']) ?></bdi></dd></div>
            <div><dt>عضویت</dt><dd><?= e(fa_date($u['created_at'])) ?></dd></div>
            <div><dt>آخرین فعالیت</dt><dd><?= e(fa_date($u['last_active_at'])) ?></dd></div>
            <?php if ($reportCount > 0): ?><div><dt>گزارش تخلف</dt><dd><a href="/admin/trust?user=<?= e($u['id']) ?>">⚑ <?= fa_int($reportCount) ?> مورد</a></dd></div><?php endif; ?>
          </dl>
        </div>
        <div class="au-card">
          <h2>صفحه‌های تجاری</h2>
          <?php if ($pages === []): ?><p class="au-empty">صفحه‌ای ندارد.</p><?php else: ?>
            <ul class="au-list">
              <?php foreach ($pages as $p):
                $inner = '<span class="au-lang">' . e($p['code']) . '</span><div class="au-li-main"><b>' . e($p['title']) . '</b></div>'
                    . '<span class="au-badge au-badge-' . ((int) $p['status'] === 2 ? 'ok' : '') . '">' . e($pageStatus[(int) $p['status']] ?? '') . '</span>';
                // Published pages open in a new tab at their public address (/p/{handle}/{lang}); staff with pages.view see the full page free.
                $href = $u['handle'] && (int) $p['status'] === 2 ? '/p/' . rawurlencode((string) $u['handle']) . '/' . rawurlencode(strtolower((string) $p['code'])) : null; ?>
                <li><?php if ($href): ?><a class="au-page-link" href="<?= e($href) ?>" target="_blank" rel="noopener" title="باز کردن صفحه در تب جدید"><?= $inner ?><span class="au-ext" aria-hidden="true">↗</span></a><?php else: ?><span class="au-page-link is-off" title="فقط صفحه منتشرشده آدرس عمومی دارد"><?= $inner ?></span><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div class="au-card au-span">
          <?php
            $tz = \App\Modules\Admin\MetricsService::timezone();
            $clock = static fn (string $utc): string => fa_num((new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('H:i'));
            $canReports = $has('reports.view');
          ?>
          <h2>فعالیت ۳۰ روز اخیر <small class="au-h2-note">· <?= fa_int(count($activeDays)) ?> روز از ۳۰ روز فعال بوده</small></h2>
          <div class="au-days" role="list" aria-label="روزهای فعال">
            <?php for ($i = 29; $i >= 0; $i--): $d = \App\Modules\Admin\MetricsService::today(-$i); $on = $activeDays[$d] ?? null;
              $tip = \App\Core\Support\Jalali::full($d) . ($on ? ' · از ' . $clock($on[0]) . ' تا ' . $clock($on[1]) : ' · فعال نبود'); ?>
              <?php if ($on && $canReports): ?><a role="listitem" class="au-day is-on" href="/admin/reports/active?day=<?= e($d) ?>" title="<?= e($tip) ?>"></a><?php else: ?><span role="listitem" class="au-day<?= $on ? ' is-on' : '' ?>" title="<?= e($tip) ?>"></span><?php endif; ?>
            <?php endfor; ?>
          </div>
          <div class="au-days-axis"><span>۳۰ روز پیش</span><span>امروز</span></div>
          <h3 class="au-sub-title">آخرین کارها</h3>
          <?php if ($events === []): ?><p class="au-empty">کاری (نامه، پیشنهاد، دیدن صفحه، ارتباط، خرج Stars) ثبت نشده؛ فقط وارد شده یا صفحه‌ها را مرور کرده است.</p><?php else: ?>
            <ul class="au-list au-list-2">
              <?php foreach ($events as $ev):
                $label = \App\Modules\Analytics\ActiveDays::EVENTS[$ev['event']] ?? $ev['event'];
                $data = json_decode((string) ($ev['data'] ?? ''), true) ?: [];
                if ($ev['event'] === 'stars_spent' && isset($data['stars'])) {
                    $label .= ' (' . fa_int((int) $data['stars']) . ' · ' . (WalletService::REASON_LABELS[$data['reason'] ?? ''] ?? ($data['reason'] ?? '')) . ')';
                }
                $peer = trim((string) ($ev['first_name'] ?? '') . ' ' . (string) ($ev['last_name'] ?? '')); ?>
                <li><span class="au-dot plus" aria-hidden="true">•</span><div class="au-li-main"><b><?= e($label) ?><?= $peer !== '' && $ev['event'] !== 'stars_spent' ? ' · ' . e($peer) : '' ?></b><small><?= e(fa_date($ev['created_at'])) ?></small></div></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div class="au-card au-span">
          <h2>ورودهای اخیر</h2>
          <?php if ($logins === []): ?><p class="au-empty">ورودی ثبت نشده.</p><?php else: ?>
            <ul class="au-list au-list-2">
              <?php foreach ($logins as $l): [$ll, $lc] = $loginLabels[$l['result']] ?? [$l['result'], '']; ?>
                <li><span class="au-dot <?= $lc === 'ok' ? 'plus' : 'minus' ?>" aria-hidden="true"><?= $lc === 'ok' ? '✓' : '!' ?></span><div class="au-li-main"><b><?= e($ll) ?></b><small><?= e(fa_date($l['created_at'])) ?></small></div></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </section>

<?php if ($has('roles.manage')): ?>
    <!-- نقش‌ها -->
    <section class="au-p au-p-roles">
      <div class="au-card au-titles-card">
        <h2>عنوان‌های رسمی آراد برندینگ</h2>
        <p class="au-hint">کشور (و برای سرپرست تجارت، محصول) را انتخاب کنید تا عنوان ساخته شود؛ مثلاً «مدیر تجارت عراق آراد برندینگ». عنوان روی صفحه تجاری کاربر نشان داده می‌شود و دسترسی مدیریتی‌اش فقط به کاربران، صفحه‌ها و پیشنهادهای همان کشور است.</p>
        <?= $err('titles') ?>
        <?php if ($titles !== []): ?>
          <ul class="au-list au-title-list">
            <?php foreach ($titles as $t): ?>
              <li>
                <span class="au-lang"><?= flag($t['country_code']) ?></span>
                <div class="au-li-main"><b><?= e($t['text']) ?></b><small>از <?= e(fa_date($t['created_at'], false)) ?> · <?= e(\App\Modules\Users\TradeTitles::ROLE_INFO[\App\Modules\Users\TradeTitles::KINDS[$t['kind']][0]][1] ?? '') ?></small></div>
                <details class="au-title-remove"><summary>گرفتن عنوان</summary>
                  <form method="post" action="/admin/users/<?= e($u['id']) ?>/titles/<?= e($t['id']) ?>/delete"><?= csrf_field() ?><?= $pw ?><button class="btn btn-sm btn-danger" type="submit">گرفتن</button></form>
                </details>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <form class="au-form au-title-form" method="post" action="/admin/users/<?= e($u['id']) ?>/titles">
          <?= csrf_field() ?>
          <select class="select" name="country" aria-label="کشور" required>
            <option value="">کشور…</option>
            <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en']) ?>"><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?>
          </select>
          <select class="select" name="title" aria-label="عنوان" required>
            <option value="">عنوان…</option>
            <?php foreach (\App\Modules\Users\TradeTitles::KINDS as $kind => $def): ?><option value="<?= e($kind) ?>"><?= e(\App\Modules\Users\TradeTitles::text($kind, 'fa', '[کشور]', '[محصول]')) ?></option><?php endforeach; ?>
          </select>
          <input class="input" name="product" maxlength="100" placeholder="محصول (فقط برای سرپرست تجارت؛ مثلاً پسته)" aria-label="محصول">
          <?= $pw ?>
          <button class="btn btn-sm" type="submit">دادن عنوان</button>
        </form>
        <p class="au-hint au-hint-foot">«مدیر انحصاری تجارت» برای هر کشور فقط به یک نفر داده می‌شود. با گرفتن عنوان، دسترسی آن کشور هم گرفته می‌شود (مگر عنوان دیگری در همان کشور داشته باشد).</p>
      </div>
      <div class="au-card">
        <h2>نقش‌ها و دسترسی</h2>
        <p class="au-hint">نقش‌های این کاربر را انتخاب کنید. ماوس را روی هر کارت نگه دارید تا دسترسی‌های آن را ببینید.</p>
        <?= $err('roles') ?>
        <form method="post" action="/admin/users/<?= e($u['id']) ?>/roles">
          <?= csrf_field() ?>
          <?php $isUserRole = static fn (array $r): bool => str_ends_with((string) $r['slug'], 'customer');
    foreach ([['نقش‌های مدیریتی', false], ['نقش‌های کاربری', true]] as [$groupTitle, $userRoles]): ?>
      <h3 class="role-group-title"><?= e($groupTitle) ?></h3>
      <div class="role-grid">
        <?php foreach ($roles as $r): if ($isUserRole($r) !== $userRoles || in_array($r['slug'], $titleSlugs, true)) { continue; } ?>
          <label class="role-card" title="<?= e($r['tip']) ?>">
            <input type="checkbox" name="roles[]" value="<?= e($r['id']) ?>"<?= (int) $r['assigned'] ? ' checked' : '' ?>>
            <span class="role-card-body">
              <span class="role-card-head"><b><?= e($r['label']) ?></b><i class="role-tick" aria-hidden="true">✓</i></span>
              <span class="role-card-about"><?= e($r['about']) ?></span>
              <?php if ($r['perm_count'] !== 0): ?><span class="role-card-count"><?= $r['perm_count'] < 0 ? 'همه دسترسی‌ها' : e(fa_int($r['perm_count'])) . ' دسترسی' ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="field">
      <span class="label">کشورهای تحت پوشش (برای نقش‌های تیمی)</span>
      <div class="chip-picks country-picks">
        <?php foreach ($countries as $c): ?><label class="chip-pick"><input type="checkbox" name="countries[]" value="<?= e($c['id']) ?>"<?= in_array($c['id'], $assigned, true) ? ' checked' : '' ?>><span><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></span></label><?php endforeach; ?>
      </div>
      <div class="hint">کارمندی که دسترسی «تیم» دارد فقط کاربران همین کشورها را می‌بیند. اگر نقش او تیمی نیست، نیازی به انتخاب کشور نیست.</div>
    </div>
          <div class="au-form-foot"><?= $pw ?><button class="btn btn-sm" type="submit">ذخیره نقش‌ها</button></div>
        </form>
      </div>
    </section>
<?php endif; ?>

    <!-- کیف پول -->
    <section class="au-p au-p-wallet">
      <div class="au-grid au-grid-wide">
        <div class="au-card">
          <h2>آخرین تراکنش‌ها</h2>
          <?php if ($ledger === []): ?><p class="au-empty">تراکنشی ندارد.</p><?php else: ?>
            <ul class="au-list">
              <?php foreach ($ledger as $t): $a = (int) $t['amount']; ?>
                <li>
                  <span class="au-dot <?= $a >= 0 ? 'plus' : 'minus' ?>" aria-hidden="true"><?= $a >= 0 ? '↑' : '↓' ?></span>
                  <div class="au-li-main"><b><?= e(WalletService::REASON_LABELS[$t['reason']] ?? $t['reason']) ?></b><small><?= e(fa_date($t['created_at'])) ?> · مانده <?= fa_int((int) $t['balance_after']) ?></small></div>
                  <b class="au-amount <?= $a >= 0 ? 'plus' : 'minus' ?>" dir="ltr"><?= $a >= 0 ? '+' : '−' ?><?= fa_int(abs($a)) ?></b>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
<?php if ($canWallet): ?>
        <div class="au-card">
          <h2>اصلاح موجودی</h2>
          <p class="au-hint">موجودی فعلی: <b><?= fa_int((int) $wallet['balance']) ?></b> Star</p>
          <?= $err('wallet') ?>
          <form class="au-form" method="post" action="/admin/users/<?= e($u['id']) ?>/wallet">
            <?= csrf_field() ?>
            <select class="select" name="direction" aria-label="نوع"><?php if ($has('wallet.credit')): ?><option value="credit">افزایش</option><?php endif; ?><?php if ($has('wallet.debit')): ?><option value="debit">کاهش</option><?php endif; ?></select>
            <input class="input" name="stars" inputmode="numeric" placeholder="تعداد Star" aria-label="تعداد Star" required>
            <input class="input" name="note" placeholder="دلیل (در دفتر ثبت می‌شود)" aria-label="دلیل" required>
            <?= $pw ?>
            <button class="btn btn-sm" type="submit">ثبت</button>
          </form>
        </div>
<?php endif; ?>
      </div>
    </section>

<?php if ($has('users.edit') || $canImpersonate): ?>
    <!-- وضعیت و امنیت -->
    <section class="au-p au-p-security">
      <div class="au-grid au-grid-wide">
<?php if ($has('users.edit')): ?>
        <div class="au-card">
          <h2>وضعیت حساب</h2>
          <?= $err('status') ?>
          <?php if ($st !== 1 && ($u['status_reason'] ?? '') !== ''): ?>
            <p class="au-note">دلیل فعلی: <?= e((string) $u['status_reason']) ?><?php if (!empty($u['suspended_until'])): ?> · تا <?= e(fa_date((string) $u['suspended_until'])) ?><?php endif; ?></p>
          <?php endif; ?>
          <form class="au-form" method="post" action="/admin/users/<?= e($u['id']) ?>/status">
            <?= csrf_field() ?>
            <div class="au-seg" role="radiogroup" aria-label="وضعیت جدید">
              <?php foreach ([1, 2, 3, 4] as $sv): ?><label><input type="radio" name="status" value="<?= $sv ?>"<?= $st === $sv ? ' checked' : '' ?>><span class="au-seg-<?= $sv ?>"><?= e(UserAdminController::STATUS[$sv]) ?></span></label><?php endforeach; ?>
            </div>
            <select class="select" name="days" aria-label="مدت تعلیق">
              <option value="0">مدت تعلیق: تا برداشتن دستی</option>
              <?php foreach (UserAdminController::SUSPEND_DAYS as $d): ?><option value="<?= $d ?>"><?= fa_int($d) ?> روز</option><?php endforeach; ?>
            </select>
            <input class="input" name="reason" maxlength="255" placeholder="دلیل (کاربر آن را می‌بیند)" aria-label="دلیل">
            <?= $pw ?>
            <button class="btn btn-sm" type="submit">ثبت وضعیت</button>
          </form>
          <details class="au-help"><summary>معنی هر وضعیت</summary>
            <p><b>محدود:</b> فقط مشاهده؛ ارسال نامه، پیشنهاد و انتشار بسته است.</p>
            <p><b>معلق:</b> خروج از همه دستگاه‌ها و پنهان‌شدن صفحه و پیشنهادها. اگر مدت انتخاب شود، پس از پایان آن با اولین ورود خودکار برداشته می‌شود.</p>
            <p><b>مسدود:</b> مثل تعلیق اما دائمی. برای تعلیق و مسدودکردن رمز عبور لازم است.</p>
          </details>
        </div>
        <div class="au-card">
          <h2>نشان تأیید کسب‌وکار</h2>
          <p class="au-hint"><?= $u['business_verified_at'] ? 'این کاربر نشان «کسب‌وکار تأییدشده» دارد.' : 'این کاربر هنوز نشان «کسب‌وکار تأییدشده» ندارد.' ?></p>
          <form method="post" action="/admin/users/<?= e($u['id']) ?>/verify"><?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit"><?= $u['business_verified_at'] ? 'برداشتن نشان' : 'دادن نشان' ?></button>
          </form>
<?php if ($canImpersonate): ?>
          <hr class="au-sep">
          <h2>ورود به جای کاربر</h2>
          <p class="au-hint">برای دیدن مشکل از دید خود کاربر. بنر هشدار نمایش داده می‌شود و شروع و پایان ثبت می‌شود.</p>
          <?= $err('impersonate') ?>
          <form class="au-form" method="post" action="/admin/users/<?= e($u['id']) ?>/impersonate">
            <?= csrf_field() ?>
            <input class="input" name="reason" placeholder="دلیل" aria-label="دلیل" required>
            <?= $pw ?>
            <button class="btn btn-ghost btn-sm" type="submit">ورود به حساب کاربر</button>
          </form>
<?php endif; ?>
        </div>
<?php elseif ($canImpersonate): ?>
        <div class="au-card">
          <h2>ورود به جای کاربر</h2>
          <p class="au-hint">برای دیدن مشکل از دید خود کاربر. بنر هشدار نمایش داده می‌شود و شروع و پایان ثبت می‌شود.</p>
          <?= $err('impersonate') ?>
          <form class="au-form" method="post" action="/admin/users/<?= e($u['id']) ?>/impersonate">
            <?= csrf_field() ?>
            <input class="input" name="reason" placeholder="دلیل" aria-label="دلیل" required>
            <?= $pw ?>
            <button class="btn btn-ghost btn-sm" type="submit">ورود به حساب کاربر</button>
          </form>
        </div>
<?php endif; ?>
      </div>
    </section>
<?php endif; ?>
  </div>
</div>
<?= $this->partial('admin/_wrap_end') ?>
