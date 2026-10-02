<?php
/**
 * گزارش‌های من — the signed-in trader's own activity. Charts are inline SVG drawn here (no chart library, CSP-safe);
 * each mark carries a <title> for the hover tooltip, and every chart has a legend plus visible totals.
 * @var int $days @var int $step @var array $buckets @var array $series @var array $totals @var array $counters
 * @var array $wallet @var array $content @var array $recent @var array $names
 */
use App\Modules\Dashboard\ReportsController;

$fmtDay = static function (string $ymd, bool $long = false): string {
    if (class_exists(\IntlDateFormatter::class)) {
        $f = new \IntlDateFormatter('fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::TRADITIONAL, $long ? 'd MMMM' : 'd MMM');
        return (string) $f->format(new \DateTimeImmutable($ymd, new \DateTimeZone('UTC')));
    }
    return fa_num(substr($ymd, 5));
};
$t = static fn (string $k): int => (int) ($totals[$k] ?? 0);
$sum = static fn (array $v): int => (int) array_sum($v);
$nice = static function (int $max): int {
    if ($max <= 4) {
        return 4;
    }
    $p = 10 ** (int) floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($max <= $m * $p) {
            return (int) ceil($m * $p);
        }
    }
    return $max;
};
$period = $step > 1 ? 'هفته منتهی به ' : '';

