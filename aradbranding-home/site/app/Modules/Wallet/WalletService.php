<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Security\Idempotency;

/**
 * Stars wallet. `wallets.balance` is the current state; `wallet_transactions` is the
 * append-only ledger. Every change: lock row → check → update → ledger → effect → commit.
 */
final class WalletService
{
    public const T_PURCHASE = 1;
    public const T_SPEND = 2;
    public const T_RESERVE = 3;
    public const T_RELEASE = 4;
    public const T_REFUND = 5;
    public const T_BONUS = 6;
    public const T_ADMIN_CREDIT = 7;
    public const T_ADMIN_DEBIT = 8;

    public const TYPE_LABELS = [
        self::T_PURCHASE => 'خرید', self::T_SPEND => 'مصرف', self::T_RESERVE => 'رزرو', self::T_RELEASE => 'بازگشت رزرو',
        self::T_REFUND => 'بازپرداخت', self::T_BONUS => 'هدیه', self::T_ADMIN_CREDIT => 'افزایش توسط مدیر',
        self::T_ADMIN_DEBIT => 'کاهش توسط مدیر',
    ];

    public const REASON_LABELS = [
        'page_view' => 'مشاهده صفحه تجاری', 'proposal_send' => 'ارسال پیشنهاد', 'private_letter' => 'نامه اختصاصی',
        'public_letter' => 'نامه عمومی', 'purchase' => 'خرید Stars', 'purchase_bonus' => 'هدیه بسته خرید',
        'admin' => 'اصلاح توسط مدیر', 'refund' => 'بازپرداخت', 'api_charge' => 'شارژ کیف پول (ثبت سفارش در آراد کانتکت)', 'proposal_publish' => 'انتشار پیشنهاد در فید', 'public_letter_release' => 'بازگشت سهم ارسال‌های تحویل‌نشده',
    ];

    public function __construct(private Connection $db, private Outbox $outbox, private Idempotency $idem)
    {
    }

    public function balance(int $userId): int
    {
        return (int) ($this->db->scalar('SELECT balance FROM wallets WHERE user_id = ?', [$userId]) ?? 0);
    }

    /**
     * Debit $stars. $effect runs inside the same transaction and receives the ledger id.
     * With an idempotency key, a repeated call returns the first transaction id without charging again.
     *
     * @param callable(int $txId): void|null $effect
     * @return int ledger transaction id
     * @throws InsufficientStars
     */
    public function spend(
        int $userId,
        int $stars,
        string $reason,
        ?string $refType,
        ?int $refId,
        ?string $idemKey = null,
        ?callable $effect = null,
    ): int {
        return $this->db->transaction(function (Connection $db) use ($userId, $stars, $reason, $refType, $refId, $idemKey, $effect): int {
            if ($idemKey !== null && !$this->idem->claim('wallet', $idemKey, $userId)) {
                return (int) $this->idem->previous('wallet', $idemKey);
            }
            $balance = $this->lock($userId);
            if ($balance < $stars) {
                throw new InsufficientStars($stars, $balance); // rolls back, including the idempotency claim
            }
            $db->exec(
                'UPDATE wallets SET balance = balance - ?, lifetime_spent = lifetime_spent + ?, updated_at = NOW(3) WHERE user_id = ?',
                [$stars, $stars, $userId]
            );
            $txId = $this->append($userId, self::T_SPEND, -$stars, $balance - $stars, $reason, $refType, $refId);
            if ($effect !== null) {
                $effect($txId);
            }
            if ($idemKey !== null) {
                $this->idem->complete('wallet', $idemKey, $txId);
            }
            $this->outbox->record('stars_spent', $userId, null, $txId, ['stars' => $stars, 'reason' => $reason]);
            return $txId;
        });
    }

    /**
     * Credit $stars (purchase, bonus, refund, admin credit). Idempotent with a key.
     */
    public function credit(
        int $userId,
        int $stars,
        int $type,
        string $reason,
        ?string $refType = null,
        ?int $refId = null,
        ?string $idemKey = null,
        ?int $actorId = null,
        ?string $note = null,
    ): int {
        if ($stars <= 0) {
            throw new \InvalidArgumentException('Credit must be positive');
        }
        return $this->db->transaction(function (Connection $db) use ($userId, $stars, $type, $reason, $refType, $refId, $idemKey, $actorId, $note): int {
            if ($idemKey !== null && !$this->idem->claim('wallet', $idemKey, $userId)) {
                return (int) $this->idem->previous('wallet', $idemKey);
            }
            $balance = $this->lock($userId);
            $bought = $type === self::T_PURCHASE ? $stars : 0;
            $db->exec(
                'UPDATE wallets SET balance = balance + ?, lifetime_bought = lifetime_bought + ?, updated_at = NOW(3) WHERE user_id = ?',
                [$stars, $bought, $userId]
            );
            $txId = $this->append($userId, $type, $stars, $balance + $stars, $reason, $refType, $refId, $actorId, $note);
            if ($idemKey !== null) {
                $this->idem->complete('wallet', $idemKey, $txId);
            }
            return $txId;
        });
    }

