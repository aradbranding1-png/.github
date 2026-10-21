<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/* Discovery & search: index for "popular opportunities", and the daily digest switch. */
return new class {
    public function up(Connection $db): void
    {
        if ($db->scalar("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_feed' AND INDEX_NAME = 'ix_popular' LIMIT 1") === null) {
            $db->statement('ALTER TABLE proposal_feed ADD KEY ix_popular (views_7d, published_at)');
        }
        $db->exec("INSERT IGNORE INTO settings (`key`, value, updated_at) VALUES ('notifications.daily_digest', 'true', NOW(3))");
    }
};