/** Grouped vertical bars. $set = list of [label, class, values]. */
$bars = function (array $set, string $unit) use ($buckets, $fmtDay, $nice, $period): string {
    $W = 640; $H = 220; $L = 34; $R = 8; $T = 12; $B = 26;
    $n = count($buckets);
    $max = $nice(max(1, ...array_merge(...array_map(static fn (array $s): array => $s[2], $set))));
    $slot = ($W - $L - $R) / max(1, $n);
    $gap = 2;
    $bw = max(2.0, min(18.0, ($slot - 6) / count($set) - $gap));
    $y = static fn (float $v): float => $T + ($H - $T - $B) * (1 - $v / $max);
    $out = '<svg class="rp-svg" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-hidden="true">';
    for ($g = 0; $g <= 4; $g++) {
        $v = $max * $g / 4; $yy = $y($v);
        $out .= '<line class="rp-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($yy, 1) . '" y2="' . round($yy, 1) . '"/>'
            . '<text class="rp-ytick" x="' . ($L - 6) . '" y="' . round($yy + 3.5, 1) . '">' . e(fa_num((int) round($v))) . '</text>';
    }
    $every = max(1, (int) ceil($n / 7));
    foreach ($buckets as $i => $day) {
        $x0 = $L + $slot * $i + ($slot - (count($set) * ($bw + $gap) - $gap)) / 2;
        $tip = $period . $fmtDay($day, true);
        foreach ($set as $j => [$label, $cls, $vals]) {
            $v = (int) $vals[$i];
            $tip .= ' · ' . $label . ': ' . fa_num($v);
        }
        $out .= '<g class="rp-col"><title>' . e($tip . ' ' . $unit) . '</title><rect class="rp-hit" x="' . round($L + $slot * $i, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '"/>';
        foreach ($set as $j => [$label, $cls, $vals]) {
            $v = (int) $vals[$i];
            if ($v <= 0) {
                continue;
            }
            $x = $x0 + $j * ($bw + $gap); $top = $y($v); $base = $y(0); $r = min(4, $bw / 2, $base - $top);
            $out .= '<path class="rp-bar ' . $cls . '" d="M' . round($x, 1) . ' ' . round($base, 1) . 'V' . round($top + $r, 1)
                . 'Q' . round($x, 1) . ' ' . round($top, 1) . ' ' . round($x + $r, 1) . ' ' . round($top, 1)
                . 'H' . round($x + $bw - $r, 1) . 'Q' . round($x + $bw, 1) . ' ' . round($top, 1) . ' ' . round($x + $bw, 1) . ' ' . round($top + $r, 1)
                . 'V' . round($base, 1) . 'Z"/>';
        }
        $out .= '</g>';
        if ($i % $every === 0 || $i === $n - 1) {
            $out .= '<text class="rp-xtick" x="' . round($L + $slot * ($i + .5), 1) . '" y="' . ($H - 8) . '">' . e($fmtDay($day)) . '</text>';
        }
    }
    $out .= '<line class="rp-axis" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($y(0), 1) . '" y2="' . round($y(0), 1) . '"/>';
    return $out . '</svg>';
};

/** One series as a line with a soft area and hover dots. */
$area = function (array $vals, string $cls, string $label) use ($buckets, $fmtDay, $nice, $period): string {
    $W = 640; $H = 220; $L = 34; $R = 10; $T = 14; $B = 26;
    $n = count($vals);
    $max = $nice(max(1, ...$vals));
    $x = static fn (int $i): float => $L + ($W - $L - $R) * ($n > 1 ? $i / ($n - 1) : .5);
    $y = static fn (float $v): float => $T + ($H - $T - $B) * (1 - $v / $max);
    $out = '<svg class="rp-svg" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-hidden="true">';
    for ($g = 0; $g <= 4; $g++) {
        $v = $max * $g / 4; $yy = $y($v);
        $out .= '<line class="rp-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($yy, 1) . '" y2="' . round($yy, 1) . '"/>'
            . '<text class="rp-ytick" x="' . ($L - 6) . '" y="' . round($yy + 3.5, 1) . '">' . e(fa_num((int) round($v))) . '</text>';
    }
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($x($i), 1) . ' ' . round($y((float) $v), 1);
    }
    $out .= '<path class="rp-area ' . $cls . '" d="M' . round($x(0), 1) . ' ' . round($y(0), 1) . 'L' . implode('L', $pts) . 'L' . round($x($n - 1), 1) . ' ' . round($y(0), 1) . 'Z"/>';
    $out .= '<path class="rp-line ' . $cls . '" d="M' . implode('L', $pts) . '"/>';
    $every = max(1, (int) ceil($n / 7));
    $slot = ($W - $L - $R) / max(1, $n - 1);
    foreach ($vals as $i => $v) {
        $out .= '<g class="rp-pt"><title>' . e($period . $fmtDay($buckets[$i], true) . ' · ' . $label . ': ' . fa_num((int) $v)) . '</title>'
            . '<rect class="rp-hit" x="' . round($x($i) - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '"/>'
            . '<circle class="rp-dot ' . $cls . '" cx="' . round($x($i), 1) . '" cy="' . round($y((float) $v), 1) . '" r="4"/></g>';
        if ($i % $every === 0 || $i === $n - 1) {
            $out .= '<text class="rp-xtick" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '">' . e($fmtDay($buckets[$i])) . '</text>';
        }
    }
    $out .= '<line class="rp-axis" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($y(0), 1) . '" y2="' . round($y(0), 1) . '"/>';
    return $out . '</svg>';
};

$kpis = [
    ['label' => 'بازدید صفحه‌های شما', 'value' => (int) ($counters['page_views_received'] ?? 0), 'icon' => 'eye', 'tone' => 'blue'],
    ['label' => 'بازدید پیشنهادهای شما', 'value' => (int) ($counters['proposal_views_received'] ?? 0), 'icon' => 'spark', 'tone' => 'gold'],
    ['label' => 'ارتباط‌های تجاری', 'value' => (int) ($counters['connections'] ?? 0), 'icon' => 'chat', 'tone' => 'green'],
    ['label' => 'نامه‌های ارسالی', 'value' => (int) ($counters['letters_sent'] ?? 0), 'icon' => 'letter', 'tone' => 'violet'],
    ['label' => 'پیشنهادهای منتشرشده', 'value' => (int) ($content['live_proposals'] ?? 0), 'icon' => 'send', 'tone' => 'blue'],
    ['label' => 'موجودی Stars', 'value' => (int) ($wallet['balance'] ?? 0), 'icon' => 'star', 'tone' => 'gold'],
];
$breakdown = [
    ['نامه‌های ارسالی', $t('sent:letter_sent'), 'c1'],
    ['پاسخ‌های شما', $t('sent:letter_replied'), 'c1'],
    ['نامه‌های دریافتی', $t('got:letter_sent') + $t('got:letter_replied'), 'c2'],
    ['پیشنهاد ارسالی', $t('sent:proposal_sent'), 'c1'],
    ['پیشنهاد دریافتی', $t('got:proposal_sent'), 'c2'],
    ['بازدید پیشنهادهای شما', $t('got:proposal_viewed'), 'c2'],
    ['صفحه‌های شما که کامل دیده شد', $t('got:page_viewed'), 'c2'],
    ['ارتباط تازه', $t('sent:connection_created') + $t('got:connection_created'), 'c3'],
];
$bmax = max(1, ...array_column($breakdown, 1));
$rangeLabel = ['7' => '۷ روز', '30' => '۳۰ روز', '90' => '۹۰ روز'];
$icon = static fn (string $id): string => '<svg class="icon"><use href="#i-' . e($id) . '"/></svg>';
?>
<div class="rp">
  <section class="rp-head">
    <div>
      <h2>گزارش‌های من</h2>
      <p class="muted">همه کارهایی که در سامانه انجام داده‌اید و بازخوردی که گرفته‌اید، در <?= e($rangeLabel[(string) $days]) ?> اخیر.</p>
    </div>
    <nav class="rp-range" aria-label="بازه گزارش">
      <?php foreach (ReportsController::RANGES as $r): ?>
        <a href="/reports?days=<?= $r ?>"<?= $r === $days ? ' aria-current="true"' : '' ?>><?= e($rangeLabel[(string) $r]) ?></a>
      <?php endforeach; ?>
    </nav>
  </section>

  <section class="rp-kpis" aria-label="خلاصه">
    <?php foreach ($kpis as $k): ?>
      <div class="rp-kpi rp-<?= e($k['tone']) ?>">
        <span class="rp-kpi-ic"><?= $icon($k['icon']) ?></span>
        <b><?= e(fa_int($k['value'])) ?></b>
        <small><?= e($k['label']) ?></small>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="rp-grid-2">
    <section class="rp-card">
      <header>
        <h3>نامه‌ها</h3>
        <ul class="rp-legend"><li><i class="c1"></i>ارسالی <b><?= e(fa_int($sum($series['sent']))) ?></b></li><li><i class="c2"></i>دریافتی <b><?= e(fa_int($sum($series['got']))) ?></b></li></ul>
      </header>
      <?= $sum($series['sent']) + $sum($series['got']) > 0 ? $bars([['ارسالی', 'c1', $series['sent']], ['دریافتی', 'c2', $series['got']]], 'نامه') : '<p class="rp-empty">در این بازه نامه‌ای ارسال یا دریافت نشده است.</p>' ?>
    </section>
    <section class="rp-card">
      <header>
        <h3>بازدید پیشنهادهای شما</h3>
        <ul class="rp-legend"><li>مجموع <b><?= e(fa_int($sum($series['views']))) ?></b></li></ul>
      </header>
      <?= $sum($series['views']) > 0 ? $area($series['views'], 'c2', 'بازدید') : '<p class="rp-empty">هنوز کسی در این بازه پیشنهادهای شما را ندیده است. <a href="/proposals/new">ثبت پیشنهاد تجاری</a></p>' ?>
    </section>
    <section class="rp-card">
      <header>
        <h3>گردش Stars</h3>
        <ul class="rp-legend"><li><i class="c3"></i>افزایش <b><?= e(fa_int($sum($series['in']))) ?></b></li><li><i class="c4"></i>کاهش <b><?= e(fa_int($sum($series['out']))) ?></b></li></ul>
      </header>
      <?= $sum($series['in']) + $sum($series['out']) > 0 ? $bars([['افزایش', 'c3', $series['in']], ['کاهش', 'c4', $series['out']]], 'Star') : '<p class="rp-empty">در این بازه تراکنشی ثبت نشده است. <a href="/wallet">کیف پول</a></p>' ?>
    </section>
    <section class="rp-card">
      <header><h3>خلاصه فعالیت</h3></header>
      <ul class="rp-hbars">
        <?php foreach ($breakdown as [$label, $v, $cls]): ?>
          <li><span><?= e($label) ?></span><span class="rp-track"><i class="<?= e($cls) ?>" data-w="<?= (int) round($v / $bmax * 100) ?>"></i></span><b><?= e(fa_int($v)) ?></b></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <section class="rp-card">
    <header><h3>رویدادهای اخیر</h3></header>
    <?php if ($recent === []): ?>
      <p class="rp-empty">هنوز رویدادی ثبت نشده است. با <a href="/discover">کشف تجار</a> شروع کنید.</p>
    <?php else: ?>
      <ol class="rp-feed">
        <?php foreach ($recent as $r):
            $ev = ReportsController::EVENTS[$r['event']] ?? null;
            if ($ev === null) { continue; }
            $mine = (int) $r['mine'] === 1;
            $who = $names[(int) $r['other']] ?? '';
            $text = $mine ? $ev['sent'] . ($who !== '' ? ' (' . $who . ')' : '') : ($who !== '' ? $who : 'یک تاجر') . ' ' . $ev['got'];
            if ($text === '' || (!$mine && $ev['got'] === '')) { continue; } ?>
          <li class="<?= $mine ? 'is-mine' : 'is-got' ?>"><i aria-hidden="true"></i><span><?= e($text) ?>.</span><time><?= e(fa_date($r['created_at'])) ?></time></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</div>
