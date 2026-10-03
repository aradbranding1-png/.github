<?php

declare(strict_types=1);

namespace App\Modules\Trust;

use App\Core\Auth\Auth;
use App\Core\Db\Connection;
use App\Core\Settings\Settings;
use App\Core\Support\Ulid;
use App\Modules\Users\ValidationFailed;

/**
 * Trust & Safety: abuse reports, member-to-member blocks and the "may this account act?" rule.
 *
 * - A member reports a trader, a business page, a proposal or a single letter message; one report per target per
 *   reporter (a second report updates the first while it is still open). Daily cap per reporter.
 * - A block is stored once (blocker → blocked) and works both ways for messaging: neither side can open a letter,
 *   reply, or send a proposal to the other, and public letters skip them.
 * - «محدود» accounts may read but not send or publish.
 */
final class TrustService
{
    public const T_USER = 1, T_PAGE = 2, T_PROPOSAL = 3, T_MESSAGE = 4;
    public const TARGETS = [self::T_USER => 'تاجر', self::T_PAGE => 'صفحه تجاری', self::T_PROPOSAL => 'پیشنهاد', self::T_MESSAGE => 'نامه'];
    public const TARGET_KEYS = ['user' => self::T_USER, 'page' => self::T_PAGE, 'proposal' => self::T_PROPOSAL, 'message' => self::T_MESSAGE];

    public const OPEN = 1, ACTIONED = 2, DISMISSED = 3;
    public const STATUS = [self::OPEN => 'در انتظار بررسی', self::ACTIONED => 'رسیدگی شد', self::DISMISSED => 'بدون تخلف'];

    public const REASONS = [
        'spam' => 'هرزنامه یا تبلیغ ناخواسته',
        'fraud' => 'کلاهبرداری یا فریب',
        'abuse' => 'توهین، تهدید یا آزار',
        'fake' => 'هویت یا اطلاعات جعلی',
        'illegal' => 'کالا یا خدمات غیرمجاز',
        'other' => 'سایر',
    ];

    /** Moderation outcomes recorded on a report. */
    public const ACTIONS = [
        'dismiss' => 'بدون تخلف',
        'warn' => 'اخطار به کاربر',
        'hide' => 'پنهان‌کردن محتوا',
        'restrict' => 'محدودکردن حساب',
        'suspend' => 'تعلیق موقت حساب',
        'ban' => 'مسدودکردن حساب',
    ];

    public function __construct(private Connection $db, private Settings $settings)
    {
    }

    // ---------- reports ----------

    /**
     * @throws ValidationFailed
     * @return bool true when a new report was filed, false when an open one was updated
     */
    public function report(int $reporterId, int $type, int $targetId, string $reason, string $details): bool
    {
        if (!isset(self::REASONS[$reason])) {
            throw new ValidationFailed(['reason' => 'دلیل گزارش را انتخاب کنید.']);
        }
        $details = trim($details);
        if (mb_strlen($details) > 1000) {
            throw new ValidationFailed(['details' => 'توضیح حداکثر ۱۰۰۰ نویسه است.']);
        }
        $target = $this->target($reporterId, $type, $targetId);
        if ($target === null) {
            throw new ValidationFailed(['reason' => 'این مورد پیدا نشد یا امکان گزارش آن را ندارید.']);
        }
        $existing = $this->db->first('SELECT id, status FROM abuse_reports WHERE reporter_id = ? AND target_type = ? AND target_id = ?', [$reporterId, $type, $targetId]);
        if ($existing !== null) {
            if ((int) $existing['status'] !== self::OPEN) {
                throw new ValidationFailed(['reason' => 'این مورد را قبلاً گزارش کرده‌اید و بررسی شده است.']);
            }
            $this->db->exec('UPDATE abuse_reports SET reason = ?, details = ? WHERE id = ?', [$reason, $details !== '' ? $details : null, $existing['id']]);
            return false;
        }
        $limit = max(1, (int) $this->settings->get('trust.daily_reports', 20));
        $today = (int) $this->db->scalar('SELECT COUNT(*) FROM abuse_reports WHERE reporter_id = ? AND created_at > NOW(3) - INTERVAL 1 DAY', [$reporterId]);
        if ($today >= $limit) {
            throw new ValidationFailed(['reason' => 'در ۲۴ ساعت حداکثر ' . fa_int($limit) . ' گزارش می‌توانید ثبت کنید.']);
        }
        $this->db->exec(
            'INSERT IGNORE INTO abuse_reports (public_id, reporter_id, target_type, target_id, target_user_id, target_at, reason, details, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))',
            [Ulid::generateBinary(), $reporterId, $type, $targetId, $target['user_id'], $target['at'], $reason, $details !== '' ? $details : null, self::OPEN]
        );
        return true;
    }

    /**
     * The reported item, if the reporter may report it (exists, is not theirs, and — for a letter — they are in the thread).
     * @return array{user_id: int, at: ?string}|null
     */
    public function target(int $reporterId, int $type, int $targetId): ?array
    {
        $row = match ($type) {
            self::T_USER => $this->db->first('SELECT id AS user_id, NULL AS at FROM users WHERE id = ? AND deleted_at IS NULL', [$targetId]),
            self::T_PAGE => $this->db->first('SELECT user_id, NULL AS at FROM pages WHERE id = ? AND deleted_at IS NULL', [$targetId]),
            self::T_PROPOSAL => $this->db->first('SELECT user_id, NULL AS at FROM proposals WHERE id = ? AND deleted_at IS NULL', [$targetId]),
            self::T_MESSAGE => $this->db->first(
                'SELECT m.sender_id AS user_id, m.created_at AS at FROM letter_messages m
                 JOIN thread_participants tp ON tp.thread_id = m.thread_id AND tp.user_id = ? WHERE m.id = ? LIMIT 1',
                [$reporterId, $targetId]
            ),
            default => null,
        };
        if ($row === null || (int) $row['user_id'] === $reporterId) {
            return null;
        }
        return ['user_id' => (int) $row['user_id'], 'at' => $row['at']];
    }

