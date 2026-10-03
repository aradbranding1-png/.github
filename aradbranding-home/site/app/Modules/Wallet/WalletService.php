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
     * How many Stars of a ledger row can still be refunded. Spends: the whole charge; a public-letter reservation:
     * what was actually consumed (reserve − released) once the send has finished. Minus earlier refunds of that row.
     * @return array{row: ?array<string, mixed>, refundable: int, refunded: int, why: ?string}
     */
    public function refundable(int $userId, int $txId, bool $lock = false): array
    {
        $row = $this->db->first('SELECT * FROM wallet_transactions WHERE id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$txId, $userId]);
        if ($row === null || !in_array((int) $row['type'], [self::T_SPEND, self::T_RESERVE], true)) {
            return ['row' => $row, 'refundable' => 0, 'refunded' => 0, 'why' => 'فقط تراکنش‌های مصرف Stars قابل بازپرداخت هستند.'];
        }
        $charged = -(int) $row['amount'];
        if ((int) $row['type'] === self::T_RESERVE) {
            $done = $row['ref_type'] === 'campaign'
                && (int) $this->db->scalar('SELECT status FROM letter_campaigns WHERE id = ?', [(int) $row['ref_id']]) === \App\Modules\Letters\CampaignService::COMPLETED;
            if (!$done) {
                return ['row' => $row, 'refundable' => 0, 'refunded' => 0, 'why' => 'ارسال این نامه هنوز تمام نشده است؛ پس از پایان ارسال قابل بازپرداخت است.'];
            }
            $charged -= (int) $this->db->scalar(
                'SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = ? AND type = ? AND ref_type = ? AND ref_id = ?',
                [$userId, self::T_RELEASE, 'campaign', (int) $row['ref_id']]
            );
        }
        $refunded = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE ref_type = 'refund_of' AND ref_id = ?",
            [$txId]
        );
        return ['row' => $row, 'refundable' => max(0, $charged - $refunded), 'refunded' => $refunded, 'why' => null];
    }

    /**
     * Returns Stars of an earlier spend (whole or part) as a T_REFUND row pointing at it (ref_type 'refund_of').
     * The ledger stays append-only; the per-row cap is checked under the wallet lock, so concurrent refunds cannot
     * return more than was charged. Idempotent with the form token.
     * @throws \DomainException with a Persian message when nothing (or less) can be refunded
     */
    public function refund(int $userId, int $txId, int $stars, int $actorId, string $note, string $idemKey): int
    {
        if ($stars <= 0) {
            throw new \DomainException('مقدار بازپرداخت باید بیشتر از صفر باشد.');
        }
        return $this->db->transaction(function (Connection $db) use ($userId, $txId, $stars, $actorId, $note, $idemKey): int {
            if (!$this->idem->claim('wallet', $idemKey, $userId)) {
                return (int) $this->idem->previous('wallet', $idemKey);
            }
            $balance = $this->lock($userId);
            $r = $this->refundable($userId, $txId, true);
            if ($r['why'] !== null) {
                throw new \DomainException($r['why']);
            }
            if ($stars > $r['refundable']) {
                throw new \DomainException($r['refundable'] === 0
                    ? 'این تراکنش قبلاً کامل بازپرداخت شده است.'
                    : 'حداکثر ' . fa_int($r['refundable']) . ' Star از این تراکنش قابل بازپرداخت است.');
            }
            $db->exec(
                'UPDATE wallets SET balance = balance + ?, lifetime_spent = IF(lifetime_spent > ?, lifetime_spent - ?, 0), updated_at = NOW(3) WHERE user_id = ?',
                [$stars, $stars, $stars, $userId]
            );
            $txRefund = $this->append($userId, self::T_REFUND, $stars, $balance + $stars, 'refund', 'refund_of', $txId, $actorId, $note);
            $this->idem->complete('wallet', $idemKey, $txRefund);
            $this->outbox->record('stars_refunded', $userId, null, $txRefund, ['stars' => $stars, 'of' => $txId]);
            return $txRefund;
        });
    }

    /**
     * Ledger page, newest first, keyset on id.
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function history(int $userId, ?int $beforeId, int $limit = 25): array
    {
        $rows = $this->db->select(
            'SELECT id, type, amount, balance_after, reason, ref_type, ref_id, note, created_at FROM wallet_transactions
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
