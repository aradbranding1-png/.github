<?php
/**
 * اتصال API — keys for Arad Contact and other partner systems.
 * @var array $clients @var array $recent @var array $scopes @var array|null $newKey @var string $baseUrl @var array $errors @var array $old @var array $perms
 */
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$cls = static fn (string $k): string => isset($errors[$k]) ? ' has-error' : '';
$picked = (array) ($old['scopes'] ?? array_keys($scopes));
$endpoint = ($baseUrl !== '' ? $baseUrl : 'https://aradbranding.app') . '/api/v1';
$names = ['wallet.charge' => 'شارژ کیف پول', 'users.lookup' => 'استعلام حساب', 'users.credentials' => 'صدور رمز ورود', 'users.read' => 'استعلام حساب'];
?>
<div class="mailbox">
  <?= $this->partial('admin/_nav', ['perms' => $perms, 'active' => 'api']) ?>
  <div class="mail-content stack">
    <?php if ($newKey !== null): ?>
      <section class="panel api-newkey" role="status">
        <h2>کلید «<?= e($newKey['name']) ?>» ساخته شد</h2>
        <p class="muted">این کلید فقط همین یک بار نمایش داده می‌شود. آن را در تنظیمات آراد کانتکت وارد کنید و جای امنی نگه دارید.</p>
        <div class="api-key"><code dir="ltr"><?= e($newKey['key']) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($newKey['key']) ?>">کپی</button></div>
      </section>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>اتصال API</h2>
          <p class="muted">با این کلیدها سامانه آراد کانتکت (یا هر سامانه دیگری که مجاز کنید) هنگام ثبت سفارش، کیف پول مشتری را با شماره موبایل شارژ می‌کند؛ اگر مشتری حساب نداشته باشد، حساب برایش ساخته می‌شود و اطلاعات ورود برای ارسال در تیکت برمی‌گردد.</p>
        </div>
      </div>
      <div class="api-endpoints">
        <div><b>POST</b><code dir="ltr"><?= e($endpoint) ?>/wallet/charge</code><span>شارژ کیف پول (و ساخت حساب)</span></div>
        <div><b>GET</b><code dir="ltr"><?= e($endpoint) ?>/users/lookup?mobile=…</code><span>استعلام حساب و موجودی</span></div>
        <div><b>POST</b><code dir="ltr"><?= e($endpoint) ?>/users/credentials</code><span>صدور رمز ورود تازه</span></div>
      </div>
      <p class="hint muted">احراز هویت: سربرگ <code dir="ltr">Authorization: Bearer ab_…</code> · راهنمای کامل با نمونه درخواست‌ها: پرونده <code dir="ltr">docs/API-arad-contact.md</code> در بسته بروزرسانی.</p>
    </section>

    <section class="panel">
      <h2>کلیدها</h2>
      <?= $err('revoke') ?>
      <?php if ($clients === []): ?>
        <p class="muted">هنوز کلیدی ساخته نشده است.</p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($clients as $c): $live = (int) $c['active'] === 1 && $c['revoked_at'] === null; ?>
            <li class="list-row api-row<?= $live ? '' : ' is-off' ?>">
              <span class="api-dot<?= $live ? ' on' : '' ?>" aria-hidden="true"></span>
              <div class="grow">
                <div class="title"><?= e($c['name']) ?> <code dir="ltr">ab_<?= e($c['key_prefix']) ?>_…</code></div>
                <div class="meta">
                  <span><?= e(implode('، ', array_map(static fn (string $s): string => $names[$s] ?? $s, array_filter(explode(',', (string) $c['scopes']))))) ?></span>
                  <?php if ($c['ip_allowlist']): ?><span dir="ltr">IP: <?= e($c['ip_allowlist']) ?></span><?php endif; ?>
                  <span><?= fa_int((int) $c['requests']) ?> درخواست · <?= fa_int((int) $c['stars']) ?> Star شارژ</span>
                  <span><?= $c['last_used_at'] ? 'آخرین استفاده: ' . e(fa_date($c['last_used_at'])) : 'هنوز استفاده نشده' ?></span>
                  <?php if (!$live): ?><span class="chip">باطل‌شده</span><?php endif; ?>
                </div>
              </div>
              <?php if ($live): ?>
                <details class="api-revoke">
                  <summary class="btn btn-quiet btn-sm">باطل‌کردن</summary>
                  <form class="inline-form" method="post" action="/admin/api/<?= e($c['id']) ?>/revoke">
                    <?= csrf_field() ?>
                    <input class="input" type="password" name="password" placeholder="رمز عبور شما" aria-label="رمز عبور شما" autocomplete="current-password" required dir="ltr">
                    <button class="btn btn-danger btn-sm" type="submit">باطل شود</button>
                  </form>
                </details>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <form class="panel form" method="post" action="/admin/api" novalidate>
      <?= csrf_field() ?>
      <h2>کلید جدید</h2>
      <div class="row row-2">
        <div class="field<?= $cls('name') ?>"><label for="api-name">نام اتصال</label><input class="input" id="api-name" name="name" maxlength="100" value="<?= e($old['name'] ?? 'آراد کانتکت') ?>" required><?= $err('name') ?></div>
        <div class="field<?= $cls('ips') ?>"><label for="api-ips">IPهای مجاز <span class="muted">(اختیاری)</span></label><input class="input" id="api-ips" name="ips" dir="ltr" value="<?= e($old['ips'] ?? '') ?>" placeholder="203.0.113.10, 203.0.113.11"><div class="hint">اگر پر شود، درخواست فقط از همین نشانی‌ها پذیرفته می‌شود.</div><?= $err('ips') ?></div>
      </div>
      <fieldset class="ff-group<?= $cls('scopes') ?>">
        <legend>دسترسی‌ها</legend>
        <?php foreach ($scopes as $key => $label): ?>
          <label class="check"><input type="checkbox" name="scopes[]" value="<?= e($key) ?>"<?= in_array($key, $picked, true) ? ' checked' : '' ?>> <?= e($label) ?> <code dir="ltr"><?= e($key) ?></code></label>
        <?php endforeach; ?>
        <?= $err('scopes') ?>
      </fieldset>
      <div class="field<?= $cls('password') ?>"><label for="api-pw">رمز عبور شما</label><input class="input narrow" id="api-pw" name="password" type="password" autocomplete="current-password" dir="ltr" required><?= $err('password') ?></div>
      <div class="form-actions"><button class="btn" type="submit">ساخت کلید</button></div>
    </form>

    <section class="panel">
      <h2>آخرین درخواست‌ها</h2>
      <?php if ($recent === []): ?><p class="muted">هنوز درخواستی ثبت نشده است.</p><?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>زمان</th><th>اتصال</th><th>عملیات</th><th>سفارش</th><th>مشتری</th><th>Star</th><th>نتیجه</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $r): $ok = (int) $r['status'] === 200; ?>
                <tr>
                  <td><?= e(fa_date($r['created_at'])) ?></td>
                  <td><?= e($r['client']) ?></td>
                  <td><?= e($names[$r['endpoint']] ?? $r['endpoint']) ?></td>
                  <td dir="ltr"><?= e((string) $r['order_id']) ?></td>
                  <td><?= $r['first_name'] !== null ? e(trim($r['first_name'] . ' ' . $r['last_name'])) . ' <span class="muted" dir="ltr">+' . e($r['phone_cc'] . $r['phone']) . '</span>' : '—' ?></td>
                  <td><?= $r['stars'] !== null ? fa_int((int) $r['stars']) : '—' ?></td>
                  <td><span class="chip<?= $ok ? ' chip-ok' : ' chip-warn' ?>"><?= $ok ? 'موفق' : e((string) $r['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