    /** @return list<array<string, mixed>> the member's own reports, newest first */
    public function mine(int $userId, int $limit = 30): array
    {
        return $this->db->select(
            'SELECT r.public_id, r.target_type, r.reason, r.status, r.created_at, r.handled_at,
                    u.first_name, u.last_name, u.handle, p.company_name
               FROM abuse_reports r JOIN users u ON u.id = r.target_user_id LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE r.reporter_id = ? ORDER BY r.id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        );
    }

    public function openCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM abuse_reports WHERE status = ?', [self::OPEN]);
    }

    // ---------- blocks ----------

    public function block(int $userId, int $blockedId): bool
    {
        if ($userId === $blockedId) {
            return false;
        }
        return $this->db->exec('INSERT IGNORE INTO user_blocks (user_id, blocked_id, created_at) VALUES (?, ?, NOW(3))', [$userId, $blockedId]) > 0;
    }

    public function unblock(int $userId, int $blockedId): void
    {
        $this->db->exec('DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?', [$userId, $blockedId]);
    }

    /** Has $userId blocked $otherId? */
    public function hasBlocked(int $userId, int $otherId): bool
    {
        return $this->db->scalar('SELECT 1 FROM user_blocks WHERE user_id = ? AND blocked_id = ?', [$userId, $otherId]) !== null;
    }

    /** Either side blocked the other: no letters, replies or proposals between them. */
    public function blockedBetween(int $a, int $b): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_id = ?) OR (user_id = ? AND blocked_id = ?) LIMIT 1',
            [$a, $b, $b, $a]
        ) !== null;
    }

    /** @return list<array<string, mixed>> */
    public function blockedList(int $userId): array
    {
        return $this->db->select(
            'SELECT b.blocked_id, b.created_at, u.first_name, u.last_name, u.handle, u.avatar_path, c.code AS country_code, p.company_name
               FROM user_blocks b JOIN users u ON u.id = b.blocked_id JOIN countries c ON c.id = u.country_id
               LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT 200',
            [$userId]
        );
    }

    /** SQL condition (on alias u) excluding members who blocked, or were blocked by, $senderId. */
    public static function notBlockedSql(): string
    {
        return 'NOT EXISTS (SELECT 1 FROM user_blocks ub WHERE (ub.user_id = u.id AND ub.blocked_id = ?) OR (ub.user_id = ? AND ub.blocked_id = u.id))';
    }

    // ---------- account standing ----------

    /**
     * Changes an account's standing with all its side effects: suspended/banned accounts are signed out everywhere and
     * their proposals leave the feed; reactivated accounts get them back. $until only applies to a suspension.
     */
    public function setStatus(array $u, int $status, ?string $until, ?string $reason): void
    {
        $id = (int) $u['id'];
        $off = in_array($status, [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED], true);
        $this->db->exec(
            'UPDATE users SET status = ?, suspended_until = ?, status_reason = ?, updated_at = NOW(3) WHERE id = ?',
            [$status, $status === Auth::STATUS_SUSPENDED ? $until : null, $status === Auth::STATUS_ACTIVE ? null : $reason, $id]
        );
        $c = \App\Core\Container::instance();
        if ($off) {
            $this->db->exec('DELETE FROM sessions WHERE user_id = ?', [$id]);
            $this->db->exec('DELETE f FROM proposal_feed f WHERE f.user_id = ?', [$id]);
            // Personal API keys stop working with the account; they are not revoked, so a lifted suspension restores them.
        }
        if (!$off && in_array((int) $u['status'], [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED], true) && $c !== null) {
            $proposals = $c->get(\App\Modules\Proposals\ProposalService::class);
            foreach ($this->db->select('SELECT id FROM proposals WHERE user_id = ? AND status = 2 AND published_at IS NOT NULL AND deleted_at IS NULL', [$id]) as $p) {
                $proposals->syncFeed((int) $p['id']);
            }
        }
        if ($c !== null) {
            $cache = $c->get(\App\Core\Cache\Cache::class);
            $cache->bump('owner:' . $id);
            if (!empty($u['handle'])) {
                $cache->forget('handle:' . $u['handle']);
            }
        }
    }

    /** @throws ValidationFailed when the account is «محدود» (read-only). */
    public function assertCanAct(array $user, string $field = 'body'): void
    {
        if ((int) ($user['status'] ?? 1) === Auth::STATUS_RESTRICTED) {
            throw new ValidationFailed([$field => 'حساب شما محدود شده است و فعلاً امکان ارسال یا انتشار ندارید. برای پیگیری با پشتیبانی آراد برندینگ تماس بگیرید.']);
        }
    }

    /** @throws ValidationFailed when either side blocked the other. */
    public function assertCanMessage(int $senderId, int $recipientId): void
    {
        if ($this->blockedBetween($senderId, $recipientId)) {
            throw new ValidationFailed(['to' => $this->hasBlocked($senderId, $recipientId)
                ? 'این تاجر را مسدود کرده‌اید. برای ارسال، ابتدا از «حریم و امنیت» رفع مسدودی کنید.'
                : 'امکان ارسال برای این تاجر وجود ندارد.']);
        }
    }
}
