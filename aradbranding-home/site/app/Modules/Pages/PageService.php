<?php

declare(strict_types=1);

namespace App\Modules\Pages;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Storage\ImageUploader;
use App\Core\Storage\UploadException;
use App\Core\Support\Ulid;
use App\Modules\Users\ValidationFailed;
use PDOException;

/** Owner-side page management. Every write bumps the owner's cache namespace after commit. */
final class PageService
{
    public const RESERVED_HANDLES = [
        'admin', 'api', 'app', 'login', 'logout', 'register', 'account', 'dashboard', 'pages', 'page', 'p',
        'install', 'health', 'ops', 'static', 'media', 'assets', 'support', 'help', 'about', 'terms', 'privacy',
        'search', 'discover', 'proposals', 'letters', 'wallet', 'stars', 'settings', 'root', 'system', 'www',
    ];

    public function __construct(private Connection $db, private Cache $cache, private ImageUploader $images)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForOwner(int $userId): array
    {
        $default = $this->db->scalar(
            'SELECT target_page_id FROM page_routes WHERE owner_user_id = ? AND country_id IS NULL AND language_id IS NULL',
            [$userId]
        );
        $rows = $this->db->select(
            'SELECT p.id, p.public_id, p.language_id, p.status, p.title, p.teaser, p.avatar_path, p.updated_at,
                    l.code AS lang_code, l.name AS lang_name, l.name_fa AS lang_name_fa, l.direction
             FROM pages p JOIN languages l ON l.id = p.language_id
             WHERE p.user_id = ? AND p.deleted_at IS NULL
             ORDER BY l.sort',
            [$userId]
        );
        foreach ($rows as &$row) {
            $row['uid'] = strtolower(Ulid::toString($row['public_id']));
            $row['is_default'] = (int) $row['id'] === (int) $default;
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function rules(int $userId): array
    {
        return $this->db->select(
            'SELECT r.id, r.country_id, r.language_id, r.target_page_id, l.code AS target_lang
             FROM page_routes r JOIN pages p ON p.id = r.target_page_id JOIN languages l ON l.id = p.language_id
             WHERE r.owner_user_id = ? AND NOT (r.country_id IS NULL AND r.language_id IS NULL)
             ORDER BY r.country_id IS NULL, r.language_id IS NULL, r.id',
            [$userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findOwned(string $uid, int $userId): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        $row = $this->db->first(
            'SELECT p.*, l.code AS lang_code, l.name AS lang_name, l.direction
             FROM pages p JOIN languages l ON l.id = p.language_id
             WHERE p.public_id = ? AND p.user_id = ? AND p.deleted_at IS NULL',
            [Ulid::toBinary($uid), $userId]
        );
        if ($row !== null) {
            $row['uid'] = strtolower($uid);
            $row['content'] = json_decode((string) ($row['content'] ?? '{}'), true) ?: [];
        }
        return $row;
    }

    public function setHandle(int $userId, string $handle): void
    {
        $handle = strtolower(trim($handle));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $handle) || str_contains($handle, '--')) {
            throw new ValidationFailed(['handle' => 'نشانی باید ۳ تا ۳۲ نویسه و فقط شامل حروف کوچک انگلیسی، عدد و خط تیره باشد.']);
        }
        if (in_array($handle, self::RESERVED_HANDLES, true)) {
            throw new ValidationFailed(['handle' => 'این نشانی رزرو شده است. نشانی دیگری انتخاب کنید.']);
        }
        try {
            $changed = $this->db->exec('UPDATE users SET handle = ?, updated_at = NOW(3) WHERE id = ? AND handle IS NULL', [$handle, $userId]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationFailed(['handle' => 'این نشانی قبلاً گرفته شده است.']);
            }
            throw $e;
        }
        if ($changed === 0) {
            $current = $this->db->scalar('SELECT handle FROM users WHERE id = ?', [$userId]);
            if ($current === $handle) {
                return; // same handle already saved by an earlier attempt of this form
            }
            throw new ValidationFailed(['handle' => 'نشانی صفحه شما قبلاً تعیین شده است.']);
        }
        $this->cache->forget('handle:' . $handle);
    }

    /**
     * @param array<string, mixed> $d validated fields
     * @param array<string, array|null> $files cover/avatar
     * @throws ValidationFailed
     */
    public function create(array $owner, array $d, array $files): string
    {
        [$cover, $avatar] = $this->storeImages($files);
        $publicId = Ulid::generateBinary();
        try {
            $this->db->transaction(function (Connection $db) use ($owner, $d, $cover, $avatar, $publicId): void {
                $status = $d['publish'] ? PageRouter::STATUS_PUBLISHED : PageRouter::STATUS_DRAFT;
                $pageId = $db->insert(
                    'INSERT INTO pages (public_id, user_id, language_id, country_id, status, title, company_name, teaser,
                                        about, content, cover_path, avatar_path, version, published_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(3), NOW(3))',
                    [$publicId, $owner['id'], $d['language_id'], $owner['country_id'], $status, $d['title'],
                        $d['company_name'], $d['teaser'], $d['about'], self::encodeContent($d), $cover, $avatar,
                        $status === PageRouter::STATUS_PUBLISHED ? gmdate('Y-m-d H:i:s') : null]
                );
                // The first page becomes the default page; the owner can change it any time.
                $db->exec(
                    'INSERT IGNORE INTO page_routes (owner_user_id, country_id, language_id, target_page_id, created_at, updated_at)
                     VALUES (?, NULL, NULL, ?, NOW(3), NOW(3))',
                    [$owner['id'], $pageId]
                );
            });
        } catch (PDOException $e) {
            $this->images->delete($cover);
            $this->images->delete($avatar);
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationFailed(['language_id' => 'برای این زبان قبلاً صفحه ساخته‌اید. همان صفحه را ویرایش کنید.']);
            }
            throw $e;
        }
        $this->cache->bump('owner:' . $owner['id']);
        return strtolower(Ulid::toString($publicId));
    }

