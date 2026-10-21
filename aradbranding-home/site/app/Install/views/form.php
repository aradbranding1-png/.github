<?php /** @var array $checks @var array $old @var array $errors @var callable $e */ ?>
<div class="card">
  <h2>۱. پیش‌نیازها</h2>
  <table>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td><?= $e($c['name']) ?></td>
        <td class="<?= $e($c['status']) ?>"><?= $e(strtoupper($c['status'])) ?></td>
        <td><?= $e($c['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<form class="card" method="post" action="/install" autocomplete="off">
  <h2>۲. تأیید مالکیت سرور</h2>
  <p class="hint">فایل <code>storage/install.key</code> را در File Manager دایرکت‌ادمین باز کنید و محتوای آن را اینجا وارد کنید. این فایل خارج از public_html است و فقط صاحب سرور به آن دسترسی دارد.</p>
  <label for="install_key">کلید نصب</label>
  <input id="install_key" name="install_key" required>
  <?php if (isset($errors['install_key'])): ?><div class="err"><?= $e($errors['install_key']) ?></div><?php endif; ?>

  <h2>۳. دیتابیس</h2>
  <div class="row">
    <div>
      <label for="db_host">Host</label>
      <input id="db_host" name="db_host" value="<?= $e($old['db_host'] ?? '') ?>" required>
    </div>
    <div>
      <label for="db_port">Port</label>
      <input id="db_port" name="db_port" inputmode="numeric" value="<?= $e($old['db_port'] ?? '3306') ?>">
    </div>
  </div>
  <?php if (isset($errors['db_host'])): ?><div class="err"><?= $e($errors['db_host']) ?></div><?php endif; ?>
  <label for="db_name">نام دیتابیس</label>
  <input id="db_name" name="db_name" value="<?= $e($old['db_name'] ?? '') ?>" required>
  <label for="db_user">نام کاربری دیتابیس</label>
  <input id="db_user" name="db_user" value="<?= $e($old['db_user'] ?? '') ?>" required>
  <label for="db_pass">رمز دیتابیس</label>
  <input id="db_pass" name="db_pass" type="password">

  <h2>۴. آدرس سامانه</h2>
  <label for="app_url">APP_URL</label>
  <input id="app_url" name="app_url" value="<?= $e($old['app_url'] ?? '') ?>" required>
  <?php if (isset($errors['app_url'])): ?><div class="err"><?= $e($errors['app_url']) ?></div><?php endif; ?>

  <?php if (isset($errors['general'])): ?><div class="alert"><?= $e($errors['general']) ?></div><?php endif; ?>
  <button type="submit">نصب سامانه</button>
</form>
