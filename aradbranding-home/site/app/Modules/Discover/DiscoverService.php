<?php

declare(strict_types=1);

namespace App\Modules\Discover;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/**
 * Discovery (doc §38): recommended traders, new traders, new and popular opportunities, countries.
 * Global sections are cached for 5 minutes; the personal section is one indexed query.
 */
final class DiscoverService
{
    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    /** Traders who recently published in the viewer's interest categories (fallback: active traders of the viewer's country). */
    public function recommended(array $viewer, array $interests): array
    {
        if ($interests !== []) {
            $marks = implode(',', array_fill(0, count($interests), '?'));
            $ids = array_map('intval', array_column($this->db->select(
                "SELECT user_id, MAX(published_at) AS last FROM proposal_feed
                 WHERE category_id IN ({$marks}) AND user_id <> ? AND published_at > NOW(3) - INTERVAL 60 DAY
                 GROUP BY user_id ORDER BY last DESC LIMIT 8",
                [...array_map('intval', $interests), (int) $viewer['id']]
            ), 'user_id'));
        } else {
            $ids = array_map('intval', array_column($this->db->select(
                'SELECT DISTINCT p.user_id FROM pages p WHERE p.country_id = ? AND p.status = 2 AND p.deleted_at IS NULL AND p.user_id <> ?
                 ORDER BY p.id DESC LIMIT 8',
                [(int) $viewer['country_id'], (int) $viewer['id']]
            ), 'user_id'));
        }
        return $this->traders($ids);
    }

    /** @return list<array<string, mixed>> */
    public function newTraders(): array
    {
        return $this->cache->remember('discover:new_traders', 300, function (): array {
            $ids = array_map('intval', array_column($this->db->select(
                'SELECT user_id, MAX(id) AS m FROM pages WHERE status = 2 AND deleted_at IS NULL GROUP BY user_id ORDER BY m DESC LIMIT 8'
            ), 'user_id'));
            return $this->traders($ids);
        });
    }

    /** @return list<array<string, mixed>> */
    public function newProposals(): array
    {
        return $this->cache->remember('discover:new_proposals:10', 300, fn (): array => $this->cards(
            $this->db->select('SELECT proposal_id, published_at, card FROM proposal_feed ORDER BY published_at DESC, proposal_id DESC LIMIT 10')
        ));
    }

    /** @return list<array<string, mixed>> */
    public function popular(): array
    {
        return $this->cache->remember('discover:popular:10', 300, fn (): array => $this->cards(
            $this->db->select('SELECT proposal_id, published_at, card FROM proposal_feed WHERE views_7d > 0 ORDER BY views_7d DESC, published_at DESC LIMIT 10')
        ));
    }

    /** Countries with the most published pages. @return list<array{country_id: int, n: int}> */
    public function countries(): array
    {
        return $this->cache->remember('discover:countries', 3600, fn (): array => array_map(
            static fn ($r) => ['country_id' => (int) $r['country_id'], 'n' => (int) $r['n']],
            $this->db->select('SELECT country_id, COUNT(DISTINCT user_id) AS n FROM pages WHERE status = 2 AND deleted_at IS NULL GROUP BY country_id ORDER BY n DESC LIMIT 24')
        ));
    }

    /**
     * Trader directory for one country, keyset on page id.
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function tradersOf(int $countryId, ?int $before): array
    {
        $rows = $this->db->select(
            'SELECT p.id, p.user_id FROM pages p
             WHERE p.country_id = ? AND p.status = 2 AND p.deleted_at IS NULL' . ($before ? ' AND p.id < ?' : '') . '
             ORDER BY p.id DESC LIMIT 61',
            $before ? [$countryId, $before] : [$countryId]
        );
        $next = null;
        if (count($rows) > 60) {
            array_pop($rows);
            $next = (int) end($rows)['id'];
        }
        $ids = array_values(array_unique(array_map('intval', array_column($rows, 'user_id'))));
        return ['rows' => $this->traders($ids), 'next' => $next];
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function traders(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->select(
            "SELECT u.id, u.first_name, u.last_name, u.handle, u.avatar_path, u.business_verified_at, c.code AS country_code,
                    pr.company_name, pr.business_area
             FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN user_profiles pr ON pr.user_id = u.id
             WHERE u.id IN ({$marks}) AND u.status IN (1, 2) AND u.handle IS NOT NULL",
            $ids
        );
        $order = array_flip($ids);
        usort($rows, static fn ($a, $b) => ($order[(int) $a['id']] ?? 0) <=> ($order[(int) $b['id']] ?? 0));
        return $rows;
    }

    private function cards(array $rows): array
    {
        return array_map(static function (array $r): array {
            $card = json_decode((string) $r['card'], true) ?: [];
            $card['id'] = (int) $r['proposal_id'];
            $card['published_at'] = (string) $r['published_at'];
            return $card;
        }, $rows);
    }
}
