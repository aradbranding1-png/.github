<div class="page-head"><div><h1>پروفایل من</h1><div class="sub">اطلاعات حساب، تصویر پروفایل، امنیت و اتصال به my.aradbranding.me</div></div></div>
<div class="grid g-main">
    <div class="stack">
        <form class="card" method="post" action="<?= url('/profile') ?>">
            <?= csrf_field() ?>
            <h3><?= icon('user') ?> اطلاعات شخصی</h3>
            <div class="form-grid">
                <div class="field"><label>نام</label><input type="text" name="first_name" value="<?= e(old('first_name', $me['first_name'])) ?>" required></div>
                <div class="field"><label>نام خانوادگی</label><input type="text" name="last_name" value="<?= e(old('last_name', $me['last_name'])) ?>" required></div>
                <div class="field"><label>موبایل</label><input class="ltr" type="text" value="<?= e($me['mobile']) ?>" disabled><div class="hint">برای تغییر موبایل با پشتیبانی تماس بگیرید.</div></div>
                <div class="field"><label>ایمیل</label><input class="ltr" type="email" name="email" value="<?= e(old('email', $me['email'])) ?>"></div>
                <div class="field full"><label>عنوان شغلی</label><input type="text" name="job_title" value="<?= e(old('job_title', $me['job_title'])) ?>"></div>
                <div class="field full"><label>درباره من</label><textarea name="bio" rows="3"><?= e(old('bio', $me['bio'])) ?></textarea></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?> ذخیره</button></div>
        </form>
        <form class="card" method="post" action="<?= url('/profile/password') ?>">
            <?= csrf_field() ?>
            <h3><?= icon('lock') ?> تغییر رمز عبور</h3>
            <div class="form-grid">
                <?php if ($me['password_hash']): ?><div class="field full"><label>رمز فعلی</label><input class="ltr" type="password" name="current_password" autocomplete="current-password" required></div><?php else: ?><div class="alert alert-info full"><?= icon('info') ?> حساب شما از طریق my ایجاد شده است؛ می‌توانید یک رمز برای ورود مستقیم تعیین کنید.</div><?php endif; ?>
                <div class="field"><label>رمز جدید</label><input class="ltr" type="password" name="password" autocomplete="new-password" required></div>
                <div class="field"><label>تکرار رمز جدید</label><input class="ltr" type="password" name="password_confirmation" autocomplete="new-password" required></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary"><?= icon('key-round') ?> تغییر رمز</button></div>
        </form>
    </div>
    <div class="stack">
        <div class="card text-center">
            <?= avatar_html($me, 'xl') ?>
            <h3 class="mt-1 mb-0"><?= user_name_html($me) ?></h3>
            <div class="small faint">منبع تصویر: <?= e(label('avatar_source', $me['avatar_source'])) ?></div>
            <form method="post" enctype="multipart/form-data" action="<?= url('/profile/avatar') ?>" class="mt-2">
                <?= csrf_field() ?>
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn btn-primary btn-sm mt-1 w-100"><?= icon('camera') ?> آپلود تصویر جدید</button>
            </form>
            <div class="flex mt-1" style="justify-content:center">
                <?php if ($me['my_avatar_url']): ?><form method="post" action="<?= url('/profile/avatar/source') ?>"><?= csrf_field() ?><input type="hidden" name="source" value="my"><button class="btn btn-outline btn-sm"><?= icon('refresh-cw') ?> استفاده از تصویر my</button></form><?php endif; ?>
                <?php if ($me['avatar_path']): ?><form method="post" action="<?= url('/profile/avatar/source') ?>" data-confirm="تصویر حذف شود؟"><?= csrf_field() ?><input type="hidden" name="source" value="none"><button class="btn btn-ghost btn-sm"><?= icon('trash-2') ?> حذف</button></form><?php endif; ?>
            </div>
            <div class="small faint mt-1">تغییر تصویر در سامانه آموزش، تصویر اصلی شما در my را تغییر نمی‌دهد.</div>
        </div>
        <div class="card">
            <h3><?= icon('fingerprint') ?> اتصال به my.aradbranding.me</h3>
            <?php if ($me['my_user_id']): ?>
                <div class="alert alert-success"><?= icon('circle-check') ?><div>حساب شما متصل است.<div class="small">تاریخ اتصال: <?= jdatetime($me['my_linked_at']) ?></div></div></div>
            <?php elseif ($sso): ?>
                <p class="small muted">با اتصال حساب، از این پس با حساب my وارد شوید. سوابق آموزشی شما حفظ می‌شود.</p>
                <a class="btn btn-outline w-100" href="<?= url('/profile/link-my') ?>"><?= icon('link') ?> اتصال حساب</a>
            <?php else: ?>
                <div class="small faint">اتصال SSO هنوز توسط مدیر سامانه فعال نشده است.</div>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3><?= icon('shield-check') ?> نقش‌ها و عضویت‌ها</h3>
            <div class="flex flex-wrap mb-1"><?php foreach ($roles as $r): ?><span class="badge <?= $r['is_root'] ? 'badge-root' : 'badge-' . e($r['color']) ?>"><?= e($r['name']) ?></span><?php endforeach; ?></div>
            <div class="flex flex-wrap mb-1"><?php foreach ($groups as $g): ?><span class="badge badge-gray"><?= icon($g['icon']) ?> <?= e($g['name']) ?></span><?php endforeach; ?></div>
            <div class="flex flex-wrap"><?php foreach ($org as $o): ?><span class="badge badge-info"><?= e($o['type_name']) ?>: <?= e($o['name']) ?></span><?php endforeach; ?></div>
        </div>
    </div>
</div>
