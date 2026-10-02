<?php
/**
 * Admin dashboard — "trade management command center". Every number here is real (daily_metrics, cached totals,
 * proposal_feed, audit_logs); charts are server-rendered SVG (no chart library, CSP-safe).
 * @var array $totals @var array $today @var array $yesterday @var list<string> $days @var int $range
 * @var array $trend @var array $spark @var array $revenue @var array $activity @var array $feed @var array $countries @var int $securityIssues @var array $perms
 */
use App\Modules\Proposals\ProposalService;

$has = static fn (string ...$codes): bool => array_intersect($codes, array_keys($perms)) !== [];
$t = static fn (string $k): int => (int) ($today[$k] ?? 0);

/** Last 7 days vs the 7 before (null = nothing to compare with). */
$growth = static function (array $values, bool $level = false): ?int {
    $n = count($values);
    if ($level) {
        $cur = (int) ($values[$n - 1] ?? 0);
        $prev = (int) ($values[$n - 8] ?? 0);
    } else {
        $cur = array_sum(array_slice($values, -7));
        $prev = array_sum(array_slice($values, -14, 7));
    }
    if ($prev <= 0) {
        return null;
    }
    return (int) round(($cur - $prev) / $prev * 100);
};
$sparkSvg = static function (array $values, string $tone): string {
    $values = array_slice($values, -12);
    $max = max(1, ...$values);
    $out = '<svg class="ad-spark ad-' . $tone . '" viewBox="0 0 72 28" aria-hidden="true">';
    foreach ($values as $i => $v) {
        $h = max(2, (int) round($v / $max * 26));
        $out .= '<rect x="' . ($i * 6) . '" y="' . (28 - $h) . '" width="4" height="' . $h . '" rx="1.5" opacity="' . round(.35 + .65 * ($i + 1) / count($values), 2) . '"/>';
    }
    return $out . '</svg>';
};
$growthBadge = static function (?int $g): string {
    if ($g === null) {
        return '<span class="ad-growth is-flat">—</span>';
    }
    $cls = $g > 0 ? 'is-up' : ($g < 0 ? 'is-down' : 'is-flat');
    $arrow = $g > 0 ? '↑' : ($g < 0 ? '↓' : '·');
    return '<span class="ad-growth ' . $cls . '" title="۷ روز اخیر نسبت به ۷ روز قبل">' . $arrow . ' ' . fa_num(abs($g)) . '٪</span>';
};

$kpis = [
    ['label' => 'کاربران', 'value' => (int) $totals['users'], 'icon' => 'user', 'tone' => 'gold', 'spark' => $spark['new_users'], 'g' => $growth($spark['new_users']), 'href' => '/admin/users'],
    ['label' => 'صفحه منتشرشده', 'value' => (int) $totals['pages'], 'icon' => 'page', 'tone' => 'blue', 'spark' => $spark['new_pages'], 'g' => $growth($spark['new_pages']), 'href' => '/admin/content?tab=pages'],
    ['label' => 'فعال امروز', 'value' => $t('dau'), 'icon' => 'spark', 'tone' => 'gold', 'spark' => $spark['dau'], 'g' => $growth($spark['dau'], true), 'href' => '/admin/reports?metric=dau'],
    ['label' => 'فعال ۷ روز', 'value' => $t('wau'), 'icon' => 'route', 'tone' => 'blue', 'spark' => $spark['wau'], 'g' => $growth($spark['wau'], true), 'href' => '/admin/reports?metric=dau'],
    ['label' => 'فعال ۳۰ روز', 'value' => $t('mau'), 'icon' => 'eye', 'tone' => 'gold', 'spark' => $spark['mau'], 'g' => $growth($spark['mau'], true), 'href' => '/admin/reports?metric=dau'],
    ['label' => 'کشورها', 'value' => (int) $totals['countries'], 'icon' => 'compass', 'tone' => 'blue', 'spark' => $spark['new_users'], 'g' => null, 'href' => '/admin/reports'],
    ['label' => 'ارتباط تجاری', 'value' => (int) $totals['connections'], 'icon' => 'chat', 'tone' => 'gold', 'spark' => $spark['connections'], 'g' => $growth($spark['connections']), 'href' => '/admin/reports?metric=connections'],
    ['label' => 'پیشنهاد فعال', 'value' => (int) $totals['proposals'], 'icon' => 'letter', 'tone' => 'blue', 'spark' => $spark['new_proposals'], 'g' => $growth($spark['new_proposals']), 'href' => '/admin/content'],
];