    /**
     * @param array<string, mixed> $page row from findOwned()
     * @param array<string, mixed> $d
     * @param array<string, array|null> $files
     */
    public function update(array $page, array $d, array $files): void
    {
        [$cover, $avatar] = $this->storeImages($files);
        $newCover = $cover ?? ($d['remove_cover'] ? null : $page['cover_path']);
        $newAvatar = $avatar ?? ($d['remove_avatar'] ? null : $page['avatar_path']);
        $status = $d['publish'] ? PageRouter::STATUS_PUBLISHED : PageRouter::STATUS_DRAFT;

        $this->db->exec(
            'UPDATE pages SET title = ?, company_name = ?, teaser = ?, about = ?, content = ?, cover_path = ?, avatar_path = ?,
                    status = ?, published_at = COALESCE(published_at, IF(? = 2, NOW(3), NULL)),
                    version = version + 1, updated_at = NOW(3)
             WHERE id = ?',
            [$d['title'], $d['company_name'], $d['teaser'], $d['about'], self::encodeContent($d), $newCover, $newAvatar,
                $status, $status, $page['id']]
        );
        if ($newCover !== $page['cover_path']) {
            $this->images->delete($page['cover_path']);
        }
        if ($newAvatar !== $page['avatar_path']) {
            $this->images->delete($page['avatar_path']);
        }
        $this->cache->bump('owner:' . $page['user_id']);
    }

    /** On/off switch of the owner: published (2) or draft (0). Moderated states are left to the admin. */
    public function setPublished(array $page, bool $on): void
    {
        $status = $on ? PageRouter::STATUS_PUBLISHED : PageRouter::STATUS_DRAFT;
        $this->db->exec(
            'UPDATE pages SET status = ?, published_at = COALESCE(published_at, IF(? = 2, NOW(3), NULL)), version = version + 1, updated_at = NOW(3)
             WHERE id = ? AND deleted_at IS NULL AND status IN (0, 2)',
            [$status, $status, $page['id']]
        );
        $this->cache->bump('owner:' . $page['user_id']);
    }

