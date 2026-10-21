<?php

declare(strict_types=1);

namespace App\Core\Session;

/** Thin static wrapper over $_SESSION. Only valid after the "session" middleware. */
final class Session
{
    public static function active(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Call on login, logout and privilege change to prevent session fixation. */
    public static function regenerate(): void
    {
        if (self::active()) {
            session_regenerate_id(true);
            unset($_SESSION['_csrf']);
        }
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    /** Flash message shown on the next request only (Post/Redirect/Get). */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash_next'][$type] = $message;
    }

    /** @return array<string, string> */
    public static function flashes(): array
    {
        return self::active() ? (array) ($_SESSION['_flash_now'] ?? []) : [];
    }

    /** Called once per request right after session_start(). */
    public static function ageFlash(): void
    {
        $_SESSION['_flash_now'] = $_SESSION['_flash_next'] ?? [];
        unset($_SESSION['_flash_next']);
        if ($_SESSION['_flash_now'] === []) {
            unset($_SESSION['_flash_now']);
        }
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['_uid']) ? (int) $_SESSION['_uid'] : null;
    }
}