// ---- Trend chart (server-rendered smooth SVG)
$slice = static fn (array $a): array => array_slice($a, -$range);
$sets = [
    'comms' => ['label' => 'ارتباطات', 'color' => 'blue', 'icon' => 'chat', 'data' => $slice($trend['comms'])],
    'pages' => ['label' => 'محتوا', 'color' => 'gold', 'icon' => 'page', 'data' => $slice($trend['pages'])],
    'users' => ['label' => 'کاربران جدید', 'color' => 'slate', 'icon' => 'user', 'data' => $slice($trend['users'])],
    'proposals' => ['label' => 'فرصت‌ها', 'color' => 'cyan', 'icon' => 'spark', 'data' => $slice($trend['proposals'])],
];
$chartDays = $slice($days);
$W = 640; $H = 230; $padL = 34; $padR = 12; $padT = 14; $padB = 30;
$maxV = 0;
foreach ($sets as $s) { $maxV = max($maxV, ...$s['data']); }
$step = max(1, (int) ceil($maxV / 4));
$mag = 10 ** max(0, strlen((string) $step) - 1);
$step = (int) (ceil($step / $mag) * $mag);
$top = $step * 4;
$n = count($chartDays);
$px = static fn (int $i): float => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * ($W - $padL - $padR);
$py = static fn (int $v): float => $H - $padB - ($top > 0 ? $v / $top : 0) * ($H - $padT - $padB);
$smooth = static function (array $data) use ($px, $py): string {
    $pts = [];
    foreach ($data as $i => $v) { $pts[] = [$px($i), $py((int) $v)]; }
    if (count($pts) < 2) { return ''; }
    $d = sprintf('M%.1f %.1f', $pts[0][0], $pts[0][1]);
    for ($i = 0; $i < count($pts) - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min(count($pts) - 1, $i + 2)];
        $c1 = [$p1[0] + ($p2[0] - $p0[0]) / 6, $p1[1] + ($p2[1] - $p0[1]) / 6];
        $c2 = [$p2[0] - ($p3[0] - $p1[0]) / 6, $p2[1] - ($p3[1] - $p1[1]) / 6];
        $d .= sprintf(' C%.1f %.1f %.1f %.1f %.1f %.1f', $c1[0], min($c1[1], $p1[1] > $p2[1] ? $p1[1] : $p2[1]), $c2[0], $c2[1], $p2[0], $p2[1]);
    }
    return $d;
};
$dayLabel = static function (string $day): string {
    if (class_exists(\IntlDateFormatter::class)) {
        $f = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'd MMMM');
        $o = $f->format(new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC')));
        if (is_string($o)) { return $o; }
    }
    return fa_num(substr($day, 5));
};
$labelEvery = max(1, (int) ceil($n / 7));

// ---- Today's checks (real counts; "done" when there is nothing to review)
$tasks = array_values(array_filter([
    ['ok' => $has('proposals.view', 'proposals.moderate'), 'icon' => 'letter', 'n' => $t('new_proposals'), 'title' => 'بررسی پیشنهادهای تازه امروز', 'href' => '/admin/content'],
    ['ok' => $has('pages.view', 'pages.approve'), 'icon' => 'page', 'n' => $t('new_pages'), 'title' => 'بررسی صفحه‌های تجاری تازه', 'href' => '/admin/content?tab=pages'],
    ['ok' => $has('users.view'), 'icon' => 'user', 'n' => $t('new_users'), 'title' => 'مرور اعضای جدید', 'href' => '/admin/users'],
    ['ok' => $has('payments.view', 'wallet.view'), 'icon' => 'star', 'n' => $t('payments'), 'title' => 'پیگیری پرداخت‌های امروز', 'href' => '/admin/finance'],
    ['ok' => $has('audit.view'), 'icon' => 'eye', 'n' => $securityIssues, 'title' => 'بررسی رویدادهای ناموفق امنیتی', 'href' => '/admin/audit'],
], static fn (array $x): bool => $x['ok']));
$openTasks = count(array_filter($tasks, static fn (array $x): bool => $x['n'] > 0));

