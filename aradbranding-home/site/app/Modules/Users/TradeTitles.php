<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;

/**
 * Official Arad Branding titles held by a member for a country (and for one title, a product):
 * «مدیر تجارت عراق آراد برندینگ», «سرپرست تجارت پسته عراق آراد برندینگ», ...
 *
 * A title is both a public badge (business page, admin) and access: each title kind has its own role
 * (roles.slug = title_*) whose permissions are team-scoped, and the title's country is added to the
 * member's staff_assignments — so a representative for Iraq sees and manages only Iraq in the admin panel.
 * Rows live in user_titles; sync() keeps user_roles / staff_assignments in line with them.
 */
final class TradeTitles
{
    /**
     * key => [role slug, [fa, en, ar] text with {country} / {product}, needs product, permissions (team scope)].
     * The permissions are the initial grants; they can be changed later in «نقش‌ها و دسترسی‌ها».
     */
    public const KINDS = [
        'exclusive_manager' => ['title_exclusive_manager', [
            'fa' => 'مدیر انحصاری تجارت {country} آراد برندینگ',
            'en' => 'Arad Branding Exclusive Trade Manager, {country}',
            'ar' => 'المدير الحصري لتجارة {country} في آراد براندينغ',
        ], false, ['users.view', 'users.edit', 'pages.view', 'pages.approve', 'proposals.view', 'proposals.moderate']],
        'trade_manager' => ['title_trade_manager', [
            'fa' => 'مدیر تجارت {country} آراد برندینگ',
            'en' => 'Arad Branding Trade Manager, {country}',
            'ar' => 'مدير تجارة {country} في آراد براندينغ',
        ], false, ['users.view', 'pages.view', 'pages.approve', 'proposals.view', 'proposals.moderate']],
        'product_supervisor' => ['title_product_supervisor', [
            'fa' => 'سرپرست تجارت {product} {country} آراد برندینگ',
            'en' => 'Arad Branding {product} Trade Supervisor, {country}',
            'ar' => 'مشرف تجارة {product} في {country} – آراد براندينغ',
        ], true, ['users.view', 'pages.view', 'proposals.view', 'proposals.moderate']],
        'representative' => ['title_representative', [
            'fa' => 'نماینده تجاری آراد برندینگ در {country}',
            'en' => 'Arad Branding Trade Representative in {country}',
            'ar' => 'الممثل التجاري لآراد براندينغ في {country}',
        ], false, ['users.view', 'pages.view', 'proposals.view']],
        'attache' => ['title_attache', [
            'fa' => 'رایزن تجاری آراد برندینگ در {country}',
            'en' => 'Arad Branding Commercial Attaché in {country}',
            'ar' => 'الملحق التجاري لآراد براندينغ في {country}',
        ], false, ['users.view', 'pages.view', 'proposals.view']],
    ];

    /** Only one member per country may hold these. */
    public const EXCLUSIVE = ['exclusive_manager'];

    /** Persian names of the role cards (roles page / hover hints). */
    public const ROLE_INFO = [
        'title_exclusive_manager' => ['مدیر انحصاری تجارت (کشور)', 'عنوان رسمی؛ کاربران، صفحه‌ها و پیشنهادهای کشور خودش را می‌بیند و بررسی می‌کند و کاربران آن کشور را ویرایش می‌کند.'],
        'title_trade_manager' => ['مدیر تجارت (کشور)', 'عنوان رسمی؛ کاربران کشور خودش را می‌بیند و صفحه‌ها و پیشنهادهای آن را بررسی می‌کند.'],
        'title_product_supervisor' => ['سرپرست تجارت محصول (کشور)', 'عنوان رسمی؛ کاربران و صفحه‌های کشور خودش را می‌بیند و پیشنهادهای آن را بررسی می‌کند.'],
        'title_representative' => ['نماینده تجاری (کشور)', 'عنوان رسمی؛ کاربران، صفحه‌ها و پیشنهادهای کشور خودش را می‌بیند (بدون تغییر).'],
        'title_attache' => ['رایزن تجاری (کشور)', 'عنوان رسمی؛ کاربران، صفحه‌ها و پیشنهادهای کشور خودش را می‌بیند (بدون تغییر).'],
    ];

    public function __construct(private Connection $db, private Cache $cache)
    {
    }

    /** @return list<string> */
    public static function roleSlugs(): array
    {
        return array_column(self::KINDS, 0);
    }

    /** Title text in a language ('fa', 'en', 'ar'; others fall back to English). */
    public static function text(string $kind, string $lang, string $country, string $product = ''): string
    {
        $tpl = self::KINDS[$kind][1][$lang] ?? self::KINDS[$kind][1]['en'] ?? $kind;
        return trim((string) preg_replace('/\s{2,}/u', ' ', strtr($tpl, ['{country}' => $country, '{product}' => $product])));
    }

    /** Country name for a title in a language, from a countries row (name_fa, name_en, names JSON). */
    public static function countryName(array $row, string $lang): string
    {
        if ($lang === 'fa') {
            return (string) $row['name_fa'];
        }
        $names = json_decode((string) ($row['names'] ?? ''), true);
        return is_array($names) && !empty($names[$lang]) ? (string) $names[$lang] : (string) $row['name_en'];
    }

