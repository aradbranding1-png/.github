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
                    throw new ValidationFailed(['email' => t('با این ایمیل قبلاً حساب ساخته شده است. وارد شوید.')]);
                }
                if (str_contains($msg, 'uq_phone')) {
                    throw new ValidationFailed(['phone' => t('این شماره تلفن قبلاً ثبت شده است.')]);
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
        $email = mb_strtolower(Str::cleanEmail($email));
        $cols = 'id, password_hash, status, suspended_until, suspended_until <= NOW(3) AS suspension_over, status_reason, locked_until, locked_until > NOW(3) AS is_locked';
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
        $generic = t('ایمیل/شماره موبایل یا رمز عبور درست نیست.');

        if ($row === null) {
            Password::dummyVerify($password);
            $this->log(null, $email, $ip, 'unknown');
            return ['ok' => false, 'error' => $generic];
        }
        $userId = (int) $row['id'];

        if ((int) $row['is_locked'] === 1) {
            $this->log($userId, $email, $ip, 'locked');
            return ['ok' => false, 'error' => t('به دلیل تلاش‌های ناموفق زیاد، ورود به این حساب :n دقیقه قفل شده است.', ['n' => fa_num(self::LOCK_MINUTES)])];
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

        $rehash = Password::needsRehash((string) $row['password_hash']) ? Password::hash($password) : null;
        return $this->finish($row, $email, $ip, 'success', $rehash);
    }

    /**
     * Sign-in with a verified Google account: the bound Google id first, otherwise the one account whose profile
     * Gmail (or primary e-mail) is this address and which has no other Google account bound — that id is bound now.
     * A password lock does not apply: Google already proved who this is (this is the "forgot my password" path).
     *
     * @return array{ok: bool, user_id?: int, error?: string}
     */
    public function attemptGoogle(string $sub, string $email, string $ip): array
    {
        $cols = 'id, status, suspended_until, suspended_until <= NOW(3) AS suspension_over, status_reason';
        $row = $this->db->first("SELECT {$cols} FROM users WHERE google_sub = ? AND deleted_at IS NULL", [$sub]);
        if ($row === null) {
            $rows = $this->db->select(
                "SELECT {$cols} FROM users WHERE deleted_at IS NULL AND google_sub IS NULL AND (google_email = ? OR email = ?) LIMIT 2",
                [$email, $email]
            );
            if ($rows === [] && str_ends_with($email, '@gmail.com')) {
                // An account opened with the same Gmail written differently (Ali.Reza@gmail.com, alireza+shop@googlemail.com).
                $rows = $this->db->select(
                    "SELECT {$cols} FROM users WHERE deleted_at IS NULL AND google_sub IS NULL
                        AND (email LIKE '%@gmail.com' OR email LIKE '%@googlemail.com')
                        AND CONCAT(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(email, '@', 1), '+', 1), '.', ''), '@gmail.com') = ? LIMIT 2",
                    [$email]
                );
            }
            if ($rows === []) {
                // No account yet: the controller opens the short sign-up step (AuthController::showGoogleRegister).
                $this->log(null, $email, $ip, 'google_new');
                return ['ok' => false, 'unknown' => true, 'error' => ''];
            }
            if (count($rows) !== 1) {
                $this->log(null, $email, $ip, 'google_unknown');
                return ['ok' => false, 'error' => t('این جیمیل در بیش از یک حساب ثبت شده است. برای ورود با گوگل با پشتیبانی آراد برندینگ تماس بگیرید.')];
            }
            $row = $rows[0];
            $this->db->exec('UPDATE users SET google_sub = ?, google_email = ?, google_linked_at = NOW(3), updated_at = NOW(3) WHERE id = ? AND google_sub IS NULL',
                [$sub, $email, (int) $row['id']]);
        }
        return $this->finish($row, $email, $ip, 'google');
    }

    /**
     * New account from a verified Google sign-in. The address is Google's (already verified), the password a random
     * one the member never needs: they sign in with Google, and can set a password in Account › Security.
     * @param array<string, mixed> $d validated: first_name, last_name, country_id, language_id, phone_cc, phone
     * @throws ValidationFailed
     */
    public function registerGoogle(array $d, string $sub, string $email, ?array $avatar): int
    {
        if ($this->db->scalar('SELECT 1 FROM users WHERE google_sub = ? AND deleted_at IS NULL', [$sub]) !== null) {
            throw new ValidationFailed(['google' => t('با این حساب گوگل قبلاً حساب ساخته شده است. با «ورود با گوگل» وارد شوید.')]);
        }
        $d['email'] = $email;
        $d['password'] = bin2hex(random_bytes(24));
        try {
            $userId = $this->register($d, $avatar);
        } catch (ValidationFailed $e) {
            if (isset($e->errors['email'])) {
                throw new ValidationFailed(['google' => t('با جیمیل :email قبلاً حساب ساخته شده است. با «ورود با گوگل» وارد شوید.', ['email' => $email])]);
            }
            throw $e;
        }
        $this->db->exec(
            'UPDATE users SET google_sub = ?, google_email = ?, google_linked_at = NOW(3), email_verified_at = NOW(3), updated_at = NOW(3) WHERE id = ?',
            [$sub, $email, $userId]
        );
        return $userId;
    }

    /** Binds a verified Google account to a signed-in member. Returns an error message, or null on success. */
    public function linkGoogle(int $userId, string $sub, string $email): ?string
    {
        $owner = $this->db->scalar('SELECT id FROM users WHERE google_sub = ? AND deleted_at IS NULL', [$sub]);
        if ($owner !== null && (int) $owner !== $userId) {
            return t('این حساب گوگل به حساب دیگری در سامانه وصل است.');
        }
        $this->db->exec('UPDATE users SET google_sub = ?, google_email = ?, google_linked_at = NOW(3), updated_at = NOW(3) WHERE id = ?',
            [$sub, $email, $userId]);
        return null;
    }

    /** New password without the current one (right after a Google sign-in). Signs out every other device. */
    public function setPassword(int $userId, string $new): void
    {
        $this->db->exec('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL, updated_at = NOW(3) WHERE id = ?', [Password::hash($new), $userId]);
        \App\Core\Container::instance()->get(\App\Core\Session\SessionStore::class)->revokeUser($userId);
    }

    /**
     * Account status checks shared by every sign-in method, then the success bookkeeping.
     *
     * @param array<string, mixed> $row id, status, suspended_until, suspension_over, status_reason
     * @return array{ok: bool, user_id?: int, error?: string}
     */
    private function finish(array $row, string $email, string $ip, string $result, ?string $rehash = null): array
    {
        $userId = (int) $row['id'];
        // A timed suspension lifts itself at the first sign-in after it ends.
        if ((int) $row['status'] === Auth::STATUS_SUSPENDED && (int) ($row['suspension_over'] ?? 0) === 1) {
            $this->db->exec('UPDATE users SET status = ?, suspended_until = NULL, status_reason = NULL, updated_at = NOW(3) WHERE id = ? AND status = ?',
                [Auth::STATUS_ACTIVE, $userId, Auth::STATUS_SUSPENDED]);
            $row['status'] = Auth::STATUS_ACTIVE;
            try {
                $this->restoreFeed($userId);
            } catch (\Throwable) {
                // The feed is rebuilt on the next publish; signing in must not fail because of it.
            }
        }
        if (in_array((int) $row['status'], [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED, Auth::STATUS_DELETED], true)) {
            $this->log($userId, $email, $ip, 'blocked');
            $why = trim((string) ($row['status_reason'] ?? ''));
            $msg = (int) $row['status'] === Auth::STATUS_SUSPENDED
                ? ($row['suspended_until'] ? t('این حساب تا :date معلق شده است.', ['date' => fa_date((string) $row['suspended_until'])]) : t('این حساب معلق شده است.'))
                : t('این حساب مسدود شده است.');
            return ['ok' => false, 'error' => $msg . ($why !== '' ? ' ' . t('دلیل: :reason.', ['reason' => $why]) : '') . ' ' . t('برای پیگیری با پشتیبانی آراد برندینگ تماس بگیرید.')];
        }

        $this->db->exec(
            'UPDATE users SET failed_logins = 0, locked_until = NULL, last_active_at = NOW(3),
                    password_hash = COALESCE(?, password_hash)
             WHERE id = ?',
            [$rehash, $userId]
        );
        $this->log($userId, $email, $ip, $result);
        (new \App\Modules\Analytics\ActiveDays($this->db))->touch($userId);
        return ['ok' => true, 'user_id' => $userId];
    }

    public function changePassword(int $userId, string $current, string $new): ?string
    {
        $hash = (string) $this->db->scalar('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!Password::verify($current, $hash)) {
            return t('رمز عبور فعلی درست نیست.');
        }
        $this->db->exec('UPDATE users SET password_hash = ?, updated_at = NOW(3) WHERE id = ?', [Password::hash($new), $userId]);
        // Sign out every other device.
        \App\Core\Container::instance()->get(\App\Core\Session\SessionStore::class)->revokeUser((int) $userId);
        return null;
    }

    /** Stored without the national trunk prefix: 0912… with +98 → 912… */
    public static function normalizePhone(string $phone): string
    {
        return ltrim(preg_replace('/\D+/', '', Str::latinDigits($phone)) ?? '', '0');
    }

    /** Published proposals return to the feed when an account becomes active again. */
    private function restoreFeed(int $userId): void
    {
        $container = \App\Core\Container::instance();
        if ($container === null) {
            return;
        }
        $proposals = $container->get(\App\Modules\Proposals\ProposalService::class);
        foreach ($this->db->select('SELECT id FROM proposals WHERE user_id = ? AND status = 2 AND published_at IS NOT NULL AND deleted_at IS NULL', [$userId]) as $p) {
            $proposals->syncFeed((int) $p['id']);
        }
    }

    private function log(?int $userId, string $email, string $ip, string $result): void
    {
        $this->db->exec(
            'INSERT INTO login_logs (user_id, email_hash, ip, result, created_at) VALUES (?, ?, ?, ?, NOW(3))',
            [$userId, hash('sha256', $email, true), @inet_pton($ip) ?: null, $result]
        );
    }
}
