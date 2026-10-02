<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Auth\Auth;
use App\Core\Auth\Password;
use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Storage\ImageUploader;
use App\Core\Support\Str;
use App\Core\Support\Ulid;
use PDOException;

final class AuthService
{
    public const MAX_FAILED = 10;
    public const LOCK_MINUTES = 15;

    public function __construct(
        private Connection $db,
        private Outbox $outbox,
        private ImageUploader $images,
    ) {
    }

    /**
     * @param array<string, mixed> $d validated: first_name, last_name, email, password, country_id, language_id, phone_cc, phone
     * @param array|null $avatar $_FILES entry
     * @throws ValidationFailed
     */
    public function register(array $d, ?array $avatar): int
    {
        $avatarPath = null;
        if ($avatar !== null) {
            try {
                $avatarPath = $this->images->store($avatar, 'avatar');
            } catch (\App\Core\Storage\UploadException $e) {
                throw new ValidationFailed(['avatar' => $e->getMessage()]);
            }
        }

        try {
            return $this->db->transaction(function (Connection $db) use ($d, $avatarPath): int {
                $userId = $db->insert(
                    'INSERT INTO users (public_id, first_name, last_name, search_name, email, phone_cc, phone, password_hash,
                                        country_id, language_id, avatar_path, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(3), NOW(3))',
                    [
                        Ulid::generateBinary(),
                        $d['first_name'],
                        $d['last_name'],
                        Str::normalize($d['first_name'] . ' ' . $d['last_name']),
                        $d['email'],
                        $d['phone_cc'],
                        self::normalizePhone((string) $d['phone']),
                        Password::hash((string) $d['password']),
                        $d['country_id'],
                        $d['language_id'],
                        $avatarPath,
                    ]
                );
                $db->exec('INSERT INTO user_profiles (user_id, updated_at) VALUES (?, NOW(3))', [$userId]);
                $db->exec('INSERT INTO user_counters (user_id, updated_at) VALUES (?, NOW(3))', [$userId]);
                $db->exec('INSERT INTO wallets (user_id, updated_at) VALUES (?, NOW(3))', [$userId]);
                $db->exec(
                    "INSERT INTO user_roles (user_id, role_id, created_at) SELECT ?, id, NOW(3) FROM roles WHERE slug = 'customer'",
                    [$userId]
                );
                $this->outbox->record('user_registered', $userId, null, null, ['country_id' => $d['country_id']]);
                return $userId;
            });
        } catch (PDOException $e) {
            $this->images->delete($avatarPath);
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'uq_email')) {
                    throw new ValidationFailed(['email' => 'با این ایمیل قبلاً حساب ساخته شده است. وارد شوید.']);
                }
                if (str_contains($msg, 'uq_phone')) {
                    throw new ValidationFailed(['phone' => 'این شماره تلفن قبلاً ثبت شده است.']);
                }
            }
            throw $e;
        }
    }

    /**
     * @return array{ok: bool, user_id?: int, error?: string}
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $cols = 'id, password_hash, status, locked_until, locked_until > NOW(3) AS is_locked';
        if (str_contains($email, '@')) {
            $row = $this->db->first("SELECT {$cols} FROM users WHERE email = ? AND deleted_at IS NULL", [$email]);
        } else {
            // Mobile number instead of e-mail (accounts opened for customers by Arad Contact sign in this way):
            // 0912…, 912…, 98912…, +98 912… or 0098912…. An ambiguous national number (same digits in two
            // countries) matches nobody unless it is written with its country code.
            $digits = preg_replace('/\D+/', '', Str::latinDigits($email)) ?? '';
            $full = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
            $rows = strlen($digits) < 6 ? [] : $this->db->select(
                "SELECT {$cols}, CONCAT(phone_cc, phone) = ? AS exact FROM users
                  WHERE deleted_at IS NULL AND (CONCAT(phone_cc, phone) = ? OR phone = ?) LIMIT 3",
                [$full, $full, self::normalizePhone($digits)]
            );
            $exact = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['exact'] === 1));
            $row = count($exact) === 1 ? $exact[0] : (count($rows) === 1 ? $rows[0] : null);
        }
        $generic = 'ایمیل/شماره موبایل یا رمز عبور درست نیست.';

        if ($row === null) {
            Password::dummyVerify($password);
            $this->log(null, $email, $ip, 'unknown');
            return ['ok' => false, 'error' => $generic];
        }
        $userId = (int) $row['id'];

        if ((int) $row['is_locked'] === 1) {
            $this->log($userId, $email, $ip, 'locked');
            return ['ok' => false, 'error' => 'به دلیل تلاش‌های ناموفق زیاد، ورود به این حساب ' . fa_num(self::LOCK_MINUTES) . ' دقیقه قفل شده است.'];
        }

        if (!Password::verify($password, (string) $row['password_hash'])) {
            // Lock column first: both expressions read the pre-update failed_logins value.
            $this->db->exec(
                'UPDATE users SET
                    locked_until = IF(failed_logins + 1 >= ?, NOW(3) + INTERVAL ? MINUTE, locked_until),
                    failed_logins = IF(failed_logins + 1 >= ?, 0, failed_logins + 1)
                 WHERE id = ?',
                [self::MAX_FAILED, self::LOCK_MINUTES, self::MAX_FAILED, $userId]
            );
            $this->log($userId, $email, $ip, 'bad_password');
            return ['ok' => false, 'error' => $generic];
        }

        if (in_array((int) $row['status'], [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED, Auth::STATUS_DELETED], true)) {
            $this->log($userId, $email, $ip, 'blocked');
            return ['ok' => false, 'error' => 'این حساب غیرفعال شده است. با پشتیبانی تماس بگیرید.'];
        }

        $rehash = Password::needsRehash((string) $row['password_hash']) ? Password::hash($password) : null;
        $this->db->exec(
            'UPDATE users SET failed_logins = 0, locked_until = NULL, last_active_at = NOW(3),
                    password_hash = COALESCE(?, password_hash)
             WHERE id = ?',
            [$rehash, $userId]
        );
        $this->log($userId, $email, $ip, 'success');
        return ['ok' => true, 'user_id' => $userId];
    }

    public function changePassword(int $userId, string $current, string $new): ?string
    {
        $hash = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!Password::verify($current, $hash)) {
            return 'رمز عبور فعلی درست نیست.';
        }
        $this->db->exec('UPDATE users SET password_hash = ?, updated_at = NOW(3) WHERE id = ?', [Password::hash($new), $userId]);
        // Sign out every other device.
        $this->db->exec('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        return null;
    }

    /** Stored without the national trunk prefix: 0912… with +98 → 912… */
    public static function normalizePhone(string $phone): string
    {
        return ltrim(preg_replace('/\D+/', '', Str::latinDigits($phone)) ?? '', '0');
    }

    private function log(?int $userId, string $email, string $ip, string $result): void
    {
        $this->db->exec(
            'INSERT INTO login_logs (user_id, email_hash, ip, result, created_at) VALUES (?, ?, ?, ?, NOW(3))',
            [$userId, hash('sha256', $email, true), @inet_pton($ip) ?: null, $result]
        );
    }
}
