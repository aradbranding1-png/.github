<?php
/** @var array $prices @var array $actions @var bool $publishEnabled @var string $chargeMode @var int $windowMinutes @var int $periodHours
 *  @var int $lifetimeDays @var int $tomanPerStar @var array $packages @var int $maxRecipients @var int $dailyCampaigns @var int $uploadKb @var bool $showStats @var bool $autoBackup @var int $retention @var array $errors */
$val = static fn (string $a, string $s): int => (int) ($prices[$a][$s] ?? 0);
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$cls = static fn (string $k): string => isset($errors[$k]) ? ' has-error' : '';
?>
<div class="mailbox">
  <?= $this->partial('admin/_nav', ['perms' => $perms, 'active' => 'settings']) ?>
  <div class="mail-content stack">
<form class="stack" method="post" action="/admin/settings">
  <?= csrf_field() ?>
  <?php if ($errors): ?><div class="alert alert-error" role="alert">برخی مقادیر معتبر نیست. موارد قرمز را اصلاح کنید.</div><?php endif; ?>

  <section class="panel form">
    <div><h2>پیشنهادها در فید</h2><p class="muted">هزینه انتشار فقط یک بار، هنگام اولین انتشار هر پیشنهاد، کسر می‌شود.</p></div>
    <div class="settings-grid">
      <div class="field">
        <span class="label">هزینه انتشار</span>
        <label class="check"><input type="checkbox" name="publish_enabled" value="1"<?= $publishEnabled ? ' checked' : '' ?>> فعال باشد</label>
      </div>
      <div class="field<?= $cls('publish_price') ?>"><label for="publish_price">مبلغ انتشار (Star)</label><input class="input" id="publish_price" name="publish_price" inputmode="numeric" value="<?= e($val('proposal_publish', 'domestic')) ?>"><?= $err('publish_price') ?></div>
      <div class="field<?= $cls('lifetime_days') ?>"><label for="lifetime_days">مدت نمایش در فید (روز)</label><input class="input" id="lifetime_days" name="lifetime_days" inputmode="numeric" value="<?= e($lifetimeDays) ?>"><?= $err('lifetime_days') ?></div>
    </div>
  </section>

  <section class="panel form">
    <div><h2>خرید Stars</h2><p class="muted">قیمت هر Star و بسته‌های خرید (به‌همراه Star هدیه هر بسته).</p></div>
    <label class="switch"><input type="checkbox" name="purchase_enabled" value="1" role="switch"<?= !empty($purchaseEnabled) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span>خرید Stars از درگاه پرداخت فعال باشد <small class="muted">(اگر خاموش باشد، بخش خرید و همه دکمه‌های «خرید Stars» برای کاربران پنهان می‌شود و خرید ممکن نیست)</small></span></label>
    <div class="field<?= $cls('toman_per_star') ?>"><label for="toman_per_star">قیمت هر Star (تومان)</label><input class="input narrow" id="toman_per_star" name="toman_per_star" inputmode="numeric" value="<?= e($tomanPerStar) ?>"><div class="hint">الان: هر Star = <?= e(toman($tomanPerStar * 10)) ?></div><?= $err('toman_per_star') ?></div>
    <?= $err('packages') ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Star</th><th>هدیه (Star)</th><th>قیمت</th><th>فعال</th><th>حذف</th></tr></thead>
        <tbody>
          <?php foreach ($packages as $p): $id = (int) $p['id']; ?>
            <tr>
              <td><input class="input narrow" name="pkg[<?= $id ?>][stars]" inputmode="numeric" value="<?= e($p['stars']) ?>" aria-label="تعداد Star"></td>
              <td><input class="input narrow" name="pkg[<?= $id ?>][bonus]" inputmode="numeric" value="<?= e($p['bonus_stars']) ?>" aria-label="Star هدیه"></td>
              <td class="muted"><?= e(toman((int) $p['stars'] * $tomanPerStar * 10)) ?></td>
              <td><input type="checkbox" name="pkg[<?= $id ?>][active]" value="1"<?= (int) $p['active'] === 1 ? ' checked' : '' ?> aria-label="فعال"></td>
              <td><input type="checkbox" name="pkg[<?= $id ?>][delete]" value="1" aria-label="حذف"></td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td><input class="input narrow" name="new_stars" inputmode="numeric" placeholder="بسته جدید" aria-label="Star بسته جدید"></td>
            <td><input class="input narrow" name="new_bonus" inputmode="numeric" placeholder="۰" aria-label="هدیه بسته جدید"></td>
            <td colspan="3" class="muted">برای افزودن بسته، این ردیف را پر کنید.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel form">
    <h2>تعرفه‌ها (Star)</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>قابلیت</th><th>داخلی</th><th>بین‌المللی</th></tr></thead>
        <tbody>
          <?php foreach ($actions as $key => $label): ?>
            <tr>
              <td><?= e($label) ?></td>
              <?php foreach (['domestic', 'international'] as $scope): $name = "price_{$key}_{$scope}"; ?>
                <td><input class="input narrow" name="<?= e($name) ?>" inputmode="numeric" aria-label="<?= e($label . ' ' . ($scope === 'domestic' ? 'داخلی' : 'بین‌المللی')) ?>" value="<?= e($val($key, $scope)) ?>"><?= $err($name) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint muted">تغییر قیمت فقط روی عملیات بعدی اثر دارد؛ تراکنش‌های گذشته با قیمت همان زمان در دفتر ثبت شده‌اند.</p>
  </section>

  <section class="panel form">
    <h2>مشاهده صفحه تجاری</h2>
    <label class="switch"><input type="checkbox" name="unlock_charge" value="1" role="switch"<?= !empty($unlockCharge) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span>برای مشاهده کامل صفحه تجاری Stars کسر شود <small class="muted">(اگر خاموش باشد، همه تجار واردشده صفحه کامل را رایگان می‌بینند و تنظیمات زیر اعمال نمی‌شود)</small></span></label>
    <div class="settings-grid">
      <div class="field">
        <label class="check"><input type="radio" name="charge_mode" value="every_view"<?= $chargeMode === 'every_view' ? ' checked' : '' ?>> هر مشاهده هزینه دارد</label>
        <div class="field<?= $cls('window_minutes') ?>"><label for="window_minutes">رفرش رایگان تا (دقیقه)</label><input class="input" id="window_minutes" name="window_minutes" inputmode="numeric" value="<?= e($windowMinutes) ?>"><?= $err('window_minutes') ?></div>
      </div>
      <div class="field">
        <label class="check"><input type="radio" name="charge_mode" value="once_per_period"<?= $chargeMode === 'once_per_period' ? ' checked' : '' ?>> یک بار در هر دوره</label>
        <div class="field<?= $cls('period_hours') ?>"><label for="period_hours">دوره (ساعت)</label><input class="input" id="period_hours" name="period_hours" inputmode="numeric" value="<?= e($periodHours) ?>"><?= $err('period_hours') ?></div>
      </div>
    </div>
  </section>

  <section class="panel form">
    <h2>ارسال گروهی نامه و پیشنهاد</h2>
    <div class="settings-grid">
      <div class="field<?= $cls('max_recipients') ?>"><label for="max_recipients">حداکثر گیرنده در هر ارسال</label><input class="input" id="max_recipients" name="max_recipients" inputmode="numeric" value="<?= e($maxRecipients) ?>"><?= $err('max_recipients') ?></div>
      <div class="field<?= $cls('daily_campaigns') ?>"><label for="daily_campaigns">حداکثر ارسال گروهی هر کاربر در ۲۴ ساعت</label><input class="input" id="daily_campaigns" name="daily_campaigns" inputmode="numeric" value="<?= e($dailyCampaigns) ?>"><?= $err('daily_campaigns') ?></div>
    </div>
  </section>

  <section class="panel form">
    <h2>صفحه اصلی، اعلان‌ها و پشتیبان‌گیری</h2>
    <label class="switch"><input type="checkbox" name="show_stats" value="1" role="switch"<?= !empty($showStats) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span>نمایش آمار عمومی (تعداد اعضا، کشورها، صفحه‌ها، پیشنهادها و ارتباطات) در صفحه اصلی</span></label>
    <label class="switch"><input type="checkbox" name="daily_digest" value="1" role="switch"<?= !empty($dailyDigest) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span>اعلان خلاصه روزانه برای کاربران («۳ پیشنهاد جدید مرتبط با حوزه شما»، «پیشنهادهای شما امروز ۱۲ بار دیده شد»)</span></label>
    <label class="switch"><input type="checkbox" name="auto_backup" value="1" role="switch"<?= !empty($autoBackup) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span>پشتیبان خودکار روزانه از دیتابیس</span></label>
    <div class="field<?= $cls('retention_days') ?>"><label for="retention_days">نگه‌داری پشتیبان‌ها (روز)</label><input class="input narrow" id="retention_days" name="retention_days" inputmode="numeric" value="<?= e($retention ?? 7) ?>"><?= $err('retention_days') ?></div>
  </section>

  <section class="panel form">
    <h2>بارگذاری تصویر</h2>
    <div class="field<?= $cls('upload_kb') ?>"><label for="upload_kb">حداکثر حجم هر تصویر (کیلوبایت)</label><input class="input narrow" id="upload_kb" name="upload_kb" inputmode="numeric" value="<?= e($uploadKb) ?>"><?= $err('upload_kb') ?></div>
  </section>

  <div class="form-actions sticky-actions"><button class="btn" type="submit">ذخیره تنظیمات</button></div>
</form>

  </div>
</div>
