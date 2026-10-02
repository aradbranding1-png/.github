<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Db\Connection;
use App\Core\Session\Session;

/** Current user for this request. One primary-key read, memoised. */
final class Auth
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_RESTRICTED = 2;
    public const STATUS_SUSPENDED = 3;
    public const STATUS_BANNED = 4;
    public const STATUS_DELETED = 9;

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(private Connection $db)
    {
    }

    public function id(): ?int
    {
        return Session::active() ? Session::userId() : null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $id = $this->id();
            if ($id !== null) {
                $this->user = $this->db->first(
                    'SELECT u.id, u.public_id, u.handle, u.first_name, u.last_name, u.email, u.phone_cc, u.phone,
                            u.country_id, u.language_id, u.avatar_path, u.status, u.created_at, u.last_active_at, u.business_verified_at,
                            c.code AS country_code, l.code AS language_code,
                            COALESCE(uc.unread_letters, 0) AS unread_letters,
                            COALESCE(uc.unread_notifications, 0) AS unread_notifications,
                            (SELECT COUNT(*) FROM thread_participants tp WHERE tp.user_id = u.id AND tp.folder = 1 AND tp.thread_type = 1 AND tp.unread_count > 0) AS unread_private,
                            (SELECT COUNT(*) FROM announcements a WHERE a.deleted_at IS NULL AND a.id > COALESCE(uc.last_announcement_id, 0)) AS unread_official
                     FROM users u
                     JOIN countries c ON c.id = u.country_id
                     JOIN languages l ON l.id = u.language_id
                     LEFT JOIN user_counters uc ON uc.user_id = u.id
                     WHERE u.id = ? AND u.deleted_at IS NULL',
                    [$id]
                );
            }
        }
        return $this->user;
    }

    public function login(int $userId, bool $remember): void
    {
        Session::regenerate();
        Session::put('_uid', $userId);
        Session::put('_auth_at', time());
        $this->user = null;
        $this->loaded = false;

        if ($remember) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => time() + (int) \App\Core\Env::get('SESSION_LIFETIME', 1_209_600),
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (Session::active()) {
            session_regenerate_id(true);
        }
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $this->user = null;
        $this->loaded = true;
    }

    /** Re-authentication window for sensitive admin actions. */
    public function recentlyAuthenticated(int $seconds = 300): bool
    {
        return time() - (int) Session::get('_auth_at', 0) <= $seconds;
    }
}
