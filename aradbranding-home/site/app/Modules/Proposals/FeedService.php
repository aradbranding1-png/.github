<?php

declare(strict_types=1);

namespace App\Modules\Proposals;

use App\Core\Db\Connection;
use App\Core\Settings\Settings;

/**
 * Proposal feed from the narrow `proposal_feed` read model with keyset pagination.
 *  - latest: chronological, optional filters (category / country / type).
 *  - for you: next chronological window re-ranked by score + diversity; the first page
 *    also mixes in recent proposals from the user's interest categories.
 * No ORDER BY RAND(), no OFFSET, no scan of `proposals`.
 */
final class FeedService
{
    public const PAGE = 24;

    public function __construct(private Connection $db, private Settings $settings)
    {
    }

    /**
     * @param array{category?: ?int, country?: ?int, type?: ?int} $filters
     * @return array{items: list<array<string, mixed>>, next: ?string}
     */
    public function latest(array $filters, ?string $cursor): array
    {
        [$rows, $next] = $this->window($filters, $cursor, self::PAGE);
        return ['items' => array_map([$this, 'hydrate'], $rows), 'next' => $next];
    }

    /** @return array{items: list<array<string, mixed>>, next: ?string} */
    public function forYou(array $viewer, array $interests, ?string $cursor): array
    {
        [$rows, $next] = $this->window([], $cursor, 36);

        if ($cursor === null && $interests !== []) {
            $parts = [];
            $bind = [];
            foreach (array_slice($interests, 0, 3) as $cat) {
                $parts[] = '(SELECT proposal_id, user_id, published_at, category_id, country_id, language_id, type, views_7d, verified, card
                             FROM proposal_feed WHERE category_id = ? AND published_at > NOW(3) - INTERVAL 14 DAY
                             ORDER BY published_at DESC, proposal_id DESC LIMIT 4)';
                $bind[] = (int) $cat;
            }
            $seen = array_flip(array_column($rows, 'proposal_id'));
            foreach ($this->db->select(implode(' UNION ALL ', $parts), $bind) as $r) {
                if (!isset($seen[$r['proposal_id']])) {
                    $rows[] = $r;
                    $seen[$r['proposal_id']] = true;
                }
            }
        }
        $ranked = $this->diversify($this->score($rows, $viewer, $interests));
        return ['items' => array_map([$this, 'hydrate'], $ranked), 'next' => $next];
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?string} */
    private function window(array $filters, ?string $cursor, int $limit): array
    {
        $where = [];
        $bind = [];
        foreach (['category' => 'category_id', 'country' => 'country_id', 'type' => 'type'] as $key => $col) {
            if (!empty($filters[$key])) {
                $where[] = "{$col} = ?";
                $bind[] = (int) $filters[$key];
            }
        }
        $c = self::decodeCursor($cursor);
        if ($c !== null) {
            $where[] = '(published_at < ? OR (published_at = ? AND proposal_id < ?))';
            array_push($bind, $c[0], $c[0], $c[1]);
        }
        $rows = $this->db->select(
            'SELECT proposal_id, user_id, published_at, category_id, country_id, language_id, type, views_7d, verified, card
             FROM proposal_feed' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY published_at DESC, proposal_id DESC
             LIMIT ' . ($limit + 1),
            $bind
        );
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = end($rows);
            $next = self::encodeCursor((string) $last['published_at'], (int) $last['proposal_id']);
        }
        return [$rows, $next];
    }

    private function score(array $rows, array $viewer, array $interests): array
    {
        $w = (array) $this->settings->get('feed.weights', []) + ['fresh' => 1.0, 'interest' => 0.8, 'geo' => 0.3, 'popular' => 0.2, 'verified' => 0.3];
        $tau = max(1, (int) $this->settings->get('feed.freshness_hours', 72));
        $interestSet = array_flip(array_map('intval', $interests));
        $maxViews = max(1, ...array_map(static fn ($r) => (int) $r['views_7d'], $rows ?: [['views_7d' => 1]]));
        $now = time();
        foreach ($rows as &$r) {
            $ageH = max(0, ($now - strtotime((string) $r['published_at'] . ' UTC')) / 3600);
            $geo = (int) $r['country_id'] === (int) $viewer['country_id'] ? 1.0 : ((int) $r['language_id'] === (int) $viewer['language_id'] ? 0.5 : 0.0);
            $r['_score'] = $w['fresh'] * exp(-$ageH / $tau)
                + $w['interest'] * (isset($interestSet[(int) $r['category_id']]) ? 1 : 0)
                + $w['geo'] * $geo
                + $w['popular'] * (log(1 + (int) $r['views_7d']) / log(1 + $maxViews))
                + $w['verified'] * (int) $r['verified']
                - ((int) $r['user_id'] === (int) $viewer['id'] ? 0.5 : 0);
        }
        unset($r);
        usort($rows, static fn ($a, $b) => $b['_score'] <=> $a['_score']);
        return $rows;
    }

    /** No two cards of the same owner in a row; at most two of the same category in a row. */
    private function diversify(array $rows): array
    {
        $out = [];
        while ($rows !== []) {
            $pick = 0;
            $n = count($out);
            foreach ($rows as $i => $r) {
                $sameOwner = $n > 0 && $out[$n - 1]['user_id'] === $r['user_id'];
                $sameCat = $n > 1 && $out[$n - 1]['category_id'] === $r['category_id'] && $out[$n - 2]['category_id'] === $r['category_id'];
                if (!$sameOwner && !$sameCat) {
                    $pick = $i;
                    break;
                }
            }
            $out[] = $rows[$pick];
            array_splice($rows, $pick, 1);
        }
        return $out;
    }

    private function hydrate(array $r): array
    {
        $card = json_decode((string) $r['card'], true) ?: [];
        $card['id'] = (int) $r['proposal_id'];
        $card['published_at'] = (string) $r['published_at'];
        return $card;
    }

    private static function encodeCursor(string $publishedAt, int $id): string
    {
        return rtrim(strtr(base64_encode($publishedAt . '|' . $id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int}|null */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 80) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)\|(\d{1,20})$/', $raw, $m)) {
            return null;
        }
        return [$m[1], (int) $m[2]];
    }
}
