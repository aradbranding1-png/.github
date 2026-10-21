<?php /** @var array $rows @var ?int $next @var string $q @var int $status @var int $country @var array $countries */
use App\Modules\Admin\UserAdminController; ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'users']) ?>
<form class="panel filters-form admin-search" method="get" action="/admin/users">
  <input class="input" name="q" value="<?= e($q) ?>" placeholder="نام، ایمیل، تلفن یا نشانی صفحه" aria-label="جستجو">
  <select class="select" name="status" aria-label="وضعیت"><option value="">همه وضعیت‌ها</option>
    <?php foreach (UserAdminController::STATUS as $k => $label): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <select class="select" name="country" aria-label="کشور"><option value="">همه کشورها</option>
    <?php foreach ($countries as $c): ?><option value="<?= e($c['id']) ?>" data-search="<?= e($c['name_en']) ?>"<?= $country === $c['id'] ? ' selected' : '' ?>><?= e(flag($c['code']) . ' ' . $c['name_fa']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm" type="submit">جستجو</button>
</form>
<section class="panel">
  <?php if ($rows === []): ?><div class="empty"><p>کاربری پیدا نشد.</p></div><?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $r): $av = media($r['avatar_path']); ?>
        <li class="list-row">
          <?php if ($av): ?><img class="avatar" src="<?= e($av) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= e(initials($r['first_name'], $r['last_name'])) ?></span><?php endif; ?>
          <div class="grow">
            <a class="title" href="/admin/users/<?= e($r['id']) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?> <?= flag($r['country_code']) ?><?= $r['business_verified_at'] ? ' ✓' : '' ?></a>
            <div class="meta"><span class="ltr"><?= e($r['email']) ?></span><?php if ($r['handle']): ?><span class="ltr">/p/<?= e($r['handle']) ?></span><?php endif; ?><span>⭐ <?= fa_int((int) $r['balance']) ?></span><span>عضویت <?= e(fa_date($r['created_at'], false)) ?></span></div>
          </div>
          <span class="chip<?= (int) $r['status'] === 1 ? ' chip-ok' : '' ?>"><?= e(UserAdminController::STATUS[(int) $r['status']] ?? '') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/admin/users?<?= e(http_build_query(['q' => $q, 'status' => $status ?: null, 'country' => $country ?: null, 'before' => $next])) ?>">بیشتر</a></div><?php endif; ?>
  <?php endif; ?>
</section>
<?= $this->partial('admin/_wrap_end') ?>
