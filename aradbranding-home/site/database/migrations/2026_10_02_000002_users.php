<?php

declare(strict_types=1);

use App\Core\Db\Connection;

return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS users (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id            BINARY(16) NOT NULL,
            handle               VARCHAR(40) NULL,
            first_name           VARCHAR(100) NOT NULL,
            last_name            VARCHAR(100) NOT NULL,
            search_name          VARCHAR(220) NOT NULL,
            email                VARCHAR(191) NOT NULL,
            phone_cc             VARCHAR(6) NOT NULL,
            phone                VARCHAR(20) NOT NULL,
            password_hash        VARCHAR(255) NOT NULL,
            country_id           SMALLINT UNSIGNED NOT NULL,
            language_id          SMALLINT UNSIGNED NOT NULL,
            avatar_path          VARCHAR(255) NULL,
            status               TINYINT UNSIGNED NOT NULL DEFAULT 1,
            email_verified_at    DATETIME(3) NULL,
            phone_verified_at    DATETIME(3) NULL,
            business_verified_at DATETIME(3) NULL,
            failed_logins        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until         DATETIME(3) NULL,
            last_active_at       DATETIME(3) NULL,
            created_at           DATETIME(3) NOT NULL,
            updated_at           DATETIME(3) NOT NULL,
            deleted_at           DATETIME(3) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            UNIQUE KEY uq_handle (handle),
            UNIQUE KEY uq_email (email),
            UNIQUE KEY uq_phone (phone_cc, phone),
            KEY ix_country_lang (country_id, language_id, status, id),
            KEY ix_status_created (status, created_at, id),
            FULLTEXT KEY ft_name (search_name),
            CONSTRAINT fk_users_country FOREIGN KEY (country_id) REFERENCES countries (id),
            CONSTRAINT fk_users_language FOREIGN KEY (language_id) REFERENCES languages (id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS user_profiles (
            user_id               BIGINT UNSIGNED NOT NULL,
            city                  VARCHAR(100) NULL,
            company_name          VARCHAR(200) NULL,
            business_area         VARCHAR(200) NULL,
            bio                   TEXT NULL,
            show_phone            TINYINT(1) NOT NULL DEFAULT 0,
            show_email            TINYINT(1) NOT NULL DEFAULT 0,
            accept_public_letters TINYINT(1) NOT NULL DEFAULT 1,
            updated_at            DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS user_socials (
            user_id   BIGINT UNSIGNED NOT NULL,
            network   VARCHAR(20) NOT NULL,
            value     VARCHAR(255) NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (user_id, network),
            CONSTRAINT fk_socials_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS user_counters (
            user_id                 BIGINT UNSIGNED NOT NULL,
            unread_letters          INT UNSIGNED NOT NULL DEFAULT 0,
            unread_notifications    INT UNSIGNED NOT NULL DEFAULT 0,
            connections             INT UNSIGNED NOT NULL DEFAULT 0,
            proposals               INT UNSIGNED NOT NULL DEFAULT 0,
            letters_sent            INT UNSIGNED NOT NULL DEFAULT 0,
            replies                 INT UNSIGNED NOT NULL DEFAULT 0,
            page_views_received     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            proposal_views_received BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at              DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_counters_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) ' . self::OPTS);

        $db->statement("CREATE TABLE IF NOT EXISTS login_logs (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NULL,
            email_hash BINARY(32) NOT NULL,
            ip         VARBINARY(16) NULL,
            result     VARCHAR(16) NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id, created_at),
            KEY ix_user (user_id, created_at),
            KEY ix_email (email_hash, created_at)
        ) " . self::OPTS . "
        PARTITION BY RANGE COLUMNS (created_at) (
            PARTITION p_init VALUES LESS THAN ('2026-01-01'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )");
    }
};
