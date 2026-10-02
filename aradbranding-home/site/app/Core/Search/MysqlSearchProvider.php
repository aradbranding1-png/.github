<?php

declare(strict_types=1);

namespace App\Core\Search;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Support\Str;

/**
 * MySQL Full-Text implementation. Relevance-ordered results are capped at 5 pages of 20
 * (a small, bounded OFFSET on an index-driven result set — never a deep scan).
 * Identical popular queries are cached for 60 seconds.
 */
final class MysqlSearchProvider implements SearchProvider
{
    public const PER_PAGE = 20;
    public const MAX_PAGES = 5;

    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    public function search(string $type, string $query, array $filters, int $page): array
    {
        $q = Str::normalize($query);
        $page = max(1, min(self::MAX_PAGES, $page));
        $country = (int) ($filters['country'] ?? 0);
        $category = (int) ($filters['category'] ?? 0);
        $product = mb_substr(Str::normalize((string) ($filters['product'] ?? '')), 0, 60);
        $terms = $this->booleanTerms($q);
        if ($terms === '' && $country === 0 && $category === 0 && mb_strlen($product) < 2) {
            return ['rows' => [], 'more' => false];
        }
        $key = 'search:' . $type . ':' . md5($q . '|' . $country . '|' . $category . '|' . $product . '|' . $page);
        return $this->cache->remember($key, 60, fn (): array => $this->run($type, $q, $terms, $country, $page, $category, $product));
    }

    private function run(string $type, string $q, string $terms, int $country, int $page, int $category = 0, string $product = ''): array
    {
        // Product / goods filter (e.g. «زعفران»): matched in proposals (title, summary, product, tags), page text and
        // product lists, and a trader's business area or live proposals/pages. Category: proposal category or a
        // trader's chosen interests.
        $like = mb_strlen($product) >= 2 ? '%' . addcslashes($product, '%_\\') . '%' : null;
        $offset = ($page - 1) * self::PER_PAGE;
        $limit = self::PER_PAGE + 1;
        $bind = [];
        $where = [];

        switch ($type) {
            case 'traders':
                $where[] = 'u.status IN (1, 2) AND u.deleted_at IS NULL AND u.handle IS NOT NULL';
                $score = '0';
                if ($terms !== '') {
                    $where[] = '(MATCH(u.search_name) AGAINST (? IN BOOLEAN MODE) OR u.handle LIKE ?)';
                    $score = 'MATCH(u.search_name) AGAINST (? IN BOOLEAN MODE)';
                    array_push($bind, $terms, preg_replace('/[^a-z0-9-]/', '', $q) . '%');
                }
                if ($country > 0) {
                    $where[] = 'u.country_id = ?';
                    $bind[] = $country;
                }
                if ($like !== null) {
                    $where[] = '(p.business_area LIKE ? OR p.company_name LIKE ?
                        OR EXISTS (SELECT 1 FROM proposals x WHERE x.user_id = u.id AND x.status = 2 AND x.deleted_at IS NULL
                                   AND (x.product LIKE ? OR x.title LIKE ? OR x.tags LIKE ?))
                        OR EXISTS (SELECT 1 FROM pages y WHERE y.user_id = u.id AND y.status = 2 AND y.deleted_at IS NULL
                                   AND (y.title LIKE ? OR y.teaser LIKE ? OR y.content LIKE ?)))';
                    array_push($bind, $like, $like, $like, $like, $like, $like, $like, $like);
                }
                if ($category > 0) {
                    $where[] = '(EXISTS (SELECT 1 FROM user_interests ui WHERE ui.user_id = u.id AND ui.category_id = ?)
                        OR EXISTS (SELECT 1 FROM proposals x WHERE x.user_id = u.id AND x.status = 2 AND x.deleted_at IS NULL AND x.category_id = ?))';
                    array_push($bind, $category, $category);
                }
                $sql = "SELECT u.id, u.first_name, u.last_name, u.handle, u.avatar_path, u.business_verified_at, c.code AS country_code, p.company_name, p.business_area,
                               {$score} AS score
                        FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN user_profiles p ON p.user_id = u.id
                        WHERE " . implode(' AND ', $where) . " ORDER BY score DESC, u.id DESC LIMIT {$limit} OFFSET {$offset}";
                if ($terms !== '') {
                    array_unshift($bind, $terms); // score placeholder comes first in the SELECT list
                }
                break;

