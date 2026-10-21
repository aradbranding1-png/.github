<?php

declare(strict_types=1);

namespace App\Core\Auth;

/** Argon2id when the PHP build supports it, bcrypt otherwise. */
final class Password
{
    public static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algo());
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /** Burns the same time as a real check when the account does not exist. */
    public static function dummyVerify(string $plain): void
    {
        static $dummy = null;
        $dummy ??= self::hash('timing-equaliser-' . bin2hex(random_bytes(4)));
        password_verify($plain, $dummy);
    }
}
