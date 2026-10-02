<?php /** @var string $tab @var array $rows @var ?int $next @var string $status @var array $perms */
use App\Modules\Proposals\ProposalService;
$pageStatus = [0 => 'پیش‌نویس', 2 => 'منتشرشده', 3 => 'مخفی', 4 => 'ردشده'];
$labels = $tab === 'pages' ? $pageStatus : ProposalService::STATUS_LABELS;
$canAct = $tab === 'pages' ? isset($perms['pages.approve']) : isset($perms['proposals.moderate']); ?>
<?= $this->partial('admin/_wrap_start', ['perms' => $perms, 'active' => 'content']) ?>
<nav class="mail-cats" aria-label="نوع محتوا">
  <a href="/admin/content"<?= $tab === 'proposals' ? ' aria-current="page"' : '' ?>>پیشنهادها</a>
  <a href="/admin/content?tab=pages"<?= $tab === 'pages' ? ' aria-current="page"' : '' ?>>صفحه‌های تجاری</a>
</nav>
<form class="filters-form admin-search" method="get" action="/admin/content">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <select class="select" name="status" aria-label="وضعیت"><option value="">همه وضعیت‌ها</option>
    <?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>"<?= $status === (string) $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm" type="submit">نمایش</button>
</form>
<section class="panel">
  <?php if ($rows === []): ?><div class="empty"><p>موردی پیدا نشد.</p></div><?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $r): $st = (int) $r['status']; ?>
        <li class="list-row">
          <?php if ($tab === 'proposals'): ?><?php if ($r['thumb_path']): ?><img class="thumb-sm" src="<?= e(media($r['thumb_path'])) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb-sm"></span><?php endif; ?><?php else: ?><span class="lang-badge"><?= e($r['lang']) ?></span><?php endif; ?>
          <div class="grow">
            <a class="title" href="<?= $tab === 'proposals' ? '/proposals/' . e($r['uid']) : ($r['handle'] ? '/p/' . e($r['handle']) . '/' . e($r['lang']) : '#') ?>" target="_blank" rel="noopener"><?= e($r['title']) ?></a>
            <div class="meta"><a href="/admin/users/<?= e($r['user_id']) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a><span><?= e(fa_date($r['created_at'])) ?></span></div>
            <p class="list-note"><?= e(\App\Core\Support\Str::excerpt((string) ($r['summary'] ?? $r['teaser'] ?? ''), 140)) ?></p>
          </div>
          <span class="chip<?= $st === 2 ? ' chip-ok' : '' ?>"><?= e($labels[$st] ?? '') ?></span>
          <?php if ($canAct): ?>
            <form class="inline-form" method="post" action="/admin/content/<?= $tab === 'pages' ? 'pages' : 'proposals' ?>/<?= e($r['id']) ?>">
              <?= csrf_field() ?>
              <?php if ($st === 2): ?><button class="btn btn-quiet btn-sm" name="action" value="hide">مخفی</button><button class="btn btn-danger btn-sm" name="action" value="reject">رد</button>
              <?php else: ?><button class="btn btn-ghost btn-sm" name="action" value="restore">انتشار مجدد</button><?php endif; ?>
              <?php if ($tab === 'pages'): ?><button class="btn btn-danger btn-sm" name="action" value="delete" data-confirm="این صفحه برای همیشه حذف شود؟ قوانین نمایش مربوط به آن هم حذف می‌شوند.">حذف</button><?php endif; ?>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($next): ?><div class="form-actions form-actions-center"><a class="btn btn-ghost btn-sm" href="/admin/content?<?= e(http_build_query(['tab' => $tab, 'status' => $status ?: null, 'before' => $next])) ?>">بیشتر</a></div><?php endif; ?>
  <?php endif; ?>
</section>
<?= $this->partial('admin/_wrap_end') ?>