    /**
     * Holds Stars for a long-running action (public letter). Balance goes down now;
     * settle() later releases whatever was not used.
     * @param callable(int $txId): void|null $effect
     * @throws InsufficientStars
     */
    public function reserve(int $userId, int $stars, string $reason, string $refType, int $refId, string $idemKey, ?callable $effect = null): int
    {
        return $this->db->transaction(function (Connection $db) use ($userId, $stars, $reason, $refType, $refId, $idemKey, $effect): int {
            if (!$this->idem->claim('wallet', $idemKey, $userId)) {
                return (int) $this->idem->previous('wallet', $idemKey);
            }
            $balance = $this->lock($userId);
            if ($balance < $stars) {
                throw new InsufficientStars($stars, $balance);
            }
            $db->exec(
                'UPDATE wallets SET balance = balance - ?, reserved = reserved + ?, updated_at = NOW(3) WHERE user_id = ?',
                [$stars, $stars, $userId]
            );
            $txId = $this->append($userId, self::T_RESERVE, -$stars, $balance - $stars, $reason, $refType, $refId);
            if ($effect !== null) {
                $effect($txId);
            }
            $this->idem->complete('wallet', $idemKey, $txId);
            return $txId;
        });
    }

    /** Closes a reservation: $spent is consumed, the rest returns to the balance. Idempotent. */
    public function settle(int $userId, int $reserved, int $spent, string $reason, string $refType, int $refId, string $idemKey): void
    {
        $spent = min($spent, $reserved);
        $this->db->transaction(function (Connection $db) use ($userId, $reserved, $spent, $reason, $refType, $refId, $idemKey): void {
            if (!$this->idem->claim('wallet', $idemKey, $userId)) {
                return;
            }
            $balance = $this->lock($userId);
            $release = $reserved - $spent;
            $db->exec(
                'UPDATE wallets SET reserved = IF(reserved > ?, reserved - ?, 0), balance = balance + ?,
                        lifetime_spent = lifetime_spent + ?, updated_at = NOW(3) WHERE user_id = ?',
                [$reserved, $reserved, $release, $spent, $userId]
            );
            if ($release > 0) {
                $txId = $this->append($userId, self::T_RELEASE, $release, $balance + $release, $reason, $refType, $refId);
                $this->idem->complete('wallet', $idemKey, $txId);
            }
        });
    }

    /** Admin debit: never below zero. Optional idempotency key (a double-submitted form debits once). */
    public function adminDebit(int $userId, int $stars, int $actorId, string $note, ?string $idemKey = null): int
    {
        return $this->db->transaction(function (Connection $db) use ($userId, $stars, $actorId, $note, $idemKey): int {
            if ($idemKey !== null && !$this->idem->claim('wallet', $idemKey, $userId)) {
                return (int) $this->idem->previous('wallet', $idemKey);
            }
            $balance = $this->lock($userId);
            if ($balance < $stars) {
                throw new InsufficientStars($stars, $balance);
            }
            $db->exec('UPDATE wallets SET balance = balance - ?, updated_at = NOW(3) WHERE user_id = ?', [$stars, $userId]);
            $txId = $this->append($userId, self::T_ADMIN_DEBIT, -$stars, $balance - $stars, 'admin', null, null, $actorId, $note);
            if ($idemKey !== null) {
                $this->idem->complete('wallet', $idemKey, $txId);
            }
            return $txId;
        });
    }

    /**
     * Ledger page, newest first, keyset on id.
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function history(int $userId, ?int $beforeId, int $limit = 25): array
    {
        $rows = $this->db->select(
            'SELECT id, type, amount, balance_after, reason, note, created_at FROM wallet_transactions
             WHERE user_id = ?' . ($beforeId !== null ? ' AND id < ?' : '') . '
             ORDER BY id DESC LIMIT ' . ($limit + 1),
            $beforeId !== null ? [$userId, $beforeId] : [$userId]
        );
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int) end($rows)['id'];
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /** Nightly reconciliation: wallets whose balance differs from the ledger sum. */
    public function mismatches(int $limit = 100): array
    {
        return $this->db->select(
            'SELECT w.user_id, w.balance, COALESCE(SUM(t.amount), 0) AS ledger
             FROM wallets w LEFT JOIN wallet_transactions t ON t.user_id = w.user_id
             WHERE w.updated_at > NOW(3) - INTERVAL 1 DAY
             GROUP BY w.user_id, w.balance
             HAVING w.balance <> COALESCE(SUM(t.amount), 0)
             LIMIT ' . $limit
        );
    }

    private function lock(int $userId): int
    {
        $this->db->exec('INSERT IGNORE INTO wallets (user_id, updated_at) VALUES (?, NOW(3))', [$userId]);
        return (int) $this->db->scalar('SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
    }

    private function append(int $userId, int $type, int $amount, int $balanceAfter, string $reason, ?string $refType, ?int $refId, ?int $actorId = null, ?string $note = null): int
    {
        return $this->db->insert(
            'INSERT INTO wallet_transactions (user_id, type, amount, balance_after, reason, ref_type, ref_id, actor_id, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))',
            [$userId, $type, $amount, $balanceAfter, $reason, $refType, $refId, $actorId, $note]
        );
    }
}
