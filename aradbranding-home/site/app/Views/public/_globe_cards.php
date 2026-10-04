<?php
/**
 * Country cards placed on the globe by trade-globe.js (App\Modules\System\GlobeCards). Links on the home page,
 * plain cards on the sign-in / sign-up pages.
 * @var list<array{code: string, name: string, lat: float, lon: float, note: string, class: string}> $cards
 * @var (callable(string): string)|null $href
 */
use App\Modules\System\GlobeCards;

$href ??= null;
$flagOf = static fn (string $code): string => in_array($code, GlobeCards::SPRITE_FLAGS, true)
    ? '<svg class="th-flag" viewBox="0 0 30 20" aria-hidden="true"><use href="#flag-' . e($code) . '"/></svg>'
    : '<span class="th-flag th-flag-emoji" aria-hidden="true">' . flag($code) . '</span>';
?>
<?php foreach ($cards as $c): $tag = $href !== null ? 'a' : 'span'; ?>
      <<?= $tag ?> class="<?= e($c['class']) ?>"<?= $href !== null ? ' href="' . e($href($c['code'])) . '"' : '' ?> data-lat="<?= e($c['lat']) ?>" data-lon="<?= e($c['lon']) ?>" data-name="<?= e($c['name']) ?>" data-note="<?= e($c['note']) ?>">
        <?= $flagOf($c['code']) ?>

        <span class="tg-card-t"><b><?= e($c['name']) ?></b><small><?= e($c['note']) ?></small></span>
      </<?= $tag ?>>
<?php endforeach; ?>