// ---- Activity composition (last 30 days)
$sum30 = static fn (array $a): int => array_sum(array_slice($a, -30));
$mix = [
    ['label' => 'ارتباطات تجاری', 'v' => $sum30($trend['comms']), 'tone' => 'blue', 'icon' => 'chat'],
    ['label' => 'محتوای منتشرشده', 'v' => $sum30($trend['pages']), 'tone' => 'gold', 'icon' => 'page'],
    ['label' => 'کاربران جدید', 'v' => $sum30($trend['users']), 'tone' => 'navy', 'icon' => 'user'],
    ['label' => 'فرصت‌های تجاری', 'v' => $sum30($trend['proposals']), 'tone' => 'cyan', 'icon' => 'spark'],
];
$mixTotal = array_sum(array_column($mix, 'v'));
$circ = 2 * M_PI * 52;

// ---- Activity feed labels
$actLabel = static function (array $a): array {
    $who = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'سامانه';
    $map = [
        'user' => ['عضو جدید ثبت‌نام کرد', 'user', 'blue'],
        'page' => ['صفحه تجاری ساخت: ' . ($a['title'] ?? ''), 'page', 'gold'],
        'proposal' => ['پیشنهاد تجاری ثبت کرد: ' . ($a['title'] ?? ''), 'spark', 'gold'],
        'settings.update' => ['تنظیمات سامانه را تغییر داد', 'gear', 'slate'],
        'home.update' => ['صفحه اصلی سایت را ویرایش کرد', 'link', 'slate'],
        'home.reset' => ['صفحه اصلی را به پیش‌فرض برگرداند', 'link', 'slate'],
        'backup.create' => ['نسخه پشتیبان ساخت', 'archive', 'slate'],
        'backup.download' => ['نسخه پشتیبان دریافت کرد', 'archive', 'slate'],
        'backup.delete' => ['نسخه پشتیبان حذف کرد', 'archive', 'red'],
        'reports.export' => ['خروجی Excel گرفت', 'archive', 'blue'],
        'roles.create' => ['نقش جدید ساخت', 'lock', 'slate'], 'roles.update' => ['نقش‌ها را ویرایش کرد', 'lock', 'slate'],
        'pages.moderate' => ['یک صفحه تجاری را بررسی کرد', 'page', 'slate'], 'proposals.moderate' => ['یک پیشنهاد را بررسی کرد', 'letter', 'slate'],
        'official.announcement' => ['نامه رسمی منتشر کرد', 'letter', 'gold'], 'official.letters' => ['نامه رسمی ارسال کرد', 'letter', 'gold'],
        'system.update' => ['سامانه را بروزرسانی کرد', 'route', 'blue'], 'updates.note' => ['یادداشت تازه‌های سامانه ثبت کرد', 'spark', 'slate'],
        'users.status' => ['وضعیت یک کاربر را تغییر داد', 'user', 'slate'], 'users.verify' => ['یک کسب‌وکار را تأیید کرد', 'check', 'green'],
        'users.impersonate' => ['به‌جای یک کاربر وارد شد', 'user', 'slate'], 'users.impersonate_end' => ['از حالت جایگزین خارج شد', 'user', 'slate'],
    ];
    [$text, $icon, $tone] = $map[$a['kind']] ?? [(string) $a['kind'], 'eye', 'slate'];
    if (($a['result'] ?? null) !== null && $a['result'] !== 'success') {
        $tone = 'red';
        $text .= ' (ناموفق)';
    }
    return [$who, $text, $icon, $tone];
};
$maxViews = max(1, ...array_map(static fn (array $f): int => $f['views'], $feed ?: [['views' => 0]]));
$icon = static fn (string $id, string $cls = 'icon'): string => '<svg class="' . e($cls) . '"><use href="#i-' . e($id) . '"/></svg>';
?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'dashboard']) ?>
<div class="ad">

  <section class="ad-kpis" aria-label="شاخص‌های کلیدی">
    <?php foreach ($kpis as $k): ?>
    <a class="ad-kpi ad-tone-<?= e($k['tone']) ?>" href="<?= e($k['href']) ?>">
      <span class="ad-kpi-ic"><?= $icon($k['icon']) ?></span>
      <span class="ad-kpi-main"><b data-count="<?= (int) $k['value'] ?>"><?= fa_int($k['value']) ?></b><small><?= e($k['label']) ?></small></span>
      <span class="ad-kpi-foot"><?= $growthBadge($k['g']) ?><?= $sparkSvg($k['spark'], $k['tone']) ?></span>
    </a>
    <?php endforeach; ?>
  </section>

  <div class="ad-row ad-row-main">
    <section class="ad-card ad-tasks" aria-labelledby="ad-tasks-h">
      <header class="ad-card-h">
        <h2 id="ad-tasks-h"><span class="ad-count"><?= fa_num($openTasks) ?></span>کارهای امروز</h2>
        <a href="/admin/content">مشاهده همه<?= $icon('send', 'icon ad-chev') ?></a>
      </header>
      <?php if ($tasks === []): ?>
        <p class="ad-empty">کاری برای بررسی نیست.</p>
      <?php else: ?>
      <ul class="ad-task-list">
        <?php foreach ($tasks as $task): $done = $task['n'] === 0; ?>
        <li class="<?= $done ? 'is-done' : '' ?>">
          <a href="<?= e($task['href']) ?>">
            <span class="ad-task-ic"><?= $icon($task['icon']) ?></span>
            <span class="ad-task-t"><b><?= e($task['title']) ?></b><small><?= $done ? 'موردی برای بررسی نیست' : fa_num($task['n']) . ' مورد امروز' ?></small></span>
            <span class="ad-check" aria-label="<?= $done ? 'انجام‌شده' : 'در انتظار' ?>"><?= $done ? $icon('check') : '' ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <section class="ad-card ad-trend" aria-labelledby="ad-trend-h">
      <header class="ad-card-h">
        <h2 id="ad-trend-h"><span class="ad-h-ic"><?= $icon('spark') ?></span>روند فعالیت‌ها</h2>
        <ul class="ad-legend" aria-hidden="true"><?php foreach ($sets as $s): ?><li class="ad-c-<?= e($s['color']) ?>"><?= e($s['label']) ?></li><?php endforeach; ?></ul>
        <details class="ad-range">
          <summary><?= fa_num($range) ?> روز اخیر<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary>
          <div><?php foreach ([7, 30, 60] as $r): ?><a href="/admin?range=<?= $r ?>"<?= $r === $range ? ' aria-current="true"' : '' ?>><?= fa_num($r) ?> روز اخیر</a><?php endforeach; ?></div>
        </details>
      </header>
      <div class="ad-trend-body">
        <div class="ad-chart-wrap">
          <svg class="ad-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="روند فعالیت‌ها در <?= fa_num($range) ?> روز اخیر">
            <defs>
              <linearGradient id="ad-fill-blue" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#008CFF" stop-opacity=".22"/><stop offset="1" stop-color="#008CFF" stop-opacity="0"/></linearGradient>
              <linearGradient id="ad-fill-gold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F3C65B" stop-opacity=".26"/><stop offset="1" stop-color="#F3C65B" stop-opacity="0"/></linearGradient>
            </defs>
            <?php for ($g = 0; $g <= 4; $g++): $y = $py($step * $g); ?>
              <line class="ad-grid" x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= round($y, 1) ?>" y2="<?= round($y, 1) ?>"/>
              <text class="ad-axis" x="<?= $padL - 8 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= fa_num($step * $g) ?></text>
            <?php endfor; ?>
            <?php foreach ($chartDays as $i => $d): if ($i % $labelEvery !== 0 && $i !== $n - 1) { continue; } ?>
              <text class="ad-axis" x="<?= round($px($i), 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= e($dayLabel($d)) ?></text>
            <?php endforeach; ?>
            <?php foreach (['comms' => 'blue', 'pages' => 'gold'] as $key => $fill): $path = $smooth($sets[$key]['data']); if ($path === '') { continue; } ?>
              <path class="ad-area" d="<?= $path ?> L<?= round($px($n - 1), 1) ?> <?= $H - $padB ?> L<?= $padL ?> <?= $H - $padB ?> Z" fill="url(#ad-fill-<?= $fill ?>)"/>
            <?php endforeach; ?>
            <?php foreach ($sets as $key => $s): $path = $smooth($s['data']); if ($path === '') { continue; } ?>
              <path class="ad-line ad-s-<?= e($s['color']) ?>" d="<?= $path ?>" pathLength="1"/>
            <?php endforeach; ?>
            <?php foreach ($chartDays as $i => $d): ?>
              <g class="ad-hit"><rect x="<?= round($px($i) - ($W - $padL - $padR) / max(1, $n - 1) / 2, 1) ?>" y="<?= $padT ?>" width="<?= round(($W - $padL - $padR) / max(1, $n - 1), 1) ?>" height="<?= $H - $padT - $padB ?>"/>
                <line x1="<?= round($px($i), 1) ?>" x2="<?= round($px($i), 1) ?>" y1="<?= $padT ?>" y2="<?= $H - $padB ?>"/>
                <title><?= e($dayLabel($d)) ?> — <?= e(implode('، ', array_map(static fn ($s) => $s['label'] . ': ' . fa_num((int) $s['data'][$i]), $sets))) ?></title></g>
            <?php endforeach; ?>
          </svg>
        </div>
        <ul class="ad-trend-sum">
          <?php foreach ($sets as $s): ?>
          <li class="ad-c-<?= e($s['color']) ?>"><span class="ad-sum-ic"><?= $icon($s['icon']) ?></span><span><b><?= fa_int(array_sum($s['data'])) ?></b><small><?= e($s['label']) ?></small></span><?= $sparkSvg($s['data'], $s['color']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  </div>

  <section class="ad-today" aria-label="امروز در یک نگاه">
    <h2>امروز در یک نگاه</h2>
    <div class="ad-today-grid">
      <div><b><?= fa_int($t('new_users')) ?></b><span>عضو جدید <small>(دیروز <?= fa_int((int) ($yesterday['new_users'] ?? 0)) ?>)</small></span></div>
      <div><b><?= fa_int($t('new_proposals')) ?></b><span>پیشنهاد جدید</span></div>
      <div><b><?= fa_int($t('letters_private') + $t('letters_public')) ?></b><span>نامه</span></div>
      <div><b><?= fa_int($t('replies')) ?></b><span>پاسخ</span></div>
      <div><b><?= fa_int($t('connections')) ?></b><span>ارتباط جدید</span></div>
      <div><b><?= fa_int($t('page_views')) ?></b><span>مشاهده صفحه</span></div>
      <div><b><?= fa_int($t('stars_used')) ?></b><span>Stars مصرف‌شده</span></div>
      <div class="is-gold"><b><?= e(toman($t('revenue_rial'))) ?></b><span>درآمد امروز</span></div>
    </div>
  </section>

  <div class="ad-row ad-row-3">
    <section class="ad-card ad-mix" aria-labelledby="ad-mix-h">
      <header class="ad-card-h"><h2 id="ad-mix-h"><span class="ad-h-ic"><?= $icon('page') ?></span>ترکیب فعالیت‌ها</h2><a href="/admin/reports">جزئیات بیشتر</a></header>
      <div class="ad-mix-body">
        <div class="ad-donut">
          <svg viewBox="0 0 128 128" aria-hidden="true">
            <circle class="ad-donut-track" cx="64" cy="64" r="52"/>
            <?php $offset = 0.0; foreach ($mix as $m): if ($mixTotal === 0 || $m['v'] === 0) { continue; } $len = $m['v'] / $mixTotal * $circ; ?>
              <circle class="ad-donut-seg ad-d-<?= e($m['tone']) ?>" cx="64" cy="64" r="52" stroke-dasharray="<?= round(max(0, $len - 2), 2) ?> <?= round($circ, 2) ?>" stroke-dashoffset="<?= round(-$offset, 2) ?>"/>
            <?php $offset += $len; endforeach; ?>
          </svg>
          <span class="ad-donut-c"><b><?= fa_int($mixTotal) ?></b><small>فعالیت ۳۰ روز</small></span>
        </div>
        <ul class="ad-mix-list">
          <?php foreach ($mix as $m): ?>
          <li class="ad-c-<?= e($m['tone']) ?>"><span class="ad-sum-ic"><?= $icon($m['icon']) ?></span><span><?= e($m['label']) ?></span><b><?= fa_num($mixTotal > 0 ? (int) round($m['v'] / $mixTotal * 100) : 0) ?>٪</b></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section class="ad-card ad-activity" aria-labelledby="ad-act-h">
      <header class="ad-card-h"><h2 id="ad-act-h"><span class="ad-h-ic"><?= $icon('route') ?></span>آخرین فعالیت‌ها</h2><?php if ($has('audit.view')): ?><a href="/admin/audit">مشاهده همه</a><?php endif; ?></header>
      <?php if ($activity === []): ?><p class="ad-empty">هنوز فعالیتی ثبت نشده است.</p><?php else: ?>
      <ol class="ad-timeline">
        <?php foreach ($activity as $a): [$who, $text, $ic, $tone] = $actLabel($a); $av = media($a['avatar_path'] ?? null); ?>
        <li class="ad-t-<?= e($tone) ?>">
          <?php if ($av): ?><img class="ad-av" src="<?= e($av) ?>" alt=""><?php else: ?><span class="ad-av"><?= $icon($ic) ?></span><?php endif; ?>
          <span class="ad-tl-t"><b><?= e($who) ?></b><small><?= e(\App\Core\Support\Str::excerpt($text, 70)) ?></small></span>
          <time class="ad-tl-time" datetime="<?= e((string) $a['created_at']) ?>"><?= e(fa_date((string) $a['created_at'])) ?></time>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </section>

    <section class="ad-card ad-opps" aria-labelledby="ad-opps-h">
      <header class="ad-card-h"><h2 id="ad-opps-h"><span class="ad-h-ic"><?= $icon('spark') ?></span>فرصت‌های جدید تجاری</h2><a href="/admin/content">مشاهده همه</a></header>
      <?php if ($feed === []): ?><p class="ad-empty">هنوز پیشنهادی منتشر نشده است.</p><?php else: ?>
      <ul class="ad-opp-list">
        <?php foreach ($feed as $f): $c = $f['card']; $code = (string) ($c['country'] ?? ''); $pct = (int) round($f['views'] / $maxViews * 100); ?>
        <li>
          <a href="<?= !empty($c['uid']) ? '/proposals/' . e($c['uid']) : '/admin/content' ?>">
            <?php if (!empty($c['thumb'])): ?><img class="ad-opp-img" src="<?= e(media($c['thumb'])) ?>" alt="" loading="lazy"><?php else: ?><span class="ad-opp-img"><?= $icon('spark') ?></span><?php endif; ?>
            <span class="ad-opp-t">
              <b><?= e(\App\Core\Support\Str::excerpt((string) ($c['title'] ?? ''), 48)) ?></b>
              <small><?= $code !== '' ? flag($code) . ' ' . e($countries[$code] ?? $code) . ' · ' : '' ?><?= e(ProposalService::TYPES[$f['type']] ?? '') ?></small>
              <span class="ad-bar"><i data-w="<?= max(4, $pct) ?>"></i></span>
              <small class="ad-opp-views"><?= fa_int($f['views']) ?> بازدید در ۷ روز</small>
            </span>
            <span class="ad-go"><?= $icon('send', 'icon ad-chev') ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </div>

  <section class="ad-card ad-revenue"><?= $this->partial('admin/_chart', ['series' => array_map(static fn ($d, $v) => ['label' => $d, 'value' => $v], array_slice($days, -30), array_slice($revenue, -30)), 'title' => 'درآمد · ۳۰ روز', 'money' => true]) ?></section>

  <div class="ad-row ad-row-bottom">
    <nav class="ad-quick" aria-label="دسترسی سریع">
      <details class="ad-new">
        <summary><svg class="icon"><use href="#i-plus"/></svg>ایجاد جدید</summary>
        <div>
          <?php if ($has('letters.official')): ?><a href="/admin/letters"><?= $icon('letter') ?>نامه رسمی</a><?php endif; ?>
          <?php if ($has('reports.export')): ?><a href="/admin/exports"><?= $icon('archive') ?>خروجی Excel</a><?php endif; ?>
          <?php if ($has('backup.manage')): ?><a href="/admin/backups"><?= $icon('archive') ?>نسخه پشتیبان</a><?php endif; ?>
          <?php if ($has('roles.manage')): ?><a href="/admin/roles"><?= $icon('lock') ?>نقش جدید</a><?php endif; ?>
          <a href="/proposals/new"><?= $icon('spark') ?>پیشنهاد تجاری</a>
        </div>
      </details>
      <?php foreach ([
          ['/admin/users', 'user', 'کاربران', $has('users.view')], ['/admin/content', 'page', 'محتوا و بررسی', $has('pages.view', 'pages.approve', 'proposals.view', 'proposals.moderate')],
          ['/admin/reports', 'spark', 'گزارش‌ها', $has('reports.view')], ['/admin/finance', 'star', 'مالی', $has('payments.view', 'wallet.view')],
          ['/admin/home', 'link', 'صفحه اصلی سایت', $has('settings.manage')], ['/admin/settings', 'gear', 'تنظیمات', $has('settings.manage')],
      ] as [$href, $ic, $label, $ok]): if (!$ok) { continue; } ?>
        <a class="ad-q" href="<?= e($href) ?>"><?= $icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <a class="ad-promo" href="/" target="_blank" rel="noopener">
      <span class="ad-promo-ic"><?= $icon('mark') ?></span>
      <span class="ad-promo-t"><b>مسیر مطمئن تجارت بین‌المللی</b><small>مشاهده صفحه اصلی سایت</small></span>
      <span class="ad-promo-go"><svg class="icon"><use href="#i-send"/></svg></span>
    </a>
  </div>
</div>
<?= $this->partial('admin/_wrap_end') ?>