    /**
     * Titles of a member with their text in $lang.
     * @return list<array{id: int, kind: string, country_id: int, country_code: string, product: string, text: string, created_at: string}>
     */
    public function forUser(int $userId, string $lang = 'fa'): array
    {
        try {
            $rows = $this->db->select(
                'SELECT t.id, t.title_key, t.country_id, t.product, t.created_at, c.code, c.name_fa, c.name_en, c.names
                   FROM user_titles t JOIN countries c ON c.id = t.country_id
                  WHERE t.user_id = ? ORDER BY t.id',
                [$userId]
            );
        } catch (\Throwable) {
            return []; // before the 1.19.21 migration
        }
        $out = [];
        foreach ($rows as $r) {
            if (!isset(self::KINDS[$r['title_key']])) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'], 'kind' => (string) $r['title_key'], 'country_id' => (int) $r['country_id'],
                'country_code' => (string) $r['code'], 'product' => (string) $r['product'], 'created_at' => (string) $r['created_at'],
                'text' => self::text((string) $r['title_key'], $lang, self::countryName($r, $lang), (string) $r['product']),
            ];
        }
        return $out;
    }

    /**
     * Give a title. Returns an error message (Persian) or null on success.
     */
    public function grant(int $userId, string $kind, int $countryId, string $product, ?int $actorId): ?string
    {
        if (!isset(self::KINDS[$kind])) {
            return 'عنوان معتبر نیست.';
        }
        if ($this->db->scalar('SELECT 1 FROM countries WHERE id = ?', [$countryId]) === null) {
            return 'کشور را انتخاب کنید.';
        }
        $product = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $product)), 0, 100);
        if (self::KINDS[$kind][2] && mb_strlen($product) < 2) {
            return 'برای «سرپرست تجارت محصول» نام محصول را بنویسید (مثلاً پسته، زعفران، خرما).';
        }
        if (!self::KINDS[$kind][2]) {
            $product = '';
        }
        if (in_array($kind, self::EXCLUSIVE, true)) {
            $holder = $this->db->first(
                'SELECT u.first_name, u.last_name FROM user_titles t JOIN users u ON u.id = t.user_id
                  WHERE t.title_key = ? AND t.country_id = ? AND t.user_id <> ?',
                [$kind, $countryId, $userId]
            );
            if ($holder !== null) {
                return 'این عنوان انحصاری است و برای این کشور به «' . trim($holder['first_name'] . ' ' . $holder['last_name']) . '» داده شده. اول از او بگیرید.';
            }
        }
        $exists = $this->db->scalar(
            'SELECT 1 FROM user_titles WHERE user_id = ? AND title_key = ? AND country_id = ? AND product = ?',
            [$userId, $kind, $countryId, $product]
        );
        if ($exists !== null) {
            return 'این کاربر همین عنوان را دارد.';
        }
        $this->db->transaction(function (Connection $db) use ($userId, $kind, $countryId, $product, $actorId): void {
            $db->exec(
                'INSERT INTO user_titles (user_id, title_key, country_id, product, granted_by, created_at) VALUES (?, ?, ?, ?, ?, NOW(3))',
                [$userId, $kind, $countryId, $product, $actorId]
            );
            $this->sync($userId);
        });
        $this->cache->bump('owner:' . $userId);
        return null;
    }

    /** Take a title back; its role and country go too unless another title of the member still needs them. */
    public function revoke(int $userId, int $titleId): bool
    {
        $t = $this->db->first('SELECT id, title_key, country_id FROM user_titles WHERE id = ? AND user_id = ?', [$titleId, $userId]);
        if ($t === null) {
            return false;
        }
        $this->db->transaction(function (Connection $db) use ($userId, $t): void {
            $db->exec('DELETE FROM user_titles WHERE id = ?', [$t['id']]);
            $slug = self::KINDS[$t['title_key']][0] ?? null;
            if ($slug !== null && $db->scalar('SELECT 1 FROM user_titles WHERE user_id = ? AND title_key = ? LIMIT 1', [$userId, $t['title_key']]) === null) {
                $db->exec('DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?', [$userId, $slug]);
            }
            if ($db->scalar('SELECT 1 FROM user_titles WHERE user_id = ? AND country_id = ? LIMIT 1', [$userId, $t['country_id']]) === null) {
                $db->exec('DELETE FROM staff_assignments WHERE user_id = ? AND country_id = ?', [$userId, $t['country_id']]);
            }
        });
        $this->cache->bump('owner:' . $userId);
        return true;
    }

    /** Make sure every title's role and country are in place (after saving the roles form, which rewrites both). */
    public function sync(int $userId): void
    {
        try {
            $titles = $this->db->select('SELECT DISTINCT title_key, country_id FROM user_titles WHERE user_id = ?', [$userId]);
        } catch (\Throwable) {
            return;
        }
        foreach ($titles as $t) {
            $slug = self::KINDS[$t['title_key']][0] ?? null;
            if ($slug !== null) {
                $this->db->exec(
                    'INSERT IGNORE INTO user_roles (user_id, role_id, created_at) SELECT ?, id, NOW(3) FROM roles WHERE slug = ?',
                    [$userId, $slug]
                );
            }
            $this->db->exec('INSERT IGNORE INTO staff_assignments (user_id, country_id) VALUES (?, ?)', [$userId, (int) $t['country_id']]);
        }
    }
}
