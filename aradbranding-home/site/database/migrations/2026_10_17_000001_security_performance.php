<?php

declare(strict_types=1);

use App\Core\Db\Connection;
use App\Core\Env;

/*
 * 1.19.14 — security & performance:
 *  - Indexes so the per-request "current user" query only touches unread rows:
 *      thread_participants ix_unread (user_id, folder, thread_type, unread_count)
 *      announcements       ix_live   (deleted_at, id)
 *  - Production: the simulated payment gateway is deactivated in the database too, and any
 *    unfinished test payment is closed so the payment cron can never credit it.
 * Forward-only and repeatable (checks before every change). No user data is deleted.
 */
return new class {
    public function up(Connection $db): void
    {
        $indexes = [
            ['thread_participants', 'ix_unread', 'ADD KEY ix_unread (user_id, folder, thread_type, unread_count)'],
            ['announcements', 'ix_live', 'ADD KEY ix_live (deleted_at, id)'],
        ];
        foreach ($indexes as [$table, $name, $ddl]) {
            if (!$this->hasTable($db, $table) || $this->hasIndex($db, $table, $name)) {
                continue;
            }
            // INPLACE / LOCK=NONE: the table stays readable and writable while the index is built.
            try {
                $db->statement("ALTER TABLE `{$table}` {$ddl}, ALGORITHM=INPLACE, LOCK=NONE");
            } catch (\Throwable $e) {
                $db->statement("ALTER TABLE `{$table}` {$ddl}");
            }
        }

        if (strtolower((string) Env::get('APP_ENV', 'production')) === 'production' && $this->hasTable($db, 'payment_gateways')) {
            $db->exec("UPDATE payment_gateways SET active = 0 WHERE code = 'test'");
            $testId = $db->scalar("SELECT id FROM payment_gateways WHERE code = 'test'");
            if ($testId !== null) {
                // created(0) / pending(1) / verifying(2) / paid-not-yet-credited(3) → failed(5)
                $db->exec(
                    "UPDATE payments SET status = 5, updated_at = NOW(3) WHERE gateway_id = ? AND status IN (0, 1, 2, 3)",
                    [(int) $testId]
                );
            }
        }
    }

    private function hasTable(Connection $db, string $table): bool
    {
        return $db->scalar(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) !== null;
    }

    private function hasIndex(Connection $db, string $table, string $index): bool
    {
        return $db->scalar(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }
};
