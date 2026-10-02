<?php
/** @var array $user @var string $folderKey @var string $category @var array $rows @var ?string $next @var array $campaigns
 *  @var array $announcements @var int $lastSeen @var array $unreadByType @var int $unreadOfficial @var string $orgName */
use App\Modules\Letters\CampaignService;
use App\Modules\Letters\LetterService;
$cats = ['all' => 'همه', 'private' => 'اختصاصی', 'public' => 'عمومی', 'proposal' => 'پیشنهادها', 'official' => $orgName];
$catBadge = static function (string $key) use ($unreadByType, $unreadOfficial): int {
    $type = LetterService::CATEGORIES[$key];
    if ($key === 'all') {
        return array_sum($unreadByType) + $unreadOfficial;
    }
    return ($unreadByType[$type] ?? 0) + ($key === 'official' ? $unreadOfficial : 0);
};
$url = static fn (string $folder, string $cat): string => '/letters?folder=' . $folder . ($cat !== 'all' ? '&type=' . $cat : '');
?>
<div class="mailbox">
  <?= $this->partial('letters/_nav', ['user' => $user, 'active' => $folderKey, 'canOfficial' => $canOfficial ?? false]) ?>

  <section class="mail-content">
    <?php if ($folderKey !== 'groups'): ?>
      <nav class="mail-cats" aria-label="نوع نامه">
        <?php foreach ($cats as $key => $label): $b = $folderKey === 'inbox' ? $catBadge($key) : 0; ?>
          <a href="<?= e($url($folderKey, $key)) ?>"<?= $category === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($b > 0): ?> <em class="badge"><?= e(fa_num(min(99, $b))) ?></em><?php endif; ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if ($folderKey === 'groups'): ?>
      <?php if ($campaigns === []): ?>
        <div class="panel empty">
          <svg class="icon"><use href="#i-letter"/></svg>
          <h3>هنوز ارسال گروهی نداشته‌اید</h3>
          <p>با یک ارسال، تجار یک کشور، زبان یا حوزه فعالیت را از نامه یا پیشنهاد خود باخبر کنید.</p>
          <a class="btn" href="/letters/send">ارسال نامه</a>
        </div>
      <?php else: ?>
        <ul class="mail">
          <?php foreach ($campaigns as $c): $st = (int) $c['status']; ?>
            <li class="mail-row">
              <a href="/letters/public/<?= e($c['uid']) ?>">
                <span class="mail-main">
                  <span class="mail-top"><span class="mail-name"><?= e($c['subject']) ?></span><span class="mail-time"><?= e(fa_date($c['created_at'])) ?></span></span>
                  <span class="mail-preview"><span class="chip"><?= e(CampaignService::KINDS[(int) $c['kind']]['label'] ?? '') ?></span> <?= fa_int((int) $c['delivered_count']) ?> از <?= fa_int((int) $c['recipient_count']) ?> گیرنده · <?= e(CampaignService::LABELS[$st] ?? '') ?></span>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php else: ?>

      <?php if ($announcements !== []): ?>
        <ul class="mail mail-official" aria-label="نامه‌های <?= e($orgName) ?> به همه">
          <?php foreach ($announcements as $a): $new = (int) $a['id'] > $lastSeen; ?>
            <li class="mail-row<?= $new ? ' unread' : '' ?>">
              <a href="/letters/official/<?= e($a['uid']) ?>">
                <span class="avatar org-avatar"><svg class="icon"><use href="#i-mark"/></svg></span>
                <span class="mail-main">
                  <span class="mail-top"><span class="mail-name"><?= e($orgName) ?> <span class="chip chip-gold">رسمی</span></span><span class="mail-time"><?= e(fa_date($a['published_at'])) ?></span></span>
                  <span class="mail-subject"><?= e($a['subject']) ?></span>
                  <span class="mail-preview"><?= e(\App\Core\Support\Str::excerpt((string) $a['body'], 140)) ?></span>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($rows === [] && $announcements === []): ?>
        <div class="panel empty">
          <svg class="icon"><use href="#i-chat"/></svg>
          <h3><?= $folderKey === 'inbox' ? 'نامه‌ای در این بخش ندارید' : ($folderKey === 'sent' ? 'هنوز نامه‌ای نفرستاده‌اید' : 'بایگانی خالی است') ?></h3>
          <p>صفحه تجار دیگر را ببینید و گفتگو را شروع کنید. هر پاسخ، یک ارتباط تجاری تازه می‌سازد.</p>
          <a class="btn" href="/letters/send">ارسال نامه</a>
        </div>
      <?php elseif ($rows !== []): ?>
        <ul class="mail" aria-label="نامه‌ها">
          <?php foreach ($rows as $r): $peer = $r['peer']; $unread = (int) $r['unread_count'] > 0;
              $official = (int) $r['thread_type'] === LetterService::T_OFFICIAL && (int) $r['created_by'] !== (int) $user['id'];
              $name = $official ? $orgName : ($peer ? ($peer['company_name'] ?: trim($peer['first_name'] . ' ' . $peer['last_name'])) : '—');
              $av = !$official && $peer ? media($peer['avatar_path']) : null; ?>
            <li class="mail-row<?= $unread ? ' unread' : '' ?>">
              <a href="/letters/<?= e($r['uid']) ?>">
                <?php if ($official): ?><span class="avatar org-avatar"><svg class="icon"><use href="#i-mark"/></svg></span>
                <?php elseif ($av): ?><img class="avatar" src="<?= e($av) ?>" alt="" loading="lazy">
                <?php else: ?><span class="avatar"><?= e($peer ? initials($peer['first_name'], $peer['last_name']) : '?') ?></span><?php endif; ?>
                <span class="mail-main">
                  <span class="mail-top">
                    <span class="mail-name"><?= e($name) ?> <?php if (!$official): ?><span class="flag" aria-hidden="true"><?= flag($peer['country_code'] ?? '') ?></span><?php endif; ?></span>
                    <span class="mail-time"><?= e(fa_date($r['last_message_at'])) ?></span>
                  </span>
                  <span class="mail-subject"><?php if ($category === 'all'): ?><span class="chip"><?= e(LetterService::TYPE_LABELS[(int) $r['thread_type']] ?? '') ?></span> <?php endif; ?><?= e($r['subject']) ?></span>
                  <span class="mail-preview"><?= e($r['preview']) ?></span>
                </span>
                <?php if ($unread): ?><em class="badge" aria-label="<?= e(fa_num($r['unread_count'])) ?> خوانده‌نشده"><?= e(fa_num($r['unread_count'])) ?></em><?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($next): ?><div class="form-actions form-actions-center feed-foot"><a class="btn btn-ghost btn-sm" href="<?= e($url($folderKey, $category)) ?>&amp;cursor=<?= e(rawurlencode($next)) ?>">نامه‌های قدیمی‌تر</a></div><?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
