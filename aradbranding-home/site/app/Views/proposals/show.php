<?php
/** @var array $p @var bool $isOwner @var array|null $category */
use App\Modules\Proposals\ProposalService;
$cover = media($p['cover_path']);
$gallery = json_decode((string) ($p['images'] ?? '[]'), true) ?: [];
$tags = json_decode((string) ($p['tags'] ?? '[]'), true) ?: [];
$owner = $p['company_name'] ?: trim($p['first_name'] . ' ' . $p['last_name']);
$status = (int) $p['status'];
// Proposals are public: any off-platform contact left in older text is masked (new text is rejected on save).
foreach (['title', 'summary', 'body', 'product', 'quantity', 'target_markets', 'terms', 'company_name'] as $field) {
    if (is_string($p[$field] ?? null)) {
        $p[$field] = \App\Core\Security\ContactGuard::mask($p[$field]);
    }
}
$owner = $p['company_name'] ?: trim($p['first_name'] . ' ' . $p['last_name']);
?>
<article class="pdetail">
  <?php if ($cover): ?><div class="pdetail-cover"><img src="<?= e($cover) ?>" alt="" width="1200" height="900"></div><?php endif; ?>
  <div class="pdetail-main">
    <div class="pub-meta">
      <span class="chip chip-gold"><?= e(ProposalService::TYPES[(int) $p['type']] ?? '') ?></span>
      <?php if ($category): ?><span class="chip"><?= e($category['name_fa']) ?></span><?php endif; ?>
      <?php if ($status !== ProposalService::PUBLISHED): ?><span class="chip"><?= e(ProposalService::STATUS_LABELS[$status] ?? '') ?></span><?php endif; ?>
    </div>
    <h1 class="pdetail-title"><?= e($p['title']) ?></h1>
    <p class="pdetail-summary"><?= e($p['summary']) ?></p>

    <a class="owner-row" href="<?= $p['handle'] ? '/p/' . e($p['handle']) : '#' ?>">
      <?php $oa = media($p['owner_avatar']); ?>
      <?php if ($oa): ?><img class="avatar" src="<?= e($oa) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($p['first_name'], $p['last_name'])) ?></span><?php endif; ?>
      <span class="grow"><b><?= e($owner) ?></b><span class="muted"><?= flag($p['country_code']) ?> <?= e($p['country_fa']) ?><?= $p['business_verified_at'] ? ' · تأییدشده' : '' ?></span></span>
      <?php if ($p['handle']): ?><span class="btn btn-ghost btn-sm">صفحه تجاری</span><?php endif; ?>
    </a>

    <?php if ($p['body']): ?><section class="pub-section"><h2>توضیحات</h2><div class="prose"><?= rich_text($p['body']) ?></div></section><?php endif; ?>

    <section class="pub-section">
      <h2>مشخصات</h2>
      <dl class="specs">
        <?php foreach (['product' => 'محصول / خدمت', 'quantity' => 'مقدار', 'target_markets' => 'بازار هدف'] as $k => $label): if (!empty($p[$k])): ?>
          <div><dt><?= e($label) ?></dt><dd><?= e($p[$k]) ?></dd></div>
        <?php endif; endforeach; ?>
        <div><dt>کشور</dt><dd><?= flag($p['country_code']) ?> <?= e($p['country_fa']) ?></dd></div>
        <?php if ($p['published_at']): ?><div><dt>انتشار</dt><dd class="ltr"><?= e(substr((string) $p['published_at'], 0, 10)) ?></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($p['terms']): ?><section class="pub-section"><h2>شرایط</h2><div class="prose"><?= rich_text($p['terms']) ?></div></section><?php endif; ?>

    <?php if ($gallery): ?>
      <section class="gallery" aria-label="تصاویر">
        <?php foreach ($gallery as $g): ?><span class="gallery-item"><img src="<?= e(media($g)) ?>" alt="" loading="lazy" decoding="async" draggable="false"></span><?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php if ($tags): ?><div class="pcard-tags"><?php foreach ($tags as $t): ?><span>#<?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>

    <div class="pdetail-actions">
      <?php if ($isOwner): ?>
        <a class="btn" href="/proposals/<?= e($p['uid']) ?>/edit">ویرایش</a>
        <span class="muted"><svg class="icon inline-icon"><use href="#i-eye"/></svg> <?= fa_int((int) $p['view_count']) ?> بازدید · <?= fa_int((int) $p['send_count']) ?> ارسال</span>
      <?php elseif ($p['handle']): ?>
        <a class="btn" href="/letters/new?to=<?= e($p['handle']) ?>"><svg class="icon"><use href="#i-letter"/></svg>با من ارتباط بگیرید</a>
      <?php endif; ?>
      <?php if (!$isOwner): ?><?= $this->partial('partials/report', ['type' => 'proposal', 'id' => (int) $p['id'], 'back' => '/proposals/' . $p['uid'], 'blockUser' => (int) $p['user_id']]) ?><?php endif; ?>
    </div>
  </div>
</article>
