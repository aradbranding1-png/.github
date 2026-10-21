<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Phase 2: Stars wallet (ledger), dynamic pricing, currency rates, star packages,
 * payment gateways, payments with state history, and page-view grants.
 * Seeds use INSERT IGNORE: values edited later by admins are never overwritten.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS wallets (
            user_id         BIGINT UNSIGNED NOT NULL,
            balance         BIGINT NOT NULL DEFAULT 0,
            reserved        BIGINT NOT NULL DEFAULT 0,
            lifetime_bought BIGINT NOT NULL DEFAULT 0,
            lifetime_spent  BIGINT NOT NULL DEFAULT 0,
            updated_at      DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id),
            CONSTRAINT ck_wallet_balance CHECK (balance >= 0),
            CONSTRAINT ck_wallet_reserved CHECK (reserved >= 0),
            CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS wallet_transactions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            type          TINYINT UNSIGNED NOT NULL,
            amount        BIGINT NOT NULL,
            balance_after BIGINT NOT NULL,
            reason        VARCHAR(40) NOT NULL,
            ref_type      VARCHAR(32) NULL,
            ref_id        BIGINT UNSIGNED NULL,
            actor_id      BIGINT UNSIGNED NULL,
            note          VARCHAR(255) NULL,
            created_at    DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_user (user_id, id),
            KEY ix_user_type (user_id, type, id),
            KEY ix_ref (ref_type, ref_id)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS pricing_rules (
            action     VARCHAR(40) NOT NULL,
            scope      ENUM('domestic','international') NOT NULL,
            stars      INT UNSIGNED NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (action, scope)
        ) " . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS fx_rates (
            currency       CHAR(3) NOT NULL,
            minor_per_star BIGINT UNSIGNED NOT NULL,
            minor_digits   TINYINT UNSIGNED NOT NULL,
            updated_by     BIGINT UNSIGNED NULL,
            updated_at     DATETIME(3) NOT NULL,
            PRIMARY KEY (currency)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS star_packages (
            id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stars       INT UNSIGNED NOT NULL,
            bonus_stars INT UNSIGNED NOT NULL DEFAULT 0,
            active      TINYINT(1) NOT NULL DEFAULT 1,
            sort        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            PRIMARY KEY (id),
            UNIQUE KEY uq_stars (stars)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS payment_gateways (
            id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code       VARCHAR(32) NOT NULL,
            name       VARCHAR(80) NOT NULL,
            country_id SMALLINT UNSIGNED NULL,
            currency   CHAR(3) NOT NULL,
            active     TINYINT(1) NOT NULL DEFAULT 0,
            priority   SMALLINT NOT NULL DEFAULT 100,
            config     JSON NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_code (code)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS payments (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id      BINARY(16) NOT NULL,
            user_id        BIGINT UNSIGNED NOT NULL,
            gateway_id     SMALLINT UNSIGNED NOT NULL,
            currency       CHAR(3) NOT NULL,
            amount_minor   BIGINT UNSIGNED NOT NULL,
            stars          BIGINT UNSIGNED NOT NULL,
            bonus_stars    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            rate_snapshot  BIGINT UNSIGNED NOT NULL,
            status         TINYINT UNSIGNED NOT NULL,
            authority      VARCHAR(128) NULL,
            gateway_ref    VARCHAR(128) NULL,
            card_mask      VARCHAR(32) NULL,
            credited_tx_id BIGINT UNSIGNED NULL,
            created_at     DATETIME(3) NOT NULL,
            updated_at     DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            UNIQUE KEY uq_gateway_ref (gateway_id, gateway_ref),
            KEY ix_authority (gateway_id, authority),
            KEY ix_user (user_id, id),
            KEY ix_status (status, updated_at),
            CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_payments_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways (id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS payment_events (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id  BIGINT UNSIGNED NOT NULL,
            from_status TINYINT UNSIGNED NULL,
            to_status   TINYINT UNSIGNED NOT NULL,
            note        VARCHAR(255) NULL,
            data        JSON NULL,
            created_at  DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_payment (payment_id, id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS page_view_grants (
            viewer_id  BIGINT UNSIGNED NOT NULL,
            page_id    BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME(3) NOT NULL,
            PRIMARY KEY (viewer_id, page_id)
        ) ' . self::OPTS);

        // ---- Seeds ----
        $prices = [
            ['page_view', 'domestic', 1], ['page_view', 'international', 2],
            ['proposal_send', 'domestic', 4], ['proposal_send', 'international', 8],
            ['public_letter', 'domestic', 20], ['public_letter', 'international', 40],
            ['private_letter', 'domestic', 100], ['private_letter', 'international', 200],
        ];
        foreach ($prices as [$action, $scope, $stars]) {
            $db->exec('INSERT IGNORE INTO pricing_rules (action, scope, stars, updated_at) VALUES (?, ?, ?, NOW(3))', [$action, $scope, $stars]);
        }
        // 1 Star = 1,000 Toman = 10,000 Rial (IRR has no minor digits).
        foreach ([['IRR', 10000, 0], ['USD', 1, 2], ['EUR', 1, 2], ['TRY', 40, 2], ['AED', 4, 2]] as [$cur, $rate, $digits]) {
            $db->exec('INSERT IGNORE INTO fx_rates (currency, minor_per_star, minor_digits, updated_at) VALUES (?, ?, ?, NOW(3))', [$cur, $rate, $digits]);
        }
        foreach ([[50, 0, 1], [100, 5, 2], [250, 20, 3], [500, 50, 4], [1000, 150, 5]] as [$stars, $bonus, $sort]) {
            $db->exec('INSERT IGNORE INTO star_packages (stars, bonus_stars, sort) VALUES (?, ?, ?)', [$stars, $bonus, $sort]);
        }
        $iran = $db->scalar("SELECT id FROM countries WHERE code = 'IR'");
        $db->exec("INSERT IGNORE INTO payment_gateways (code, name, country_id, currency, active, priority) VALUES ('zarinpal', 'زرین‌پال', ?, 'IRR', 1, 10)", [$iran]);
        $db->exec("INSERT IGNORE INTO payment_gateways (code, name, country_id, currency, active, priority) VALUES ('test', 'درگاه آزمایشی', NULL, 'IRR', 1, 999)");

        $settings = [
            'page_view.charge_mode' => '"every_view"',      // every_view | once_per_period
            'page_view.view_window_minutes' => '30',        // every_view: refresh/navigation within the window is free
            'page_view.period_hours' => '24',               // once_per_period
        ];
        foreach ($settings as $key => $json) {
            $db->exec('INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES (?, ?, NOW(3))', [$key, $json]);
        }

        // Wallet for every existing user.
        $db->exec('INSERT IGNORE INTO wallets (user_id, updated_at) SELECT id, NOW(3) FROM users');
    }
};
