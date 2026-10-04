<?php
/**
 * Language switcher (everyone can change the interface language). A plain <details> menu of /lang/{code} links,
 * so it works without JavaScript; the choice is kept in a cookie and on the member's account.
 * @var string $variant landing|panel|auth @var string|null $globe icon markup for the landing variant
 */
use App\Core\I18n\I18n;

$variant ??= 'panel';
$locales = I18n::enabledLocales();
if (count($locales) < 2) {
    return;
}
$current = I18n::locale();
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$parts = parse_url($uri);
$back = (string) ($parts['path'] ?? '/');
if (isset($parts['query'])) {
    parse_str($parts['query'], $q);
    unset($q['lang']);
    if ($q !== []) {
        $back .= '?' . http_build_query($q);
    }
}
$label = t('زبان سامانه');
if ($variant === 'list'):
?>
<nav class="th-menu-langs" aria-label="<?= te($label) ?>">
  <?php foreach ($locales as $code => $l): ?><a href="/lang/<?= e($code) ?>?to=<?= e(rawurlencode($back)) ?>" lang="<?= e($code) ?>" dir="<?= e($l['dir']) ?>" hreflang="<?= e($code) ?>" rel="nofollow"<?= $code === $current ? ' aria-current="true"' : '' ?>><?= e($l['native']) ?></a><?php endforeach; ?>
</nav>
<?php
    return;
endif;
?>
<details class="lang-pick lang-pick-<?= e($variant) ?>">
  <?php if ($variant === 'landing'): ?>
  <summary class="th-lang" title="<?= te($label) ?>" aria-label="<?= e($label . ': ' . $locales[$current]['native']) ?>"><?= $globe ?? '' ?><?= e(strtoupper($current)) ?></summary>
  <?php elseif ($variant === 'auth'): ?>
  <summary class="lang-pill" title="<?= te($label) ?>" aria-label="<?= e($label . ': ' . $locales[$current]['native']) ?>"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.7 2.6 15.3 0 18M12 3c-2.6 2.7-2.6 15.3 0 18"/></svg><span><?= e($locales[$current]['native']) ?></span></summary>
  <?php else: ?>
  <summary class="icon-btn lang-btn" title="<?= te($label) ?>" aria-label="<?= e($label . ': ' . $locales[$current]['native']) ?>"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.7 2.6 15.3 0 18M12 3c-2.6 2.7-2.6 15.3 0 18"/></svg><i class="lang-code" aria-hidden="true"><?= e(strtoupper($current)) ?></i></summary>
  <?php endif; ?>
  <div class="lang-menu" role="menu" aria-label="<?= te($label) ?>">
    <b class="lang-menu-h"><?= te($label) ?></b>
    <?php foreach ($locales as $code => $l): ?>
    <a role="menuitemradio" aria-checked="<?= $code === $current ? 'true' : 'false' ?>" href="/lang/<?= e($code) ?>?to=<?= e(rawurlencode($back)) ?>" lang="<?= e($code) ?>" dir="<?= e($l['dir']) ?>" hreflang="<?= e($code) ?>" rel="nofollow"><span><?= e($l['native']) ?></span><small><?= e(strtoupper($code)) ?></small></a>
    <?php endforeach; ?>
  </div>
</details>
