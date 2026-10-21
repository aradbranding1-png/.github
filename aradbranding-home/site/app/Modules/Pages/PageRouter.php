<?php

declare(strict_types=1);

namespace App\Modules\Pages;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/**
 * Decides which of an owner's pages a visitor sees. Order (architecture doc):
 *   1 exact country+language rule, 2 language rule, 2b published page in the visitor's language,
 *   3 country rule, 4 default page (rule with both NULL). The system never picks a language itself.
 * The owner's whole route set (published pages + rules) is one cached read.
 */
final class PageRouter
{
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 2;

    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    /** @return array{id: int, status: int}|null */
    public function ownerByHandle(string $handle): ?array
    {
        $handle = strtolower($handle);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $handle)) {
            return null;
        }
        $row = $this->cache->remember('handle:' . $handle, 3600, function () use ($handle): array {
            $r = $this->db->first('SELECT id, status FROM users WHERE handle = ? AND deleted_at IS NULL', [$handle]);
            return $r === null ? ['id' => 0, 'status' => 0] : ['id' => (int) $r['id'], 'status' => (int) $r['status']];
        });
        return $row['id'] === 0 ? null : $row;
    }

    /**
     * @return array{pages: array<int, array{id: int, language_id: int, code: string, version: int}>,
     *               rules: list<array{country_id: ?int, language_id: ?int, target: int}>, owner_version: int}
     */
    public function routeSet(int $ownerId): array
    {
        $ownerVersion = $this->cache->version('owner:' . $ownerId);
        return $this->cache->remember("routes:{$ownerId}:v{$ownerVersion}", 3600, function () use ($ownerId, $ownerVersion): array {
            $pages = [];
            foreach ($this->db->select(
                'SELECT p.id, p.language_id, p.version, l.code
                 FROM pages p JOIN languages l ON l.id = p.language_id
                 WHERE p.user_id = ? AND p.status = ? AND p.deleted_at IS NULL
                 ORDER BY l.sort',
                [$ownerId, self::STATUS_PUBLISHED]
            ) as $p) {
                $pages[(int) $p['id']] = [
                    'id' => (int) $p['id'],
                    'language_id' => (int) $p['language_id'],
                    'code' => (string) $p['code'],
                    'version' => (int) $p['version'],
                ];
            }
            $rules = [];
            foreach ($this->db->select(
                'SELECT country_id, language_id, target_page_id FROM page_routes
                 WHERE owner_user_id = ? AND active = 1 ORDER BY priority DESC',
                [$ownerId]
            ) as $r) {
                $rules[] = [
                    'country_id' => $r['country_id'] === null ? null : (int) $r['country_id'],
                    'language_id' => $r['language_id'] === null ? null : (int) $r['language_id'],
                    'target' => (int) $r['target_page_id'],
                ];
            }
            return ['pages' => $pages, 'rules' => $rules, 'owner_version' => $ownerVersion];
        });
    }

    public function resolve(int $ownerId, ?int $countryId, ?int $languageId): ?int
    {
        $set = $this->routeSet($ownerId);
        $rule = static function (?int $c, ?int $l) use ($set): ?int {
            foreach ($set['rules'] as $r) {
                if ($r['country_id'] === $c && $r['language_id'] === $l && isset($set['pages'][$r['target']])) {
                    return $r['target'];
                }
            }
            return null;
        };

        if ($countryId !== null && $languageId !== null && ($id = $rule($countryId, $languageId)) !== null) {
            return $id;
        }
        if ($languageId !== null) {
            if (($id = $rule(null, $languageId)) !== null) {
                return $id;
            }
            foreach ($set['pages'] as $page) {
                if ($page['language_id'] === $languageId) {
                    return $page['id'];
                }
            }
        }
        if ($countryId !== null && ($id = $rule($countryId, null)) !== null) {
            return $id;
        }
        return $rule(null, null);
    }

    public function pageInLanguage(int $ownerId, string $code): ?int
    {
        foreach ($this->routeSet($ownerId)['pages'] as $page) {
            if ($page['code'] === $code) {
                return $page['id'];
            }
        }
        return null;
    }
}
