<?php

declare(strict_types=1);

use App\Core\Db\Connection;

/*
 * 1.19.19: sign in with Google. A member writes the Gmail address of their Google account in their profile
 * (google_email, stored in canonical form); the first Google sign-in with that address binds the account's
 * immutable Google id (google_sub), which is what every later sign-in matches.
 */
return new class {
    public function up(Connection $db): void
    {
        $has = static fn (string $what, string $name): bool => $db->scalar(
            $what === 'column'
                ? "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ? LIMIT 1"
                : "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = ? LIMIT 1",
            [$name]
        ) !== null;

        if (!$has('column', 'google_email')) {
            $db->statement('ALTER TABLE users ADD COLUMN google_email VARCHAR(190) NULL');
        }
        if (!$has('column', 'google_sub')) {
            $db->statement('ALTER TABLE users ADD COLUMN google_sub VARCHAR(64) NULL');
        }
        if (!$has('column', 'google_linked_at')) {
            $db->statement('ALTER TABLE users ADD COLUMN google_linked_at DATETIME(3) NULL');
        }
        if (!$has('index', 'uq_google_sub')) {
            $db->statement('ALTER TABLE users ADD UNIQUE KEY uq_google_sub (google_sub)');
        }
        if (!$has('index', 'ix_google_email')) {
            $db->statement('ALTER TABLE users ADD KEY ix_google_email (google_email)');
        }
    }
};
