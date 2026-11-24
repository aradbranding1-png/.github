<?php
/**
 * کاربران فعال — who was active on a day / in a range, and what each member did.
 * @var string $from @var string $to @var bool $single @var int $range @var string $today @var string $show @var array $rows
 * @var array $timeline @var array $summary @var int $filtered @var int $page @var int $pages @var array $dayOptions
 * @var bool $canOpen @var int $liveDau @var array $perms
 */
use App\Core\Support\Jalali;
use App\Modules\Admin\ActiveUsersController;
use App\Modules\Admin\MetricsService;
use App\Modules\Admin\UserAdminController;
use App\Modules\Analytics\ActiveDays;
use App\Modules\Wallet\WalletService;

$tz = MetricsService::timezone();
$local = static fn (string $utc, string $fmt): string => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format($fmt);
$clock = static fn (string $utc): string => fa_num($local($utc, 'H:i'));
$period = $range > 0 ? ['range' => $range] : ($single ? ['day' => $from] : ['from' => $from, 'to' => $to]);
$url = static fn (array $over = []): string => '/admin/reports/active?' . http_build_query(array_filter(
    $over + $period + ['show' => $show],
    static fn ($v, $k): bool => !($v === null || $v === '' || ($k === 'show' && $v === 'all') || ($k === 'page' && (int) $v === 1)),
    ARRAY_FILTER_USE_BOTH
));
$periodUrl = static fn (array $p): string => '/admin/reports/active?' . http_build_query($p + ($show !== 'all' ? ['show' => $show] : []));
$yesterday = MetricsService::today(-1);
$title = $single ? Jalali::full($from) : 'از ' . Jalali::full($from) . ' تا ' . Jalali::full($to);
$shift = static fn (int $d): string => date('Y-m-d', strtotime($from . ' 12:00:00 UTC ' . sprintf('%+d', $d) . ' day'));
$statusClass = [1 => 'chip-ok', 2 => 'chip-gold'];
?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'reports']) ?>
<link rel="stylesheet" href="<?= e(asset('admin-active.css')) ?>">

<section class="panel aa-period">
  <div class="panel-head">
    <div>
      <h2>کاربران فعال · <?= e($title) ?></h2>
      <p class="muted">هر عضوی که در این <?= $single ? 'روز' : 'بازه' ?> وارد سامانه شده یا صفحه‌ای را باز کرده، با کارهایی که انجام داده. روزها به وقت تهران‌اند.</p>
    </div>
    <a class="btn btn-quiet btn-sm" href="/admin/reports?metric=dau">نمودار کاربران فعال</a>
  </div>
  <div class="aa-period-bar">
    <nav class="seg-tabs" aria-label="بازه">
      <a href="<?= e($periodUrl(['day' => $today])) ?>"<?= $single && $from === $today && $range === 0 ? ' aria-current="page"' : '' ?>>امروز</a>
      <a href="<?= e($periodUrl(['day' => $yesterday])) ?>"<?= $single && $from === $yesterday ? ' aria-current="page"' : '' ?>>دیروز</a>
      <a href="<?= e($periodUrl(['range' => 7])) ?>"<?= $range === 7 ? ' aria-current="page"' : '' ?>>۷ روز اخیر</a>
      <a href="<?= e($periodUrl(['range' => 30])) ?>"<?= $range === 30 ? ' aria-current="page"' : '' ?>>۳۰ روز اخیر</a>
    </nav>
    <form class="aa-day-form" method="get" action="/admin/reports/active">
      <?php if ($single): ?><a class="btn btn-ghost btn-sm" href="<?= e($periodUrl(['day' => $shift(-1)])) ?>" title="روز قبل">→</a><?php endif; ?>
      <select class="select" name="day" aria-label="انتخاب روز">
        <?php if (!$single): ?><option value="">انتخاب یک روز…</option><?php endif; ?>
        <?php foreach ($dayOptions as $d => $label): ?><option value="<?= e($d) ?>"<?= $single && $from === $d ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <?php if ($show !== 'all'): ?><input type="hidden" name="show" value="<?= e($show) ?>"><?php endif; ?>
      <button class="btn btn-sm" type="submit">نمایش</button>
      <?php if ($single && $from < $today): ?><a class="btn btn-ghost btn-sm" href="<?= e($periodUrl(['day' => $shift(1)])) ?>" title="روز بعد">←</a><?php endif; ?>
    </form>
  </div>
</section>

<section class="stats stats-admin aa-kpis">
  <?php foreach ([
      'all' => [$summary['active'], 'کاربر فعال'],
      'doers' => [$summary['doers'], 'کاری انجام دادند'],
      'visitors' => [$summary['visitors'], 'فقط سر زدند (بدون اقدام)'],
      'new' => [$summary['new'], 'عضو جدید در همین ' . ($single ? 'روز' : 'بازه')],
  ] as $key => [$n, $label]): ?>
    <a class="stat<?= $show === $key ? ' is-on' : '' ?>" href="<?= e($url(['show' => $key, 'page' => null])) ?>"><b><?= fa_int($n) ?></b><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</section>

