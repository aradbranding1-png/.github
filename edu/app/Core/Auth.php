<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id <= 0) return null;
        $u = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$u || $u['status'] !== 'active') {
            if (!empty($_SESSION['imp_root'])) { self::stopImpersonation(); return self::user(); }
            unset($_SESSION['uid']);
            return null;
        }
        // Bind session to user agent fingerprint to limit session hijacking
        if (($_SESSION['_ua'] ?? '') !== hash('sha256', Request::userAgent())) {
            Session::destroy();
            return null;
        }
        self::$user = $u;
        return $u;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function refresh(): void
    {
        self::$loaded = false;
        self::$user = null;
    }

    public static function findByIdentifier(string $identifier): ?array
    {
        return self::candidates($identifier)[0] ?? null;
    }

    /**
     * Every account the typed identifier can point to (mobile in any stored form, email, username).
     * Duplicate accounts (e.g. an imported «912…» and a registered «0912…») are all returned,
     * so the password is checked against each one instead of silently picking the wrong account.
     */
    public static function candidates(string $identifier): array
    {
        $identifier = normalize_input($identifier);
        if ($identifier === '') return [];
        $rows = [];
        $mobiles = self::mobileVariants($identifier);
        if ($mobiles) {
            $in = implode(',', array_fill(0, count($mobiles), '?'));
            foreach (DB::all("SELECT * FROM users WHERE deleted_at IS NULL AND mobile IN ($in) ORDER BY (status = 'active') DESC, (password_hash IS NOT NULL) DESC, id", $mobiles) as $r) $rows[(int)$r['id']] = $r;
        }
        $low = mb_strtolower($identifier);
        foreach (DB::all("SELECT * FROM users WHERE deleted_at IS NULL AND (LOWER(email) = ? OR LOWER(username) = ?) ORDER BY (username = ?) DESC, id", [$low, $low, $identifier]) as $r) $rows[(int)$r['id']] ??= $r;
        return array_values($rows);
    }

    /**
     * Checks identifier + password.
     * @return array{user: ?array, reason: string}  reason: ok | not_found | no_password | wrong_password
     */
    public static function attempt(string $identifier, string $password): array
    {
        $cands = self::candidates($identifier);
        if (!$cands) return ['user' => null, 'reason' => 'not_found'];
        foreach ($cands as $u) {
            if (self::verifyPassword($u, $password)) return ['user' => DB::find('users', (int)$u['id']) ?? $u, 'reason' => 'ok'];
        }
        $withPw = array_values(array_filter($cands, fn($u) => !empty($u['password_hash'])));
        return ['user' => $withPw[0] ?? $cands[0], 'reason' => $withPw ? 'wrong_password' : 'no_password'];
    }

    /** Rate-limit key for an identifier — the same person typing 0912…, 912… or +98912… shares one counter */
    public static function loginKey(string $identifier): string
    {
        $i = normalize_input($identifier);
        $c = canonical_mobile($i);
        return 'login-id:' . mb_strtolower($c !== '' && preg_match('/^09\d{9}$/', $c) ? $c : $i);
    }

    /** Remove any sign-in lock on this account (after an admin resets the password, etc.) */
    public static function unlock(array $user): void
    {
        foreach (array_filter([(string)$user['mobile'], (string)$user['email'], (string)$user['username']]) as $i) RateLimiter::clear(self::loginKey($i));
    }

    /** Minutes left on a sign-in lock for this identifier (0 = not locked) */
    public static function lockedMinutes(string $identifier): int
    {
        $key = substr(hash('sha256', self::loginKey($identifier)), 0, 64);
        $row = DB::one('SELECT hits, reset_at FROM rate_limits WHERE `key` = ?', [$key]);
        if (!$row || (int)$row['hits'] < self::MAX_TRIES || (int)$row['reset_at'] < time()) return 0;
        return max(1, (int)ceil(((int)$row['reset_at'] - time()) / 60));
    }

    public const MAX_TRIES = 8;

    /** All stored forms an Iranian mobile number may have been saved in */
    private static function mobileVariants(string $v): array
    {
        $d = preg_replace('/[\s\-\(\)]/', '', $v);
        if (!preg_match('/^\+?\d{8,15}$/', $d)) return [];
        $out = [$d];
        $n = ltrim($d, '+');
        if (str_starts_with($n, '0098')) $n = substr($n, 4);
        elseif (str_starts_with($n, '98') && strlen($n) === 12) $n = substr($n, 2);
        elseif (str_starts_with($n, '0')) $n = substr($n, 1);
        if (preg_match('/^9\d{9}$/', $n)) array_push($out, '0' . $n, '+98' . $n, '98' . $n, $n);
        return array_values(array_unique($out));
    }

    /**
     * Canonical form a password is stored in: Persian/Arabic digits → Latin, surrounding spaces removed.
     * (Phones often switch the keyboard to Persian digits, which made correct passwords look "changed".)
     */
    public static function canonicalPassword(string $p): string
    {
        $p = strtr($p, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        return trim($p, " \t\n\r\0\x0B\u{00A0}\u{200C}\u{200F}\u{200E}");
    }

    /** Same keystrokes typed while the keyboard was on the Persian layout → the Latin characters intended */
    private static function fromPersianLayout(string $p): string
    {
        static $map = [
            'ض' => 'q', 'ص' => 'w', 'ث' => 'e', 'ق' => 'r', 'ف' => 't', 'غ' => 'y', 'ع' => 'u', 'ه' => 'i', 'خ' => 'o', 'ح' => 'p', 'ج' => '[', 'چ' => ']',
            'ش' => 'a', 'س' => 's', 'ی' => 'd', 'ي' => 'd', 'ب' => 'f', 'ل' => 'g', 'ا' => 'h', 'ت' => 'j', 'ن' => 'k', 'م' => 'l', 'ک' => ';', 'ك' => ';', 'گ' => "'",
            'ظ' => 'z', 'ط' => 'x', 'ز' => 'c', 'ر' => 'v', 'ذ' => 'b', 'د' => 'n', 'پ' => 'm', 'و' => ',',
        ];
        return strtr($p, $map);
    }

    public static function login(array $user, string $method = 'password'): void
    {
        Session::regenerate();
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['_ua'] = hash('sha256', Request::userAgent());
        $_SESSION['login_at'] = time();
        self::refresh();
        DB::run('UPDATE users SET last_login_at = NOW(), first_login_at = COALESCE(first_login_at, NOW()), login_count = login_count + 1 WHERE id = ?', [(int)$user['id']]);
        DB::insert('login_history', ['user_id' => (int)$user['id'], 'method' => $method, 'ip' => Request::ip(), 'user_agent' => Request::userAgent(), 'success' => 1, 'created_at' => now()]);
        Activity::track((int)$user['id'], 'login', null, null, ['method' => $method]);
        Audit::log('auth.login', 'user', (int)$user['id'], 'success', ['method' => $method]);
    }

    public static function logout(): void
    {
        if ($u = self::user()) Audit::log('auth.logout', 'user', (int)$u['id']);
        if (!empty($_SESSION['imp_log'])) {
            DB::run('UPDATE impersonation_logs SET ended_at = NOW() WHERE id = ? AND ended_at IS NULL', [(int)$_SESSION['imp_log']]);
        }
        Session::destroy();
        self::refresh();
    }

    public static function failed(string $identifier, ?int $userId, string $reason = 'wrong_password'): void
    {
        $row = ['user_id' => $userId, 'method' => 'password', 'ip' => Request::ip(), 'user_agent' => mb_substr(Request::userAgent(), 0, 255), 'success' => 0, 'identifier' => mb_substr($identifier, 0, 100), 'created_at' => now()];
        try { DB::insert('login_history', $row + ['reason' => $reason]); } catch (\Throwable) { DB::insert('login_history', $row); }
        Audit::log('auth.login_failed', 'user', $userId, 'fail', ['identifier' => mb_substr($identifier, 0, 100), 'reason' => $reason]);
    }

    // ------------------------------------------------------------------ Impersonation (root only)

    public static function isImpersonating(): bool
    {
        return !empty($_SESSION['imp_root']);
    }

    public static function impersonator(): ?array
    {
        return self::isImpersonating() ? DB::find('users', (int)$_SESSION['imp_root']) : null;
    }

    public static function impersonate(array $target): void
    {
        $root = self::user();
        if (!$root || !Gate::isRoot($root) || self::isImpersonating()) throw new HttpException(403);
        if ((int)$target['id'] === (int)$root['id'] || (int)$target['is_root'] === 1) throw new HttpException(400, 'امکان ورود به این حساب وجود ندارد.');
        $logId = DB::insert('impersonation_logs', ['root_id' => (int)$root['id'], 'target_id' => (int)$target['id'], 'started_at' => now(), 'ip' => Request::ip(), 'user_agent' => Request::userAgent()]);
        Audit::log('impersonate.start', 'user', (int)$target['id'], 'success', ['log_id' => $logId]);
        Session::regenerate();
        $_SESSION['imp_root'] = (int)$root['id'];
        $_SESSION['imp_log'] = $logId;
        $_SESSION['uid'] = (int)$target['id'];
        self::refresh();
        Gate::flush();
    }

    public static function stopImpersonation(): void
    {
        $rootId = (int)($_SESSION['imp_root'] ?? 0);
        $logId = (int)($_SESSION['imp_log'] ?? 0);
        $targetId = (int)($_SESSION['uid'] ?? 0);
        if (!$rootId) return;
        DB::run('UPDATE impersonation_logs SET ended_at = NOW(), duration_sec = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ?', [$logId]);
        Session::regenerate();
        unset($_SESSION['imp_root'], $_SESSION['imp_log']);
        $_SESSION['uid'] = $rootId;
        self::refresh();
        Gate::flush();
        Audit::log('impersonate.stop', 'user', $targetId, 'success', ['log_id' => $logId]);
    }

    public static function hashPassword(string $password): string
    {
        $password = self::canonicalPassword($password);
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public static function verifyPassword(array $user, string $password): bool
    {
        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || $password === '') return false;
        // exact input first, then the same keystrokes with Persian digits / surrounding spaces / Persian keyboard layout
        $canon = self::canonicalPassword($password);
        $tries = array_values(array_unique([$password, $canon, self::canonicalPassword(self::fromPersianLayout($password))]));
        $matched = null;
        foreach ($tries as $t) {
            if ($t !== '' && password_verify($t, $hash)) { $matched = $t; break; }
        }
        if ($matched === null) return false;
        // store the canonical form so the account keeps working whatever keyboard the user has next time
        $store = self::canonicalPassword($matched);
        if ($store !== '' && ($store !== $matched || password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT))) {
            DB::update('users', ['password_hash' => self::hashPassword($store)], 'id = ?', [(int)$user['id']]);
        }
        return true;
    }

    public static function passwordStrongEnough(string $p): bool
    {
        $p = self::canonicalPassword($p);
        return mb_strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
    }
}
