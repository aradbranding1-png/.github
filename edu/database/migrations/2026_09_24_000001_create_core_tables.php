<?php
/**
 * Core schema: users, RBAC, groups, org structure, taxonomies, settings, logs.
 */
return [
    'description' => 'جداول پایه: کاربران، نقش‌ها، مجوزها، گروه‌ها، ساختار سازمانی، تنظیمات و لاگ‌ها',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            mobile VARCHAR(20) NULL UNIQUE,
            email VARCHAR(190) NULL UNIQUE,
            username VARCHAR(100) NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            segment VARCHAR(20) NOT NULL DEFAULT 'merchant',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            is_root TINYINT(1) NOT NULL DEFAULT 0,
            my_user_id VARCHAR(100) NULL UNIQUE,
            my_linked_at DATETIME NULL,
            my_synced_at DATETIME NULL,
            avatar_path VARCHAR(255) NULL,
            avatar_source VARCHAR(10) NOT NULL DEFAULT 'none',
            my_avatar_url VARCHAR(500) NULL,
            supervisor_id INT UNSIGNED NULL,
            job_title VARCHAR(150) NULL,
            bio TEXT NULL,
            first_login_at DATETIME NULL,
            last_login_at DATETIME NULL,
            login_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_activity_at DATETIME NULL,
            merged_into_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_users_status (status, deleted_at),
            KEY idx_users_segment (segment),
            KEY idx_users_supervisor (supervisor_id),
            KEY idx_users_last_login (last_login_at),
            KEY idx_users_name (last_name, first_name)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(500) NULL,
            color VARCHAR(20) NOT NULL DEFAULT 'primary',
            data_scope VARCHAR(20) NOT NULL DEFAULT 'own',
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_root TINYINT(1) NOT NULL DEFAULT 0,
            is_learner TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS permissions (
            `key` VARCHAR(80) NOT NULL PRIMARY KEY,
            module VARCHAR(40) NOT NULL,
            action VARCHAR(40) NOT NULL,
            label VARCHAR(200) NOT NULL,
            root_only TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_perm_module (module)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT UNSIGNED NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            PRIMARY KEY (role_id, permission_key),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NULL,
            assigned_at DATETIME NULL,
            PRIMARY KEY (user_id, role_id),
            KEY idx_ur_role (role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INT UNSIGNED NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            effect VARCHAR(10) NOT NULL DEFAULT 'allow',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (user_id, permission_key),
            CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$T";

        // Groups: the three main segments (merchant/employee/agent) + unlimited sub-groups
        $sql[] = "CREATE TABLE IF NOT EXISTS `groups` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NULL,
            segment VARCHAR(20) NOT NULL DEFAULT 'custom',
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#4f46e5',
            icon VARCHAR(40) NOT NULL DEFAULT 'users',
            supervisor_id INT UNSIGNED NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_groups_parent (parent_id),
            KEY idx_groups_segment (segment)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS group_members (
            group_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            joined_at DATETIME NULL,
            PRIMARY KEY (group_id, user_id),
            KEY idx_gm_user (user_id),
            CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
            CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$T";

        // Levels per group (سطح‌بندی مستقل هر گروه)
        $sql[] = "CREATE TABLE IF NOT EXISTS levels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            rank_no INT NOT NULL DEFAULT 1,
            color VARCHAR(20) NOT NULL DEFAULT '#0ea5e9',
            description VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_levels_group (group_id, rank_no),
            CONSTRAINT fk_levels_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_levels (
            user_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            level_id INT UNSIGNED NOT NULL,
            assigned_at DATETIME NULL,
            PRIMARY KEY (user_id, group_id),
            KEY idx_ul_level (level_id),
            CONSTRAINT fk_ul_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ul_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE CASCADE
        )$T";

        // Organizational structure (dynamic types: معاونت → واحد → بخش → سمت ...)
        $sql[] = "CREATE TABLE IF NOT EXISTS org_unit_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            icon VARCHAR(40) NOT NULL DEFAULT 'building-2',
            created_at DATETIME NOT NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS org_units (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NULL,
            type_id INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            code VARCHAR(50) NULL,
            manager_id INT UNSIGNED NULL,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_org_parent (parent_id),
            KEY idx_org_type (type_id),
            CONSTRAINT fk_org_type FOREIGN KEY (type_id) REFERENCES org_unit_types(id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_org_units (
            user_id INT UNSIGNED NOT NULL,
            org_unit_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, org_unit_id),
            KEY idx_uou_unit (org_unit_id),
            CONSTRAINT fk_uou_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_uou_unit FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE CASCADE
        )$T";

        // Dynamic classifications (merchant: stage/activity/market/product ... agent: country/region/type ...)
        $sql[] = "CREATE TABLE IF NOT EXISTS taxonomies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            segment VARCHAR(20) NOT NULL,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            is_hierarchical TINYINT(1) NOT NULL DEFAULT 0,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_tax (segment, slug)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS taxonomy_terms (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            taxonomy_id INT UNSIGNED NOT NULL,
            parent_id INT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_terms_tax (taxonomy_id),
            CONSTRAINT fk_terms_tax FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_terms (
            user_id INT UNSIGNED NOT NULL,
            term_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, term_id),
            KEY idx_ut_term (term_id),
            CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ut_term FOREIGN KEY (term_id) REFERENCES taxonomy_terms(id) ON DELETE CASCADE
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(80) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            updated_at DATETIME NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS login_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            method VARCHAR(20) NOT NULL DEFAULT 'password',
            identifier VARCHAR(100) NULL,
            success TINYINT(1) NOT NULL DEFAULT 1,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_lh_user (user_id, created_at)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL,
            ref_type VARCHAR(40) NULL,
            ref_id INT UNSIGNED NULL,
            meta TEXT NULL,
            ip VARCHAR(45) NULL,
            day DATE NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_al_user_day (user_id, day),
            KEY idx_al_type (type, created_at),
            KEY idx_al_ref (ref_type, ref_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_daily_activity (
            user_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            logins INT UNSIGNED NOT NULL DEFAULT 0,
            lessons_viewed INT UNSIGNED NOT NULL DEFAULT 0,
            lessons_completed INT UNSIGNED NOT NULL DEFAULT 0,
            courses_completed INT UNSIGNED NOT NULL DEFAULT 0,
            exams INT UNSIGNED NOT NULL DEFAULT 0,
            exercises INT UNSIGNED NOT NULL DEFAULT 0,
            content_views INT UNSIGNED NOT NULL DEFAULT 0,
            learning_events INT UNSIGNED NOT NULL DEFAULT 0,
            seconds_spent INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, day),
            KEY idx_uda_day (day)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            impersonator_id INT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NULL,
            target_id INT UNSIGNED NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'success',
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_audit_user (user_id, created_at),
            KEY idx_audit_action (action),
            KEY idx_audit_created (created_at)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS impersonation_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            root_id INT UNSIGNED NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            duration_sec INT UNSIGNED NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            KEY idx_imp_target (target_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT NULL,
            link VARCHAR(500) NULL,
            dedupe_key VARCHAR(120) NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_notif_user (user_id, read_at),
            KEY idx_notif_dedupe (user_id, dedupe_key)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS rate_limits (
            `key` VARCHAR(64) NOT NULL PRIMARY KEY,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            reset_at INT UNSIGNED NOT NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS api_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            token_hint VARCHAR(12) NOT NULL,
            abilities VARCHAR(500) NOT NULL DEFAULT '',
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS account_merges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_user_id INT UNSIGNED NOT NULL,
            target_user_id INT UNSIGNED NOT NULL,
            summary TEXT NULL,
            merged_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL
        )$T";

        foreach ($sql as $s) $db->exec($s);
    },
];