<?php if ($single && $liveDau > $summary['active']): ?>
  <p class="alert aa-note">عدد ثبت‌شده در نمودار برای این روز <b><?= fa_int($liveDau) ?></b> نفر است ولی <?= fa_int($summary['active']) ?> نفر شناسایی شد: پیش از نسخه ۱.۱۹.۲۱ فقط ورودها و کارها (نامه، پیشنهاد، دیدن صفحه…) به تفکیک روز ثبت می‌شد و بازدید ساده نه. از این نسخه به بعد هر حضور ثبت می‌شود و این دو عدد یکی می‌شوند.</p>
<?php endif; ?>

<?php $totals = array_filter($summary['totals']); if ($totals !== []): ?>
<section class="panel">
  <h2>کارهای انجام‌شده در این <?= $single ? 'روز' : 'بازه' ?></h2>
  <div class="aa-totals">
    <?php foreach ($totals as $k => $n): ?><span class="aa-total"><b><?= fa_int($n) ?></b><?= e(ActiveUsersController::ACTIONS[$k][0]) ?></span><?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <?php if ($rows === []): ?>
    <div class="empty"><p><?= $summary['active'] === 0 ? 'در این ' . ($single ? 'روز' : 'بازه') . ' فعالیتی ثبت نشده است.' : 'در این دسته کسی نیست.' ?></p></div>
  <?php else: ?>
    <p class="muted aa-count"><?= fa_int($filtered) ?> نفر<?= $pages > 1 ? ' · صفحه ' . fa_int($page) . ' از ' . fa_int($pages) : '' ?> · مرتب‌شده بر اساس بیشترین کار</p>
    <ul class="list aa-list-active">
      <?php foreach ($rows as $r): $uid = (int) $r['user_id']; $av = media($r['avatar_path']); $name = trim($r['first_name'] . ' ' . $r['last_name']); $acts = array_filter($r['actions']); ?>
        <li class="aa-row">
          <div class="list-row">
            <?php if ($av): ?><img class="avatar" src="<?= e($av) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= e(initials($r['first_name'], $r['last_name'])) ?></span><?php endif; ?>
            <div class="grow">
              <?php if ($canOpen): ?><a class="title" href="/admin/users/<?= $uid ?>"><?= e($name) ?></a><?php else: ?><b class="title"><?= e($name) ?></b><?php endif; ?>
              <?= flag($r['country_code']) ?>
              <?php if ($r['is_new']): ?><span class="chip chip-gold">عضو جدید</span><?php endif; ?>
              <?php if ((int) $r['status'] !== 1): ?><span class="chip"><?= e(UserAdminController::STATUS[(int) $r['status']] ?? '') ?></span><?php endif; ?>
              <div class="meta">
                <?php if ($canOpen): ?><span class="ltr"><?= e($r['email']) ?></span><?php endif; ?>
                <?php if ($single): ?>
                  <span>اولین حضور <?= e($clock($r['first_seen'])) ?></span><span>آخرین حضور <?= e($clock($r['last_seen'])) ?></span>
                <?php else: ?>
                  <span><?= fa_int((int) $r['active_days']) ?> روز فعال</span><span>آخرین حضور <?= e(fa_date($r['last_seen'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="aa-acts">
              <?php if ($acts === []): ?><span class="chip">فقط بازدید</span><?php else: foreach ($acts as $k => $n): ?>
                <span class="chip chip-ok"><?= fa_int($n) ?> <?= e(ActiveUsersController::ACTIONS[$k][1]) ?></span>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php if (!empty($timeline[$uid])): ?>
            <details class="aa-tl">
              <summary>جزئیات کارها</summary>
              <ol>
                <?php foreach ($timeline[$uid] as $ev):
                    $label = ActiveDays::EVENTS[$ev['event']] ?? $ev['event'];
                    $data = json_decode((string) ($ev['data'] ?? ''), true) ?: [];
                    if ($ev['event'] === 'stars_spent' && isset($data['stars'])) {
                        $label .= ' (' . fa_int((int) $data['stars']) . ' · ' . (WalletService::REASON_LABELS[$data['reason'] ?? ''] ?? ($data['reason'] ?? '')) . ')';
                    }
                    $peer = trim((string) ($ev['first_name'] ?? '') . ' ' . (string) ($ev['last_name'] ?? '')); ?>
                  <li><time><?= e($single ? $clock($ev['created_at']) : fa_date($ev['created_at'])) ?></time> <?= e($label) ?><?= $peer !== '' && $ev['event'] !== 'stars_spent' ? ' · ' . e($peer) : '' ?></li>
                <?php endforeach; ?>
              </ol>
            </details>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($pages > 1): ?>
      <div class="form-actions form-actions-center">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($url(['page' => $page - 1])) ?>">صفحه قبل</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($url(['page' => $page + 1])) ?>">صفحه بعد</a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?= $this->partial('admin/_wrap_end') ?>
