<?php
/**
 * Trader growth system (نظام رشد تاجر): 12 dynamic stages in 4 seasons, linked courses / events / services,
 * weighted progress, expert approvals, deals, services synced from the sales system, multiple phones, ranks & stars.
 * The previous group-based growth tables (growth_stages, user_growth, growth_history) are kept untouched.
 */
return [
    'description' => 'نظام رشد تاجر ۱۲ مرحله‌ای، خدمات خریداری‌شده، معاملات، رتبه و ستاره',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $has = function (string $table, string $col) use ($db): bool {
            return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
        };
        foreach ([
            'tg_stage' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'tg_achieved' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'tg_progress' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
            'tg_track_id' => 'INT UNSIGNED NULL',
            'tg_dirty' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'tg_calc_at' => 'DATETIME NULL',
            'tg_services_at' => 'DATETIME NULL',
            'tg_services_api_at' => 'DATETIME NULL',
            'tg_services_error' => 'VARCHAR(255) NULL',
        ] as $col => $def) {
            if (!$has('users', $col)) $db->exec("ALTER TABLE users ADD COLUMN `$col` $def");
        }
        try { $db->exec('ALTER TABLE users ADD KEY idx_users_tg (tg_stage)'); } catch (\Throwable) {}
        try { $db->exec('ALTER TABLE users ADD KEY idx_users_tg_dirty (tg_dirty)'); } catch (\Throwable) {}

        $db->exec("CREATE TABLE IF NOT EXISTS tg_seasons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            sort INT NOT NULL DEFAULT 1,
            color VARCHAR(20) NOT NULL DEFAULT '#4f46e5'
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_tracks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            sort INT NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_stages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            season_id INT UNSIGNED NULL,
            sort INT NOT NULL DEFAULT 1,
            title VARCHAR(150) NOT NULL,
            goal TEXT NULL,
            description MEDIUMTEXT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#4f46e5',
            icon VARCHAR(40) NOT NULL DEFAULT 'flag',
            approval VARCHAR(10) NOT NULL DEFAULT 'auto',
            pass_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
            edu_weight SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            svc_weight SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            deal_weight SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            min_deals INT UNSIGNED NOT NULL DEFAULT 0,
            allow_deals TINYINT(1) NOT NULL DEFAULT 0,
            request_docs TEXT NULL,
            request_hint TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_tgs_sort (sort)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stage_id INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            ref_id INT UNSIGNED NULL,
            ref_type VARCHAR(20) NULL,
            min_count INT UNSIGNED NULL,
            track_id INT UNSIGNED NULL,
            weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            KEY idx_tgi_stage (stage_id),
            KEY idx_tgi_ref (kind, ref_id)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_services (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            category VARCHAR(100) NULL,
            match_keys TEXT NULL,
            description VARCHAR(500) NULL,
            buy_url VARCHAR(500) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_user_services (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            service_id INT UNSIGNED NULL,
            phone VARCHAR(20) NULL,
            service_name VARCHAR(255) NOT NULL,
            service_code VARCHAR(120) NULL,
            status VARCHAR(60) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(10) NOT NULL DEFAULT 'api',
            purchased_at DATETIME NULL,
            raw TEXT NULL,
            note VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            api_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_tgus_user (user_id, service_id),
            KEY idx_tgus_service (service_id),
            KEY idx_tgus_name (service_name(60))
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS user_phones (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            phone VARCHAR(20) NOT NULL,
            label VARCHAR(60) NULL,
            last_checked_at DATETIME NULL,
            last_status VARCHAR(20) NULL,
            last_count INT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_up (user_id, phone),
            KEY idx_up_phone (phone)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_user_stages (
            user_id INT UNSIGNED NOT NULL,
            stage_id INT UNSIGNED NOT NULL,
            status VARCHAR(10) NOT NULL,
            by_user INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, stage_id)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_deals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            product VARCHAR(200) NOT NULL,
            customer VARCHAR(200) NOT NULL,
            market VARCHAR(150) NULL,
            amount DECIMAL(20,0) NULL,
            deal_date DATE NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewer_id INT UNSIGNED NULL,
            review_note VARCHAR(500) NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_tgd_user (user_id, status),
            KEY idx_tgd_status (status)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            stage_id INT UNSIGNED NOT NULL,
            note TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewer_id INT UNSIGNED NULL,
            review_note VARCHAR(500) NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_tgr_user (user_id, stage_id),
            KEY idx_tgr_status (status)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_docs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_type VARCHAR(10) NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            file_id INT UNSIGNED NOT NULL,
            label VARCHAR(150) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_tgdoc_owner (owner_type, owner_id),
            KEY idx_tgdoc_file (file_id)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_ranks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(60) NOT NULL,
            stars TINYINT UNSIGNED NOT NULL DEFAULT 1,
            filled TINYINT(1) NOT NULL DEFAULT 1,
            from_stage TINYINT UNSIGNED NOT NULL,
            to_stage TINYINT UNSIGNED NOT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#f59e0b',
            sort INT NOT NULL DEFAULT 1
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            from_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
            to_stage TINYINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'auto',
            by_user INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_tgh_user (user_id)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS tg_sync_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            total INT UNSIGNED NOT NULL DEFAULT 0,
            done INT UNSIGNED NOT NULL DEFAULT 0,
            ok INT UNSIGNED NOT NULL DEFAULT 0,
            failed INT UNSIGNED NOT NULL DEFAULT 0,
            services INT UNSIGNED NOT NULL DEFAULT 0,
            last_user_id INT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(255) NULL,
            started_by INT UNSIGNED NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL
        )$T");

        // ------------------------------------------------------------------ seed (only on first run)
        $now = date('Y-m-d H:i:s');
        if (!(int)$db->query('SELECT COUNT(*) FROM tg_seasons')->fetchColumn()) {
            $ins = $db->prepare('INSERT INTO tg_seasons (title, sort, color) VALUES (?,?,?)');
            foreach ([['ساختن پایه‌های تجارت', '#6366f1'], ['ساختن تجارت', '#0ea5e9'], ['تجارت موفق', '#10b981'], ['توسعه ساختار تجاری', '#f59e0b']] as $i => [$t, $c]) $ins->execute([$t, $i + 1, $c]);
        }
        if (!(int)$db->query('SELECT COUNT(*) FROM tg_tracks')->fetchColumn()) {
            $ins = $db->prepare('INSERT INTO tg_tracks (name, sort) VALUES (?,?)');
            foreach (['تجارت داخلی', 'صادرات', 'تجارت بین‌الملل'] as $i => $t) $ins->execute([$t, $i + 1]);
        }
        if (!(int)$db->query('SELECT COUNT(*) FROM tg_ranks')->fetchColumn()) {
            $ins = $db->prepare('INSERT INTO tg_ranks (title, stars, filled, from_stage, to_stage, color, sort) VALUES (?,?,?,?,?,?,?)');
            foreach ([['تجارت آموز', 1, 0, 1, 3, '#64748b'], ['PR', 1, 1, 4, 6, '#0ea5e9'], ['PRO', 2, 1, 7, 9, '#8b5cf6'], ['PROMAX', 3, 1, 10, 12, '#f59e0b']] as $i => $r) $ins->execute([...$r, $i + 1]);
        }
        if (!(int)$db->query('SELECT COUNT(*) FROM tg_stages')->fetchColumn()) {
            $seasons = $db->query('SELECT id FROM tg_seasons ORDER BY sort')->fetchAll(PDO::FETCH_COLUMN);
            $dealDocs = "عکس بارگیری\nفیلم بارگیری\nفیلم معرفی معامله توسط خود تاجر\nاسناد حمل\nفاکتور\nمدارک معامله";
            // [season, title, goal, icon, color, approval, pass, edu, svc, deal, min_deals, allow_deals, docs, hint]
            $stages = [
                [0, 'آشنایی اولیه', 'تاجر با مفهوم تجارت، مسیر تجاری خود و اصول اولیه تجارت آشنا شود.', 'lightbulb', '#6366f1', 'auto', 100, 100, 0, 0, 0, 0, null, null],
                [0, 'آموزش جامع', 'تاجر آموزش‌های کامل موردنیاز مسیر تجاری خود را دریافت کند: وبینار تجاری، کارگاه تجاری آنلاین، فایل‌های تجاری مهارت‌محور و میتینگ‌های آنلاین.', 'graduation-cap', '#6366f1', 'auto', 100, 100, 0, 0, 0, 0, null, null],
                [0, 'مشاوره و پشتیبانی', 'تاجر از خدمات مشاوره و پشتیبانی موردنیاز خود استفاده کند.', 'message-square', '#6366f1', 'auto', 100, 40, 60, 0, 0, 0, null, null],
                [1, 'برندسازی', 'ایجاد زیرساخت‌های برند و معرفی تجاری.', 'palette', '#0ea5e9', 'auto', 100, 40, 60, 0, 0, 0, null, null],
                [1, 'مذاکره', 'تاجر توانایی ارتباط، مذاکره و پیشبرد معامله را پیدا کند.', 'handshake', '#0ea5e9', 'auto', 100, 40, 60, 0, 0, 0, null, null],
                [1, 'عملیات بازرگانی', 'تاجر توانایی اجرای فرآیند واقعی تجارت را پیدا کند: تأمین، لجستیک، حمل، قرارداد، امور مالی و اجرای معامله.', 'truck', '#0ea5e9', 'auto', 100, 40, 60, 0, 0, 0, null, null],
                [2, 'تجارت اول', 'ثبت اولین معامله واقعی تاجر. مدارک معامله (عکس و فیلم بارگیری، فیلم معرفی معامله، اسناد حمل، فاکتور) ثبت و توسط کارشناس بررسی می‌شود.', 'rocket', '#10b981', 'auto', 100, 0, 0, 100, 1, 1, null, 'اولین معامله خود را با مدارک در بخش «معاملات من» ثبت کنید؛ پس از تأیید کارشناس این مرحله تکمیل می‌شود.'],
                [2, 'تکرار تجارت', 'تاجر بتواند معاملات خود را تکرار کند. معاملات متعدد با محصول، مشتری، مبلغ، تاریخ و مدارک ثبت می‌شود.', 'repeat', '#10b981', 'expert', 100, 0, 0, 100, 3, 1, null, 'پس از ثبت و تأیید معاملات، درخواست تأیید این مرحله را ارسال کنید.'],
                [2, 'تثبیت تجارت', 'رسیدن به تجارت مستمر: تعداد معاملات، استمرار فروش، مشتری‌های تکرارشونده و گزارش عملکرد.', 'trophy', '#10b981', 'expert', 100, 0, 0, 100, 10, 1, "گزارش عملکرد", 'گزارش عملکرد خود را پیوست و درخواست بررسی ارسال کنید.'],
                [3, 'شرکت تجاری', 'تاجر از حالت فردی خارج شده و ساختار تجاری ایجاد کرده باشد.', 'building-2', '#f59e0b', 'manual', 100, 0, 0, 0, 0, 0, "مدارک ثبت شرکت\nاطلاعات حقوقی\nساختار تیم\nتقسیم وظایف", null],
                [3, 'هولدینگ تجاری', 'مدیریت چند ساختار تجاری: چند شرکت، چند محصول، چند بازار.', 'landmark', '#f59e0b', 'manual', 100, 0, 0, 0, 0, 0, "مدارک شرکت‌ها\nفهرست محصولات\nبازارهای فعال", null],
                [3, 'سازمان تجاری', 'بالاترین سطح رشد: ساختار سازمانی، تیم‌های متعدد، مدیریت مجموعه‌های مختلف و همکاری‌های گسترده تجاری.', 'crown', '#f59e0b', 'manual', 100, 0, 0, 0, 0, 0, "ساختار سازمانی\nمعرفی تیم‌ها و مجموعه‌ها\nهمکاری‌های تجاری", null],
            ];
            $ins = $db->prepare('INSERT INTO tg_stages (season_id, sort, title, goal, icon, color, approval, pass_percent, edu_weight, svc_weight, deal_weight, min_deals, allow_deals, request_docs, request_hint, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stageIds = [];
            foreach ($stages as $i => $s) {
                $ins->execute([$seasons[$s[0]] ?? null, $i + 1, $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $s[8], $s[9], $s[10], $s[11], $s[12], $s[13], $now]);
                $stageIds[$i + 1] = (int)$db->lastInsertId();
            }
            // sample services from the model, linked to their stages (admin can edit / remove)
            $svc = [
                3 => ['مشاوره تجاری' => 'مشاوره', 'پشتیبانی تجاری' => 'مشاوره'],
                4 => ['طراحی برند' => 'برندسازی', 'سایت تجاری' => 'برندسازی', 'کاتالوگ' => 'برندسازی', 'معرفی‌نامه تجاری' => 'برندسازی', 'پروفایل تجاری' => 'برندسازی'],
                5 => ['منتور تجارت داخلی' => 'مذاکره', 'منتور صادرات' => 'مذاکره', 'همراه مذاکره' => 'مذاکره', 'جلسات مذاکره تخصصی' => 'مذاکره', 'آموزش فروش و مذاکره' => 'مذاکره'],
                6 => ['تأمین‌کننده‌یابی' => 'عملیات', 'خدمات تأمین' => 'عملیات', 'خدمات حمل' => 'عملیات', 'خدمات اجرایی صادرات' => 'عملیات', 'خدمات مالی' => 'عملیات'],
            ];
            $si = $db->prepare('INSERT INTO tg_services (name, category, match_keys, sort, created_at) VALUES (?,?,?,?,?)');
            $ii = $db->prepare("INSERT INTO tg_items (stage_id, kind, ref_id, weight, created_at) VALUES (?, 'service', ?, 1, ?)");
            $n = 0;
            foreach ($svc as $stageNo => $list) {
                foreach ($list as $name => $cat) {
                    $si->execute([$name, $cat, $name, ++$n, $now]);
                    $ii->execute([$stageIds[$stageNo], (int)$db->lastInsertId(), $now]);
                }
            }
            // stage 2: all business webinars / online workshops (dynamic: new ones are counted automatically)
            $ev = $db->prepare("INSERT INTO tg_items (stage_id, kind, ref_type, weight, created_at) VALUES (?, 'event_all', ?, 1, ?)");
            foreach (['webinar', 'workshop'] as $t) $ev->execute([$stageIds[2], $t, $now]);
            $ec = $db->prepare("INSERT INTO tg_items (stage_id, kind, ref_type, min_count, weight, created_at) VALUES (?, 'event_count', 'meeting', 5, 1, ?)");
            $ec->execute([$stageIds[2], $now]);
        }

        $set = $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)');
        foreach ([
            'growth_segments' => 'merchant',
            'growth_track_self' => '1',
            'growth_show_badge' => '1',
            'growth_currency' => 'تومان',
            'growth_deal_docs' => "عکس بارگیری\nفیلم بارگیری\nفیلم معرفی معامله توسط خود تاجر\nاسناد حمل\nفاکتور\nسایر مدارک معامله",
            'growth_api_enabled' => '0',
            'growth_api_url' => '',
            'growth_api_method' => 'GET',
            'growth_api_phone_param' => 'mobile',
            'growth_api_name_param' => 'name',
            'growth_api_phone_format' => '09',
            'growth_api_list_path' => '',
            'growth_api_field_name' => '',
            'growth_api_field_code' => '',
            'growth_api_field_status' => '',
            'growth_api_field_date' => '',
            'growth_api_ok_statuses' => '',
            'growth_api_timeout' => '15',
        ] as $k => $v) $set->execute([$k, $v]);
        @unlink(dirname(__DIR__, 2) . '/storage/cache/settings.php');

        $perms = [
            'growth.run' => 'نظام رشد — بروزرسانی خدمات از سامانه فروش',
            'growth.export' => 'نظام رشد — خروجی Excel',
        ];
        $ins = $db->prepare('INSERT IGNORE INTO permissions (`key`, module, action, label, root_only) VALUES (?,?,?,?,0)');
        foreach ($perms as $k => $l) { [$m, $a] = explode('.', $k); $ins->execute([$k, $m, $a, $l]); }
        $rid = $db->prepare('SELECT id FROM roles WHERE slug = ?');
        $rp = $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?,?)');
        foreach (['system_admin' => ['growth.run', 'growth.export'], 'edu_manager' => ['growth.run', 'growth.export'], 'report_viewer' => ['growth.export']] as $slug => $keys) {
            $rid->execute([$slug]);
            $id = $rid->fetchColumn();
            if ($id) foreach ($keys as $k) $rp->execute([(int)$id, $k]);
        }
    },
];
