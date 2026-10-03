<?php
/**
 * گزارش‌های تخلف — moderation queue.
 * @var array $rows @var ?int $next @var int $tab @var string $typeKey @var int $userFilter @var array $counts @var array $can @var array $errors @var array $perms
 */
use App\Modules\Admin\UserAdminController;
use App\Modules\Trust\TrustService;
$tName = static fn (array $r): string => ($r['t_company'] ?? '') !== '' && $r['t_company'] !== null ? $r['t_company'] : trim($r['t_first'] . ' ' . $r['t_last']);
$qs = static fn (array $over) => '/admin/trust?' . http_build_query(array_filter($over + ['status' => $tab, 'type' => $typeKey], static fn ($v) => $v !== '' && $v !== 0 && $v !== null));
$typeTabs = ['' => 'همه'] + array_combine(array_keys(TrustService::TARGET_KEYS), array_values(TrustService::TARGETS));
?>
<div class="mailbox">
  <?= $this->partial('admin/_nav', ['perms' => $perms, 'active' => 'trust']) ?>
  <div class="mail-content stack">
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>گزارش‌های تخلف</h2>
          <p class="muted">گزارش‌هایی که اعضا درباره صفحه‌ها، پیشنهادها، نامه‌ها و تاجران ثبت کرده‌اند. نتیجه هر بررسی برای گزارش‌دهنده اعلان می‌شود؛ اخطار و پنهان‌شدن محتوا هم با توضیح شما به کاربر گزارش‌شده خبر داده می‌شود. همه اقدام‌ها در «رویدادهای امنیتی» ثبت می‌شوند.</p>
        </div>
      </div>
      <?php if ($userFilter > 0): ?>
        <p class="trust-filter">فقط گزارش‌های مربوط به یک کاربر · <a href="/admin/users/<?= (int) $userFilter ?>">پرونده کاربر</a> · <a href="/admin/trust">نمایش همه</a></p>
      <?php else: ?>
        <nav class="seg-tabs" aria-label="وضعیت">
          <?php foreach (TrustService::STATUS as $s => $label): ?>
            <a href="<?= e($qs(['status' => $s])) ?>"<?= $tab === $s ? ' aria-current="page"' : '' ?>><?= e($label) ?> <b><?= fa_int($counts[$s] ?? 0) ?></b></a>
          <?php endforeach; ?>
        </nav>
        <nav class="chip-tabs" aria-label="نوع">
          <?php foreach ($typeTabs as $k => $label): ?><a class="chip<?= $typeKey === $k ? ' is-on' : '' ?>" href="<?= e($qs(['type' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
        </nav>
      <?php endif; ?>
    </section>

    <?php if ($rows === []): ?>
      <section class="panel"><p class="muted trust-empty"><?= $tab === TrustService::OPEN ? 'گزارش بررسی‌نشده‌ای نیست. 🎉' : 'موردی نیست.' ?></p></section>
    <?php endif; ?>

    <?php foreach ($rows as $r): $pv = $r['preview']; $type = (int) $r['target_type']; $open = (int) $r['status'] === TrustService::OPEN;
      $canHide = ($type === TrustService::T_MESSAGE && $can['letters']) || ($type === TrustService::T_PAGE && $can['pages']) || ($type === TrustService::T_PROPOSAL && $can['proposals']); ?>
      <article class="panel trust-card" id="r<?= (int) $r['id'] ?>">
        <header class="trust-card-head">
          <span class="chip"><?= e(TrustService::TARGETS[$type] ?? '') ?></span>
          <span class="chip chip-warn"><?= e(TrustService::REASONS[$r['reason']] ?? $r['reason']) ?></span>
          <?php if ((int) $r['same_target'] > 1): ?><span class="chip chip-danger"><?= fa_int((int) $r['same_target']) ?> گزارش برای همین مورد</span><?php endif; ?>
          <span class="grow"></span>
          <small class="muted"><?= e(fa_date($r['created_at'])) ?></small>
        </header>

        <div class="trust-who">
          <div><small>گزارش‌شده</small>
            <b><a href="/admin/users/<?= (int) $r['target_user_id'] ?>"><?= e($tName($r)) ?></a></b> <?= flag($r['t_cc']) ?>
            <?php if ((int) $r['t_status'] !== 1): ?><span class="chip"><?= e(UserAdminController::STATUS[(int) $r['t_status']] ?? '') ?></span><?php endif; ?>
            <a class="muted" href="/admin/trust?user=<?= (int) $r['target_user_id'] ?>">(<?= fa_int((int) $r['t_reports']) ?> گزارش در کل)</a>
          </div>
          <div><small>گزارش‌دهنده</small><b><?= e(trim($r['rp_first'] . ' ' . $r['rp_last'])) ?></b><?php if ($r['rp_handle']): ?> <bdi class="muted">@<?= e($r['rp_handle']) ?></bdi><?php endif; ?></div>
        </div>

        <?php if (!empty($pv['gone'])): ?>
          <p class="muted">این مورد دیگر وجود ندارد (حذف شده است).</p>
        <?php elseif ($type !== TrustService::T_USER): ?>
          <blockquote class="trust-content<?= !empty($pv['hidden']) ? ' is-hidden' : '' ?>">
            <?php if ($pv['title']): ?><b><?= e($pv['title']) ?></b><?php endif; ?>
            <?php if ($pv['text'] !== null): ?><div><?= nl2br(e(mb_strimwidth((string) $pv['text'], 0, 1200, '…')), false) ?></div>
            <?php else: ?><div class="muted">متن نامه فقط برای دارندگان دسترسی «بررسی نامه‌ها» نمایش داده می‌شود.</div><?php endif; ?>
            <?php if (!empty($pv['hidden'])): ?><span class="chip">پنهان شده</span><?php endif; ?>
            <?php if (!empty($pv['link'])): ?><a class="trust-open" href="<?= e($pv['link']) ?>" target="_blank" rel="noopener">مشاهده ↗</a><?php endif; ?>
          </blockquote>
        <?php endif; ?>
        <?php if (($r['details'] ?? '') !== ''): ?><p class="trust-details"><small>توضیح گزارش‌دهنده</small><?= e((string) $r['details']) ?></p><?php endif; ?>

        <?php if (!$open): ?>
          <p class="trust-done"><b><?= e(TrustService::ACTIONS[$r['action']] ?? '') ?></b><?php if ($r['resolution']): ?> · <?= e((string) $r['resolution']) ?><?php endif; ?>
            <small class="muted"> — <?= e(trim(($r['h_first'] ?? '') . ' ' . ($r['h_last'] ?? ''))) ?>، <?= e(fa_date($r['handled_at'])) ?></small></p>
        <?php endif; ?>

        <?php if (isset($errors['r' . $r['id']])): ?><div class="alert alert-error" role="alert"><?= e($errors['r' . $r['id']]) ?></div><?php endif; ?>
        <details class="trust-decide"<?= isset($errors['r' . $r['id']]) ? ' open' : '' ?>>
          <summary class="btn btn-sm<?= $open ? '' : ' btn-ghost' ?>"><?= $open ? 'بررسی و تصمیم' : 'تغییر تصمیم' ?></summary>
          <form class="form trust-form" method="post" action="/admin/trust/<?= (int) $r['id'] ?>">
            <?= csrf_field() ?>
            <fieldset class="trust-actions">
              <legend>نتیجه</legend>
              <label><input type="radio" name="action" value="dismiss" required> <span>بدون تخلف</span></label>
              <label><input type="radio" name="action" value="warn"> <span>اخطار</span></label>
              <?php if ($canHide && empty($pv['gone']) && empty($pv['hidden'])): ?><label><input type="radio" name="action" value="hide"> <span>پنهان‌کردن <?= e(TrustService::TARGETS[$type]) ?></span></label><?php endif; ?>
              <?php if ($can['users']): ?>
                <label><input type="radio" name="action" value="restrict"> <span>محدودکردن حساب</span></label>
                <label><input type="radio" name="action" value="suspend"> <span>تعلیق موقت</span></label>
                <label><input type="radio" name="action" value="ban"> <span>مسدودکردن</span></label>
              <?php endif; ?>
            </fieldset>
            <div class="row row-2">
              <div class="field"><label for="tn-<?= (int) $r['id'] ?>">توضیح</label><input class="input" id="tn-<?= (int) $r['id'] ?>" name="note" maxlength="500" placeholder="برای اخطار، پنهان‌کردن و محدودیت، کاربر این را می‌بیند"></div>
              <?php if ($can['users']): ?>
              <div class="field"><label for="td-<?= (int) $r['id'] ?>">مدت تعلیق</label>
                <select class="select" id="td-<?= (int) $r['id'] ?>" name="days"><?php foreach (UserAdminController::SUSPEND_DAYS as $d): ?><option value="<?= $d ?>"<?= $d === 7 ? ' selected' : '' ?>><?= fa_int($d) ?> روز</option><?php endforeach; ?><option value="0">تا برداشتن دستی</option></select>
              </div>
              <?php endif; ?>
            </div>
            <?php if ($can['users']): ?><div class="field"><label for="tp-<?= (int) $r['id'] ?>">رمز عبور شما <span class="muted">(فقط برای تعلیق و مسدودکردن)</span></label><input class="input narrow" id="tp-<?= (int) $r['id'] ?>" name="password" type="password" autocomplete="current-password" dir="ltr"></div><?php endif; ?>
            <label class="check"><input type="checkbox" name="all" value="1" checked> بستن همه گزارش‌های باز همین مورد</label>
            <div class="form-actions"><button class="btn" type="submit">ثبت تصمیم</button></div>
          </form>
        </details>
      </article>
    <?php endforeach; ?>

    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-quiet btn-sm" href="<?= e($qs(['before' => $next])) ?>">موارد قدیمی‌تر</a></div><?php endif; ?>
  </div>
</div>
