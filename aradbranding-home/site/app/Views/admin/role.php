<?php /** @var array $role @var array $permissions @var array $granted */
use App\Modules\Admin\RolesController;
$locked = $role['slug'] === 'super_admin'; ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'roles']) ?>
<form class="panel form" method="post" action="/admin/roles/<?= e($role['id']) ?>">
  <?= csrf_field() ?>
  <div class="field"><label for="r-name">نام نقش</label><input class="input" id="r-name" name="name" value="<?= e($role['name']) ?>"<?= $locked ? ' disabled' : '' ?>></div>
  <?php if ($locked): ?><div class="alert">مدیر کل همه دسترسی‌ها را با محدوده «همه» دارد و قابل تغییر نیست.</div><?php endif; ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>دسترسی</th><th>محدوده</th></tr></thead>
    <tbody><?php foreach ($permissions as $code): $g = $granted[$code] ?? ''; ?>
      <tr><td><?= e(RolesController::PERMISSION_LABELS[$code] ?? $code) ?> <span class="muted ltr"><?= e($code) ?></span></td>
        <td><select class="select" name="perm[<?= e($code) ?>]" aria-label="محدوده <?= e($code) ?>"<?= $locked ? ' disabled' : '' ?>>
          <option value="">— ندارد —</option>
          <?php foreach (RolesController::SCOPES as $k => $l): ?><option value="<?= e($k) ?>"<?= $g === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if (!$locked): ?><div class="form-actions sticky-actions"><button class="btn" type="submit">ذخیره دسترسی‌ها</button><a class="btn btn-quiet" href="/admin/roles">بازگشت</a></div><?php endif; ?>
</form>
<?= $this->partial('admin/_wrap_end') ?>