            case 'pages':
                $where[] = 'pg.status = 2 AND pg.deleted_at IS NULL AND u.status IN (1, 2) AND u.handle IS NOT NULL';
                $score = '0';
                if ($terms !== '') {
                    $where[] = 'MATCH(pg.title, pg.company_name, pg.teaser) AGAINST (? IN BOOLEAN MODE)';
                    $score = 'MATCH(pg.title, pg.company_name, pg.teaser) AGAINST (? IN BOOLEAN MODE)';
                    $bind[] = $terms;
                }
                if ($country > 0) {
                    $where[] = 'pg.country_id = ?';
                    $bind[] = $country;
                }
                if ($like !== null) {
                    $where[] = '(pg.title LIKE ? OR pg.company_name LIKE ? OR pg.teaser LIKE ? OR pg.content LIKE ?)';
                    array_push($bind, $like, $like, $like, $like);
                }
                if ($category > 0) {
                    $where[] = '(EXISTS (SELECT 1 FROM user_interests ui WHERE ui.user_id = pg.user_id AND ui.category_id = ?)
                        OR EXISTS (SELECT 1 FROM proposals x WHERE x.user_id = pg.user_id AND x.status = 2 AND x.deleted_at IS NULL AND x.category_id = ?))';
                    array_push($bind, $category, $category);
                }
                $sql = "SELECT pg.id, pg.title, pg.company_name, pg.teaser, pg.avatar_path, l.code AS lang, u.handle, c.code AS country_code, {$score} AS score
                        FROM pages pg JOIN users u ON u.id = pg.user_id JOIN languages l ON l.id = pg.language_id JOIN countries c ON c.id = pg.country_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY score DESC, pg.id DESC LIMIT {$limit} OFFSET {$offset}";
                if ($terms !== '') {
                    array_unshift($bind, $terms);
                }
                break;

            default: // proposals: only live ones (joined to the feed read model)
                $where[] = 'p.status = 2 AND p.deleted_at IS NULL';
                $score = '0';
                if ($terms !== '') {
                    $where[] = 'MATCH(p.title, p.summary) AGAINST (? IN BOOLEAN MODE)';
                    $score = 'MATCH(p.title, p.summary) AGAINST (? IN BOOLEAN MODE)';
                    $bind[] = $terms;
                }
                if ($country > 0) {
                    $where[] = 'f.country_id = ?';
                    $bind[] = $country;
                }
                if ($like !== null) {
                    $where[] = '(p.title LIKE ? OR p.summary LIKE ? OR p.product LIKE ? OR p.tags LIKE ?)';
                    array_push($bind, $like, $like, $like, $like);
                }
                if ($category > 0) {
                    $where[] = 'p.category_id = ?';
                    $bind[] = $category;
                }
                $sql = "SELECT f.proposal_id, f.published_at, f.card, {$score} AS score
                        FROM proposals p JOIN proposal_feed f ON f.proposal_id = p.id
                        WHERE " . implode(' AND ', $where) . " ORDER BY score DESC, f.published_at DESC LIMIT {$limit} OFFSET {$offset}";
                if ($terms !== '') {
                    array_unshift($bind, $terms);
                }
        }

        $rows = $this->db->select($sql, $bind);
        $more = count($rows) > self::PER_PAGE && ($offset + self::PER_PAGE) < self::PER_PAGE * self::MAX_PAGES;
        return ['rows' => array_slice($rows, 0, self::PER_PAGE), 'more' => $more];
    }

    /** "پسته صادراتی" → "+پسته* +صادراتی*" (short words skipped: below the full-text token size). */
    private function booleanTerms(string $q): string
    {
        $words = preg_split('/\s+/u', (string) preg_replace('/[+\-<>()~*"@]+/u', ' ', $q)) ?: [];
        $out = [];
        foreach (array_slice($words, 0, 6) as $w) {
            if (mb_strlen($w) >= 2) {
                $out[] = '+' . $w . '*';
            }
        }
        return implode(' ', $out);
    }
}
