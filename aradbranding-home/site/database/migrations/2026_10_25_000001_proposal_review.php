<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.19.18: proposal review. Status 1 = pending review (a published or rejected proposal that its owner edited), and
 * a rejected proposal keeps the reason the moderator wrote so the owner can fix it.
 */
return new class {
    public function up(Connection $db): void
    {
        if ($db->scalar("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposals' AND COLUMN_NAME = 'reject_reason' LIMIT 1") === null) {
            $db->statement('ALTER TABLE proposals ADD COLUMN reject_reason VARCHAR(500) NULL');
        }
    }
};
