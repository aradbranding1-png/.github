<?php /** @var array $roles */ ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'roles']) ?>
<section class="panel">
  <h2>نقش‌ها</h2>
  <ul class="list">
    <?php foreach ($roles as $r): ?>
      <li class="list-row"><div class="grow"><a class="title" href="/admin/roles/<?= e($r['id']) ?>"><?= e($r['name']) ?></a><div class="meta"><span class="ltr"><?= e($r['slug']) ?></span><span><?= fa_int((int) $r['users']) ?> کاربر</span></div></div>
        <?php if ($r['slug'] === 'super_admin'): ?><span class="chip chip-gold">کامل و قفل</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<form class="panel form" method="post" action="/admin/roles">
  <?= csrf_field() ?>
  <h2>نقش جدید</h2>
  <div class="row row-2">
    <div class="field"><label for="r-name">نام</label><input class="input" id="r-name" name="name" required maxlength="80"></div>
    <div class="field"><label for="r-slug">شناسه انگلیسی</label><input class="input" id="r-slug" name="slug" required dir="ltr" placeholder="sales_team" pattern="[a-z][a-z0-9_]{2,39}"></div>
  </div>
  <div class="form-actions"><button class="btn btn-sm" type="submit">ساخت نقش</button></div>
</form>
<?= $this->partial('admin/_wrap_end') ?>
