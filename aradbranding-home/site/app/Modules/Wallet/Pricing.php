<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/** Prices in Stars, per action and scope. Never hard-coded: read from pricing_rules. */
final class Pricing
{
    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    public function price(string $action, bool $domestic): int
    {
        $all = $this->all();
        $price = $all[$action][$domestic ? 'domestic' : 'international'] ?? null;
        if ($price === null) {
            throw new \RuntimeException("No price configured for {$action}");
        }
        return (int) $price;
    }

    /** @return array<string, array<string, int>> */
    public function all(): array
    {
        return $this->cache->remember('pricing:v' . $this->cache->version('pricing'), 3600, function (): array {
            $out = [];
            foreach ($this->db->select('SELECT action, scope, stars FROM pricing_rules') as $r) {
                $out[$r['action']][$r['scope']] = (int) $r['stars'];
            }
            return $out;
        });
    }

    /** @return array{currency: string, minor_per_star: int, minor_digits: int}|null */
    public function rate(string $currency): ?array
    {
        $rates = $this->cache->remember('fx:v' . $this->cache->version('pricing'), 3600, function (): array {
            $out = [];
            foreach ($this->db->select('SELECT currency, minor_per_star, minor_digits FROM fx_rates') as $r) {
                $out[$r['currency']] = ['currency' => $r['currency'], 'minor_per_star' => (int) $r['minor_per_star'], 'minor_digits' => (int) $r['minor_digits']];
            }
            return $out;
        });
        return $rates[$currency] ?? null;
    }

    /** @return list<array{id: int, stars: int, bonus_stars: int}> */
    public function packages(): array
    {
        return $this->cache->remember('packages:v' . $this->cache->version('pricing'), 3600, fn (): array => array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'stars' => (int) $r['stars'], 'bonus_stars' => (int) $r['bonus_stars']],
            $this->db->select('SELECT id, stars, bonus_stars FROM star_packages WHERE active = 1 ORDER BY sort, stars')
        ));
    }
}
