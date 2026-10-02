<?php
/** @var array $campaign @var int $balance @var ?array $country @var ?array $language @var ?array $category */
use App\Modules\Letters\CampaignService;
$st = (int) $campaign['status'];
$cost = (int) $campaign['total_cost'];
$total = (int) $campaign['recipient_count'];
$done = (int) $campaign['delivered_count'];
$pct = $total > 0 ? (int) floor($done * 100 / $total) : 0;
$kindLabel = CampaignService::KINDS[(int) ($campaign['kind'] ?? 2)]['label'] ?? 'نامه عمومی';
?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => 'groups', 'canOfficial' => $canOfficial ?? false]) ?>
  <div class="mail-content stack">
<section class="panel form campaign"<?= $st === CampaignService::DELIVERING ? ' data-autorefresh="8"' : '' ?>>
  <div class="panel-head"><div><span class="chip chip-gold"><?= e($kindLabel) ?></span><h2><?= e($campaign['subject']) ?></h2></div><span class="chip<?= $st === CampaignService::COMPLETED ? ' chip-ok' : '' ?>"><?= e(CampaignService::LABELS[$st] ?? '') ?></span></div>
  <dl class="result-meta">
    <div><dt>کشور</dt><dd><?= $country ? e(flag($country['code']) . ' ' . $country['name_fa']) : 'همه' ?></dd></div>
    <div><dt>زبان</dt><dd><?= $language ? e($language['name_fa']) : 'همه' ?></dd></div>
    <div><dt>حوزه فعالیت</dt><dd><?= $category ? e($category['name_fa']) : 'همه' ?></dd></div>
    <?php if (!empty($campaign['filter']['handles'])): ?><div><dt>کاربران منتخب</dt><dd><?= fa_int(count($campaign['filter']['handles'])) ?> نشانی</dd></div><?php endif; ?>
    <div><dt>تعداد گیرندگان</dt><dd><?= fa_int($total) ?> <span class="muted">(<?= fa_int((int) $campaign['domestic_count']) ?> داخلی)</span></dd></div>
    <div><dt>هزینه</dt><dd><?= fa_int($cost) ?> Star</dd></div>
    <?php if ($st === CampaignService::QUOTED): ?>
      <div><dt>موجودی فعلی</dt><dd><?= fa_int($balance) ?> Star</dd></div>
      <div><dt>موجودی پس از ارسال</dt><dd><?= fa_int(max(0, $balance - $cost)) ?> Star</dd></div>
    <?php endif; ?>
  </dl>

  <?php if ($st === CampaignService::QUOTED): ?>
    <?php if ($balance >= $cost): ?>
      <form method="post" action="/letters/public/<?= e($campaign['uid']) ?>/send" class="form-actions">
        <?= csrf_field() ?>
        <button class="btn" type="submit"><svg class="icon"><use href="#i-send"/></svg>تأیید و ارسال برای <?= fa_int($total) ?> نفر</button>
        <a class="btn btn-quiet" href="/letters/send">ویرایش</a>
      </form>
    <?php else: ?>
      <div class="form-actions"><button class="btn" type="button" disabled aria-disabled="true"><svg class="icon"><use href="#i-send"/></svg>تأیید و ارسال برای <?= fa_int($total) ?> نفر</button><a class="btn btn-quiet" href="/letters/send">ویرایش</a></div>
      <?= $this->partial('partials/stars_short', ['need' => $cost - $balance, 'buyHref' => !empty($buyEnabled) ? '/wallet?need=' . ($cost - $balance) . '&next=' . rawurlencode('/letters/public/' . $campaign['uid']) : null]) ?>
    <?php endif; ?>
  <?php else: ?>
    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>"><span class="progress-<?= (int) round($pct / 5) * 5 ?>"></span></div>
    <p class="muted"><?= fa_int($done) ?> از <?= fa_int($total) ?> گیرنده دریافت کردند<?= $st === CampaignService::COMPLETED && (int) $campaign['spent'] < $cost ? ' · ' . fa_int($cost - (int) $campaign['spent']) . ' Star به کیف پول شما برگشت' : '' ?>.</p>
  <?php endif; ?>
</section>
<section class="panel"><h3><?= (int) ($campaign['kind'] ?? 2) === 3 ? 'پیام همراه پیشنهاد' : 'متن نامه' ?></h3><div class="prose"><?= nl2br(e($campaign['body']), false) ?></div></section>

  </div>
</div>
