<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Auth\Password;
use App\Core\Db\Connection;
use App\Core\Support\Ulid;

/**
 * Load-test data (doc §106): users with profiles, wallets, one or two pages each, and proposals
 * in the feed — written with 1,000-row multi-inserts. For staging servers only.
 * Demo accounts: demoN@example.test / password "demo-password".
 */
final class DemoSeeder
{
    private const WORDS = ['زعفران صادراتی', 'پسته اکبری', 'فرش دستباف', 'میلگرد', 'سیمان', 'خرما', 'کاشی و سرامیک', 'قطعات خودرو',
        'پلی‌اتیلن', 'لوازم خانگی', 'حمل دریایی', 'ترخیص کالا', 'دارو', 'چای', 'برنج', 'سنگ گرانیت'];

    public function __construct(private Connection $db)
    {
    }

    public function run(int $users, callable $log): void
    {
        @set_time_limit(0);
        $countries = array_map('intval', array_column($this->db->select('SELECT id FROM countries WHERE active = 1 ORDER BY sort LIMIT 30'), 'id'));
        $languages = array_map('intval', array_column($this->db->select('SELECT id FROM languages WHERE active = 1 ORDER BY sort LIMIT 6'), 'id'));
        $categories = array_map('intval', array_column($this->db->select('SELECT id FROM categories WHERE active = 1'), 'id'));
        $customer = (int) $this->db->scalar("SELECT id FROM roles WHERE slug = 'customer'");
        $hash = Password::hash('demo-password');
        $start = (int) ($this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM users') ?? 0) + 1;

        for ($done = 0; $done < $users; $done += 1000) {
            $n = min(1000, $users - $done);
            $rows = [];
            for ($i = 0; $i < $n; $i++) {
                $k = $start + $done + $i;
                $rows[] = [Ulid::generateBinary(), 'demo' . $k, 'تاجر', 'نمونه ' . $k, 'تاجر نمونه ' . $k, "demo{$k}@example.test", '98',
                    (string) (9000000000 + $k), $hash, $countries[$k % count($countries)], $languages[$k % count($languages)]];
            }
            $this->db->exec(
                'INSERT INTO users (public_id, handle, first_name, last_name, search_name, email, phone_cc, phone, password_hash, country_id, language_id,
                                    status, created_at, updated_at, last_active_at) VALUES '
                    . implode(',', array_fill(0, $n, '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(3) - INTERVAL FLOOR(RAND() * 365) DAY, NOW(3), NOW(3) - INTERVAL FLOOR(RAND() * 40) DAY)')),
                array_merge(...$rows)
            );
            $ids = array_map('intval', array_column($this->db->select('SELECT id, country_id, language_id FROM users WHERE handle IN ('
                . implode(',', array_fill(0, $n, '?')) . ')', array_column($rows, 1)), 'id'));
            $marks = implode(',', $ids);
            $this->db->exec("INSERT IGNORE INTO user_profiles (user_id, accept_public_letters, updated_at) SELECT id, 1, NOW(3) FROM users WHERE id IN ({$marks})");
            $this->db->exec("INSERT IGNORE INTO user_counters (user_id, updated_at) SELECT id, NOW(3) FROM users WHERE id IN ({$marks})");
            $this->db->exec("INSERT IGNORE INTO wallets (user_id, balance, updated_at) SELECT id, 500, NOW(3) FROM users WHERE id IN ({$marks})");
            $this->db->exec("INSERT IGNORE INTO user_roles (user_id, role_id, created_at) SELECT id, {$customer}, NOW(3) FROM users WHERE id IN ({$marks})");

            // One page per user (some get a second language), published.
            $pages = [];
            foreach ($this->db->select("SELECT id, country_id, language_id FROM users WHERE id IN ({$marks})") as $u) {
                $w = self::WORDS[$u['id'] % count(self::WORDS)];
                $pages[] = [Ulid::generateBinary(), $u['id'], $u['language_id'], $u['country_id'], 'بازرگانی ' . $w, 'تولید و صادرات ' . $w . ' با کیفیت صادراتی.'];
                if ($u['id'] % 4 === 0) {
                    $pages[] = [Ulid::generateBinary(), $u['id'], $languages[($u['id'] + 1) % count($languages)] === (int) $u['language_id'] ? $languages[($u['id'] + 2) % count($languages)] : $languages[($u['id'] + 1) % count($languages)],
                        $u['country_id'], 'Trading ' . $u['id'], 'Exporter of quality goods.'];
                }
            }
            foreach (array_chunk($pages, 1000) as $chunk) {
                $this->db->exec(
                    'INSERT IGNORE INTO pages (public_id, user_id, language_id, country_id, status, title, teaser, version, published_at, created_at, updated_at) VALUES '
                        . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, 2, ?, ?, 1, NOW(3), NOW(3), NOW(3))')),
                    array_merge(...$chunk)
                );
            }
            $this->db->exec(
                "INSERT IGNORE INTO page_routes (owner_user_id, country_id, language_id, target_page_id, created_at, updated_at)
                 SELECT p.user_id, NULL, NULL, MIN(p.id), NOW(3), NOW(3) FROM pages p WHERE p.user_id IN ({$marks}) GROUP BY p.user_id"
            );

            // Two proposals for every fifth user, published into the feed.
            $props = [];
            foreach ($ids as $uid) {
                if ($uid % 5 !== 0) {
                    continue;
                }
                for ($j = 0; $j < 2; $j++) {
                    $w = self::WORDS[($uid + $j) % count(self::WORDS)];
                    $props[] = [Ulid::generateBinary(), $uid, 1 + ($uid + $j) % 6, $categories[($uid + $j) % count($categories)],
                        'عرضه ' . $w, 'عرضه مستقیم ' . $w . ' برای بازارهای منطقه.'];
                }
            }
            foreach (array_chunk($props, 1000) as $chunk) {
                $this->db->exec(
                    'INSERT INTO proposals (public_id, user_id, type, category_id, country_id, language_id, status, title, summary, tags,
                                            published_at, expires_at, created_at, updated_at)
                     SELECT v.pid, v.uid, v.t, v.c, u.country_id, u.language_id, 2, v.title, v.summary, JSON_ARRAY(),
                            NOW(3) - INTERVAL FLOOR(RAND() * 60) DAY, NOW(3) + INTERVAL 90 DAY, NOW(3), NOW(3)
                     FROM (' . implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS pid, ? AS uid, ? AS t, ? AS c, ? AS title, ? AS summary')) . ') v
                     JOIN users u ON u.id = v.uid',
                    array_merge(...$chunk)
                );
            }
            $feed = [];
            foreach ($this->db->select(
                "SELECT p.id, p.public_id, p.user_id, p.published_at, p.category_id, p.country_id, p.language_id, p.type, p.title,
                        u.first_name, u.last_name, u.handle, c.code
                 FROM proposals p JOIN users u ON u.id = p.user_id JOIN countries c ON c.id = p.country_id
                 WHERE p.user_id IN ({$marks}) AND p.status = 2"
            ) as $p) {
                $card = json_encode(['uid' => strtolower(Ulid::toString($p['public_id'])), 'title' => $p['title'], 'type' => (int) $p['type'],
                    'thumb' => null, 'owner' => $p['first_name'] . ' ' . $p['last_name'], 'handle' => $p['handle'], 'country' => $p['code'], 'tags' => []],
                    JSON_UNESCAPED_UNICODE);
                $feed[] = [$p['id'], $p['user_id'], $p['published_at'], $p['category_id'], $p['country_id'], $p['language_id'], $p['type'], $card];
            }
            foreach (array_chunk($feed, 1000) as $chunk) {
                $this->db->exec(
                    'INSERT IGNORE INTO proposal_feed (proposal_id, user_id, published_at, category_id, country_id, language_id, type, card) VALUES '
                        . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')),
                    array_merge(...$chunk)
                );
            }
            $log(sprintf('%d / %d users', $done + $n, $users));
        }
        $log('Done. Demo login: demo' . $start . '@example.test / demo-password');
    }
}
