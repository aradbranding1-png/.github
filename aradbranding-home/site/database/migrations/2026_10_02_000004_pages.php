<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * Business pages: one per language per owner (soft-deleted pages free their language slot
 * through the generated lang_slot column), plus owner-defined routing rules.
 */
return new class {
    private const OPTS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS pages (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id    BINARY(16) NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL,
            language_id  SMALLINT UNSIGNED NOT NULL,
            country_id   SMALLINT UNSIGNED NOT NULL,
            status       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            title        VARCHAR(200) NOT NULL,
            company_name VARCHAR(200) NULL,
            teaser       VARCHAR(500) NOT NULL,
            about        TEXT NULL,
            content      JSON NULL,
            cover_path   VARCHAR(255) NULL,
            avatar_path  VARCHAR(255) NULL,
            version      INT UNSIGNED NOT NULL DEFAULT 1,
            published_at DATETIME(3) NULL,
            created_at   DATETIME(3) NOT NULL,
            updated_at   DATETIME(3) NOT NULL,
            deleted_at   DATETIME(3) NULL,
            lang_slot    SMALLINT UNSIGNED AS (IF(deleted_at IS NULL, language_id, NULL)) STORED,
            PRIMARY KEY (id),
            UNIQUE KEY uq_public (public_id),
            UNIQUE KEY uq_user_lang (user_id, lang_slot),
            KEY ix_status_created (status, created_at, id),
            KEY ix_country_status (country_id, status, id),
            FULLTEXT KEY ft_page (title, company_name, teaser),
            CONSTRAINT fk_pages_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_pages_language FOREIGN KEY (language_id) REFERENCES languages (id),
            CONSTRAINT fk_pages_country FOREIGN KEY (country_id) REFERENCES countries (id)
        ) ' . self::OPTS);

        $db->statement('CREATE TABLE IF NOT EXISTS page_routes (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_user_id  BIGINT UNSIGNED NOT NULL,
            country_id     SMALLINT UNSIGNED NULL,
            language_id    SMALLINT UNSIGNED NULL,
            target_page_id BIGINT UNSIGNED NOT NULL,
            priority       SMALLINT NOT NULL DEFAULT 0,
            active         TINYINT(1) NOT NULL DEFAULT 1,
            country_key    SMALLINT UNSIGNED AS (IFNULL(country_id, 0)) STORED,
            language_key   SMALLINT UNSIGNED AS (IFNULL(language_id, 0)) STORED,
            created_at     DATETIME(3) NOT NULL,
            updated_at     DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rule (owner_user_id, country_key, language_key),
            KEY ix_target (target_page_id),
            CONSTRAINT fk_routes_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
            CONSTRAINT fk_routes_page FOREIGN KEY (target_page_id) REFERENCES pages (id)
        ) ' . self::OPTS);
    }
};
