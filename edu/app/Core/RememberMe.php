<?php
declare(strict_types=1);

namespace App\Core;

/**
 * «مرا به خاطر بسپار» — persistent sign-in for 30 days after the last visit (sliding).
 *
 * Cookie value is "<selector>:<validator>". Only sha256(validator) is stored in the database, so a leaked
 * database row cannot be turned into a working cookie. Each device has its own row; logging out removes
 * the row of that device, changing the password / deactivating the account removes all of them.
 */
final class RememberMe
{
    public const DAYS = 30;
    private const TABLE = 'remember_tokens';

    public static function cookieName(): string
    {
        return (string)env('SESSION_NAME', 'aradedu_sid') . '_rm';
    }

    private static function lifetime(): int
    {
        return self::DAYS * 86400;
    }

    /** Create a token for this device and send the cookie */
    public static function issue(int $userId): void
    {
        try {
            self::ensureTable();
            $selector = bin2hex(random_bytes(12));
            $validator = bin2hex(random_bytes(32));
            DB::insert(self::TABLE, [
                'user_id' => $userId, 'selector' => $selector, 'token_hash' => hash('sha256', $validator),
                'user_agent' => mb_substr(Request::userAgent(), 0, 255), 'ip' => Request::ip(),
                'created_at' => now(), 'last_used_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + self::lifetime()),
            ]);
            self::setCookie($selector . ':' . $validator, time() + self::lifetime());
            $_SESSION['_rm'] = $selector;
        } catch (\Throwable $e) {
            Logger::error('remember-me issue failed: ' . $e->getMessage());
        }
    }

    /** Valid cookie → user row (and the expiry is pushed 30 days forward). Invalid cookie → removed. */
    public static function consume(): ?array
    {
        $raw = (string)($_COOKIE[self::cookieName()] ?? '');
        if ($raw === '') return null;
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) { self::forgetCookie(); return null; }
        try {
            $row = DB::one('SELECT * FROM ' . self::TABLE . ' WHERE selector = ?', [$m[1]]);
        } catch (\Throwable) {
            return null; // table not created yet
        }
        if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $m[2])) || strtotime((string)$row['expires_at']) < time()) {
            if ($row) DB::delete(self::TABLE, 'id = ?', [(int)$row['id']]);
            self::forgetCookie();
            return null;
        }
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [(int)$row['user_id']]);
        if (!$u || $u['status'] !== 'active') {
            DB::delete(self::TABLE, 'user_id = ?', [(int)$row['user_id']]);
            self::forgetCookie();
            return null;
        }
        // sliding window: 30 days from the latest visit
        $exp = time() + self::lifetime();
        DB::update(self::TABLE, ['last_used_at' => now(), 'expires_at' => date('Y-m-d H:i:s', $exp), 'ip' => Request::ip(), 'user_agent' => mb_substr(Request::userAgent(), 0, 255)], 'id = ?', [(int)$row['id']]);
        self::setCookie($raw, $exp);
        $_SESSION['_rm'] = $m[1];
        return $u;
    }

    /** Called on every authenticated request: keeps the cookie and DB row 30 days ahead (at most once a day) */
    public static function touch(): void
    {
        $sel = (string)($_SESSION['_rm'] ?? '');
        $raw = (string)($_COOKIE[self::cookieName()] ?? '');
        if ($sel === '' || $raw === '' || !str_starts_with($raw, $sel . ':')) return;
        if ((int)($_SESSION['_rm_touch'] ?? 0) > time() - 86400) return;
        $_SESSION['_rm_touch'] = time();
        $exp = time() + self::lifetime();
        try {
            DB::update(self::TABLE, ['last_used_at' => now(), 'expires_at' => date('Y-m-d H:i:s', $exp)], 'selector = ?', [$sel]);
        } catch (\Throwable) { return; }
        self::setCookie($raw, $exp);
    }

    /** Sign-out on this device */
    public static function forgetCurrent(): void
    {
        $raw = (string)($_COOKIE[self::cookieName()] ?? '');
        if (preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
            try { DB::delete(self::TABLE, 'selector = ?', [$m[1]]); } catch (\Throwable) {}
        }
        self::forgetCookie();
    }

    /** Sign out every remembered device of a user (password changed, account disabled…). $keepCurrent keeps this device. */
    public static function forgetUser(int $userId, bool $keepCurrent = false): void
    {
        try {
            $sel = $keepCurrent ? (string)($_SESSION['_rm'] ?? '') : '';
            if ($sel !== '') DB::delete(self::TABLE, 'user_id = ? AND selector <> ?', [$userId, $sel]);
            else DB::delete(self::TABLE, 'user_id = ?', [$userId]);
        } catch (\Throwable) {}
    }

    public static function cleanup(): int
    {
        try { return DB::delete(self::TABLE, 'expires_at < ?', [now()]); } catch (\Throwable) { return 0; }
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) return;
        setcookie(self::cookieName(), $value, [
            'expires' => $expires, 'path' => base_path_uri() ?: '/',
            'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE[self::cookieName()] = $value;
    }

    private static function forgetCookie(): void
    {
        unset($_SESSION['_rm'], $_SESSION['_rm_touch']);
        if (!isset($_COOKIE[self::cookieName()])) return;
        unset($_COOKIE[self::cookieName()]);
        if (headers_sent()) return;
        setcookie(self::cookieName(), '', ['expires' => time() - 42000, 'path' => base_path_uri() ?: '/', 'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** Same DDL as the migration — so sign-in keeps working even if the migration has not been run yet */
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        DB::pdo()->exec(self::ddl());
        $done = true;
    }

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            selector CHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            user_agent VARCHAR(255) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uq_rt_selector (selector),
            KEY idx_rt_user (user_id),
            KEY idx_rt_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
}
