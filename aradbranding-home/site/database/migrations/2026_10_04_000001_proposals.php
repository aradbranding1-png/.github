<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Phase 3: categories, proposals, feed read model, direct sends, views, interests.
 * Publishing fee is OFF by default (settings proposal_publish.enabled = false);
 * its price lives in pricing_rules like every other price.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement("CREATE TABLE IF NOT EXISTS categories (
            id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id INT UNSIGNED NULL,
            type      ENUM('activity','product','service') NOT NULL,
            slug      VARCHAR(60) NOT NULL,
            name_fa   VARCHAR(100) NOT NULL,
            name_en   VARCHAR(100) NOT NULL,
            active    TINYINT(1) NOT NULL DEFAULT 1,
            sort      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            PRIMARY KEY (id),
            UNIQUE KEY uq_type_slug (type, slug),
            KEY ix_active (active, sort)
        ) " . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS proposals (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id      BINARY(16) NOT NULL,
            user_id        BIGINT UNSIGNED NOT NULL,
            type           TINYINT UNSIGNED NOT NULL,
            category_id    INT UNSIGNED NOT NULL,
            country_id     SMALLINT UNSIGNED NOT NULL,
            language_id    SMALLINT UNSIGNED NOT NULL,
            status         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            title          VARCHAR(200) NOT NULL,
            summary        VARCHAR(300) NOT NULL,
            body           TEXT NULL,
            product        VARCHAR(200) NULL,
            quantity       VARCHAR(100) NULL,
            target_markets VARCHAR(500) NULL,
            terms          TEXT NULL,
            tags           JSON NULL,
            cover_path     VARCHAR(255) NULL,
            thumb_path     VARCHAR(255) NULL,
            images         JSON NULL,
            view_count     INT UNSIGNED NOT NULL DEFAULT 0,
            send_count     INT UNSIGNED NOT NULL DEFAULT 0,
            fee_paid       TINYINT(1) NOT NULL DEFAULT 0,
            published_at   DATETIME(3) NULL,
            expires_at     DATETIME(3) NULL,
            created_at     DATETIME(3) NOT NULL,
            updated_at     DATETIME(3) NOT NULL,
            deleted_at     DATETIME(3) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            KEY ix_user_created (user_id, created_at, id),
            KEY ix_expiry (status, expires_at),
            FULLTEXT KEY ft_prop (title, summary),
            CONSTRAINT fk_prop_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_prop_category FOREIGN KEY (category_id) REFERENCES categories (id)
        ) ' . self::OPTS);

        // Narrow read model: only live proposals. No status column needed in feed indexes.
        $db->statement('CREATE TABLE IF NOT EXISTS proposal_feed (
            proposal_id  BIGINT UNSIGNED NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL,
            published_at DATETIME(3) NOT NULL,
            category_id  INT UNSIGNED NOT NULL,
            country_id   SMALLINT UNSIGNED NOT NULL,
            language_id  SMALLINT UNSIGNED NOT NULL,
            type         TINYINT UNSIGNED NOT NULL,
            rank_bucket  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            views_7d     INT UNSIGNED NOT NULL DEFAULT 0,
            verified     TINYINT(1) NOT NULL DEFAULT 0,
            card         JSON NOT NULL,
            PRIMARY KEY (proposal_id),
            KEY ix_feed (published_at, proposal_id),
            KEY ix_feed_cat (category_id, published_at, proposal_id),
            KEY ix_feed_country (country_id, published_at, proposal_id),
            KEY ix_feed_type (type, published_at, proposal_id),
            KEY ix_feed_lang (language_id, published_at, proposal_id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS proposal_sends (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            proposal_id  BIGINT UNSIGNED NOT NULL,
            sender_id    BIGINT UNSIGNED NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            message      VARCHAR(1000) NULL,
            cost         INT UNSIGNED NOT NULL,
            tx_id        BIGINT UNSIGNED NULL,
            read_at      DATETIME(3) NULL,
            created_at   DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_once (proposal_id, recipient_id),
            KEY ix_recipient (recipient_id, created_at, id),
            KEY ix_sender (sender_id, created_at, id),
            CONSTRAINT fk_send_prop FOREIGN KEY (proposal_id) REFERENCES proposals (id),
            CONSTRAINT fk_send_sender FOREIGN KEY (sender_id) REFERENCES users (id),
            CONSTRAINT fk_send_recipient FOREIGN KEY (recipient_id) REFERENCES users (id)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS proposal_views (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            proposal_id BIGINT UNSIGNED NOT NULL,
            viewer_id   BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (id, created_at),
            KEY ix_proposal (proposal_id, created_at)
        ) " . self::OPTS . "
        PARTITION BY RANGE COLUMNS (created_at) (
            PARTITION p_init VALUES LESS THAN ('2026-01-01'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )");

        $db->statement('CREATE TABLE IF NOT EXISTS user_interests (
            user_id     BIGINT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, category_id),
            KEY ix_category (category_id, user_id)
        ) ' . self::OPTS);

        // ---- Seeds ----
        $cats = [
            ['product', 'agri-food', 'کشاورزی و مواد غذایی', 'Agriculture & Food'],
            ['product', 'nuts-dried-fruit', 'خشکبار و آجیل', 'Nuts & Dried Fruit'],
            ['product', 'saffron-spices', 'زعفران و ادویه', 'Saffron & Spices'],
            ['product', 'fruit-vegetables', 'میوه و صیفی‌جات', 'Fruit & Vegetables'],
            ['product', 'carpet-handicrafts', 'فرش و صنایع دستی', 'Carpets & Handicrafts'],
            ['product', 'petrochemicals', 'پتروشیمی و پلیمر', 'Petrochemicals & Polymers'],
            ['product', 'oil-energy', 'نفت، گاز و انرژی', 'Oil, Gas & Energy'],
            ['product', 'steel-metals', 'فولاد و فلزات', 'Steel & Metals'],
            ['product', 'mining-stone', 'معدن و سنگ', 'Mining & Stone'],
            ['product', 'building-materials', 'مصالح ساختمانی', 'Building Materials'],
            ['product', 'chemicals', 'مواد شیمیایی', 'Chemicals'],
            ['product', 'textile-apparel', 'نساجی و پوشاک', 'Textile & Apparel'],
            ['product', 'leather-shoes', 'چرم و کفش', 'Leather & Footwear'],
            ['product', 'home-appliances', 'لوازم خانگی', 'Home Appliances'],
            ['product', 'machinery', 'ماشین‌آلات صنعتی', 'Industrial Machinery'],
            ['product', 'auto-parts', 'خودرو و قطعات', 'Automotive & Parts'],
            ['product', 'electronics', 'الکترونیک و برق', 'Electronics & Electrical'],
            ['product', 'pharma-medical', 'دارو و تجهیزات پزشکی', 'Pharma & Medical'],
            ['product', 'cosmetics', 'آرایشی و بهداشتی', 'Cosmetics & Hygiene'],
            ['product', 'packaging', 'بسته‌بندی', 'Packaging'],
            ['service', 'logistics', 'حمل‌ونقل و لجستیک', 'Logistics & Shipping'],
            ['service', 'customs', 'ترخیص و گمرک', 'Customs Clearance'],
            ['service', 'trade-consulting', 'بازرگانی و مشاوره', 'Trade & Consulting'],
            ['service', 'marketing', 'بازاریابی و برندینگ', 'Marketing & Branding'],
            ['service', 'it-services', 'فناوری اطلاعات', 'IT Services'],
            ['service', 'tourism', 'گردشگری و سلامت', 'Tourism & Health'],
            ['product', 'other', 'سایر', 'Other'],
        ];
        foreach ($cats as $i => [$type, $slug, $fa, $en]) {
            $db->exec(
                'INSERT IGNORE INTO categories (type, slug, name_fa, name_en, sort) VALUES (?, ?, ?, ?, ?)',
                [$type, $slug, $fa, $en, $slug === 'other' ? 999 : $i + 1]
            );
        }

        foreach ([['proposal_publish', 'domestic', 0], ['proposal_publish', 'international', 0]] as [$a, $s, $n]) {
            $db->exec('INSERT IGNORE INTO pricing_rules (action, scope, stars, updated_at) VALUES (?, ?, ?, NOW(3))', [$a, $s, $n]);
        }
        $settings = [
            'proposal_publish.enabled' => 'false',
            'proposal.lifetime_days' => '90',
            'feed.freshness_hours' => '72',
            'feed.weights' => '{"fresh": 1.0, "interest": 0.8, "geo": 0.3, "popular": 0.2, "verified": 0.3}',
        ];
        foreach ($settings as $key => $json) {
            $db->exec('INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES (?, ?, NOW(3))', [$key, $json]);
        }
    }
};