    /** Soft delete (administrators only). Rules pointing at the page go; a new default is chosen if needed. */
    public function delete(array $page): void
    {
        $ownerId = (int) $page['user_id'];
        $this->db->transaction(function (Connection $db) use ($page, $ownerId): void {
            $db->exec('DELETE FROM page_routes WHERE target_page_id = ?', [$page['id']]);
            $db->exec('UPDATE pages SET deleted_at = NOW(3), status = 3, version = version + 1 WHERE id = ?', [$page['id']]);
            $hasDefault = $db->scalar(
                'SELECT 1 FROM page_routes WHERE owner_user_id = ? AND country_id IS NULL AND language_id IS NULL',
                [$ownerId]
            );
            if ($hasDefault === null) {
                $next = $db->scalar(
                    'SELECT id FROM pages WHERE user_id = ? AND deleted_at IS NULL ORDER BY status = 2 DESC, id LIMIT 1',
                    [$ownerId]
                );
                if ($next !== null) {
                    $db->exec(
                        'INSERT INTO page_routes (owner_user_id, country_id, language_id, target_page_id, created_at, updated_at)
                         VALUES (?, NULL, NULL, ?, NOW(3), NOW(3))',
                        [$ownerId, $next]
                    );
                }
            }
        });
        $this->cache->bump('owner:' . $ownerId);
    }

    public function setDefault(array $page): void
    {
        $this->upsertRule((int) $page['user_id'], null, null, (int) $page['id']);
    }

    public function addRule(int $ownerId, ?int $countryId, ?int $languageId, int $targetPageId): void
    {
        if ($countryId === null && $languageId === null) {
            throw new ValidationFailed(['rule' => 'حداقل کشور یا زبان را انتخاب کنید.']);
        }
        $this->upsertRule($ownerId, $countryId, $languageId, $targetPageId);
    }

    public function deleteRule(int $ownerId, int $ruleId): void
    {
        $this->db->exec(
            'DELETE FROM page_routes WHERE id = ? AND owner_user_id = ? AND NOT (country_id IS NULL AND language_id IS NULL)',
            [$ruleId, $ownerId]
        );
        $this->cache->bump('owner:' . $ownerId);
    }

    private function upsertRule(int $ownerId, ?int $countryId, ?int $languageId, int $targetPageId): void
    {
        $this->db->exec(
            'INSERT INTO page_routes (owner_user_id, country_id, language_id, target_page_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(3), NOW(3))
             ON DUPLICATE KEY UPDATE target_page_id = ?, active = 1, updated_at = NOW(3)',
            [$ownerId, $countryId, $languageId, $targetPageId, $targetPageId]
        );
        $this->cache->bump('owner:' . $ownerId);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function storeImages(array $files): array
    {
        $cover = null;
        $avatar = null;
        try {
            if (!empty($files['cover'])) {
                $cover = $this->images->store($files['cover'], 'cover');
            }
            if (!empty($files['avatar'])) {
                $avatar = $this->images->store($files['avatar'], 'avatar');
            }
        } catch (UploadException $e) {
            $this->images->delete($cover);
            throw new ValidationFailed([$cover === null && !empty($files['cover']) ? 'cover' : 'avatar' => $e->getMessage()]);
        }
        return [$cover, $avatar];
    }

    /** Structured page content. Lists are one item per line in the editor. */
    private static function encodeContent(array $d): string
    {
        $lines = static fn (?string $text): array => array_values(array_filter(
            array_map(static fn (string $l): string => mb_substr(trim($l), 0, 200), preg_split('/\R/u', (string) $text) ?: []),
            static fn (string $l): bool => $l !== ''
        ));
        return json_encode([
            'products' => array_slice($lines($d['products'] ?? ''), 0, 50),
            'services' => array_slice($lines($d['services'] ?? ''), 0, 50),
            'markets' => array_slice($lines($d['markets'] ?? ''), 0, 50),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
