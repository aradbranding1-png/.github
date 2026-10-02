<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/* 1.15.3: each member's trade role (تاجر صادراتی، واردکننده، بین‌الملل، نماینده بین‌الملل، متحد تجاری). */
return new class {
    public function up(Connection $db): void
    {
        if ($db->scalar("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'trade_role' LIMIT 1") === null) {
            $db->statement('ALTER TABLE user_profiles ADD COLUMN trade_role VARCHAR(20) NULL AFTER business_area');
        }
    }
};
