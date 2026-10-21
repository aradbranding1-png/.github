<?php

declare(strict_types=1);

namespace App\Modules\Reference;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/** Countries and languages: tiny, hot, shared → cached 24h, bumped by admin edits ("ref" namespace). */
final class ReferenceData
{
    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    /** @return array<int, array{id: int, code: string, calling_code: string, name_fa: string, name_en: string}> keyed by id */
    public function countries(): array
    {
        return $this->cache->remember('ref:countries:v' . $this->cache->version('ref'), 86400, function (): array {
            $rows = $this->db->select(
                'SELECT id, code, calling_code, name_fa, name_en FROM countries WHERE active = 1 ORDER BY sort, name_fa'
            );
            $out = [];
            foreach ($rows as $r) {
                $r['id'] = (int) $r['id'];
                $out[$r['id']] = $r;
            }
            return $out;
        });
    }

    /** @return array<int, array{id: int, code: string, name: string, name_fa: string, direction: string}> keyed by id */
    public function languages(): array
    {
        return $this->cache->remember('ref:languages:v' . $this->cache->version('ref'), 86400, function (): array {
            $rows = $this->db->select(
                'SELECT id, code, name, name_fa, direction FROM languages WHERE active = 1 ORDER BY sort, name_fa'
            );
            $out = [];
            foreach ($rows as $r) {
                $r['id'] = (int) $r['id'];
                $out[$r['id']] = $r;
            }
            return $out;
        });
    }

    public function country(?int $id): ?array
    {
        return $id === null ? null : ($this->countries()[$id] ?? null);
    }

    public function language(?int $id): ?array
    {
        return $id === null ? null : ($this->languages()[$id] ?? null);
    }

    public function languageByCode(string $code): ?array
    {
        foreach ($this->languages() as $lang) {
            if ($lang['code'] === $code) {
                return $lang;
            }
        }
        return null;
    }

    public function countryByCode(string $code): ?array
    {
        foreach ($this->countries() as $country) {
            if ($country['code'] === strtoupper($code)) {
                return $country;
            }
        }
        return null;
    }

    /** @return array<int, array{id: int, type: string, slug: string, name_fa: string, name_en: string}> keyed by id */
    public function categories(): array
    {
        return $this->cache->remember('ref:categories:v' . $this->cache->version('ref'), 86400, function (): array {
            $out = [];
            foreach ($this->db->select('SELECT id, type, slug, name_fa, name_en FROM categories WHERE active = 1 ORDER BY sort, name_fa') as $r) {
                $r['id'] = (int) $r['id'];
                $out[$r['id']] = $r;
            }
            return $out;
        });
    }

    public function category(?int $id): ?array
    {
        return $id === null ? null : ($this->categories()[$id] ?? null);
    }
}
