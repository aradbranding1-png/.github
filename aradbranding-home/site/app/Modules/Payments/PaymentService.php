<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Log\Logger;
use App\Core\Support\Ulid;
use App\Modules\Payments\Gateways\PaymentGateway;
use App\Modules\Payments\Gateways\TestGateway;
use App\Modules\Payments\Gateways\ZarinpalGateway;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;
use Throwable;

/**
 * Payment state machine (architecture doc):
 *   created → pending → verifying → paid → credited
 *   pending → canceled | expired ; verifying → failed ; credited → refunded
 * Transitions are guarded with "UPDATE … WHERE status = expected" so only one process wins.
 */
final class PaymentService
{
    public const CREATED = 0, PENDING = 1, VERIFYING = 2, PAID = 3, CREDITED = 4, FAILED = 5, CANCELED = 6, EXPIRED = 7, REFUNDED = 8;

    public const LABELS = [
        self::CREATED => 'ایجادشده', self::PENDING => 'در انتظار پرداخت', self::VERIFYING => 'در حال بررسی',
        self::PAID => 'پرداخت‌شده', self::CREDITED => 'موفق', self::FAILED => 'ناموفق', self::CANCELED => 'لغوشده',
        self::EXPIRED => 'منقضی', self::REFUNDED => 'بازپرداخت‌شده',
    ];

    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(
        private Connection $db,
        private WalletService $wallet,
        private Pricing $pricing,
        private Logger $logger,
    ) {
        $this->gateways = ['zarinpal' => new ZarinpalGateway()];
        if (TestGateway::enabled()) {
            // Not even registered on production: a "test" payment row can never be started or verified there.
            $this->gateways['test'] = new TestGateway();
        }
    }

    /** @return list<array{id: int, code: string, name: string}> gateways that can take payments now */
    public function availableGateways(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT id, code, name FROM payment_gateways WHERE active = 1 ORDER BY priority') as $g) {
            if (isset($this->gateways[$g['code']]) && $this->gateways[$g['code']]->available()) {
                $out[] = ['id' => (int) $g['id'], 'code' => (string) $g['code'], 'name' => (string) $g['name']];
            }
        }
        return $out;
    }

    /**
     * @return array{ok: bool, redirect?: string, error?: string}
     */
    public function start(array $user, int $packageId, string $gatewayCode): array
    {
        $package = null;
        foreach ($this->pricing->packages() as $p) {
            if ($p['id'] === $packageId) {
                $package = $p;
            }
        }
        $gateway = null;
        foreach ($this->availableGateways() as $g) {
            if ($g['code'] === $gatewayCode) {
                $gateway = $g;
            }
        }
        $rate = $this->pricing->rate('IRR');
        if ($package === null || $gateway === null || $rate === null) {
            return ['ok' => false, 'error' => 'بسته یا درگاه انتخاب‌شده معتبر نیست.'];
        }

        $amount = $package['stars'] * $rate['minor_per_star'];
        $publicId = Ulid::generateBinary();
        $uid = strtolower(Ulid::toString($publicId));
        $paymentId = $this->db->insert(
            'INSERT INTO payments (public_id, user_id, gateway_id, currency, amount_minor, stars, bonus_stars, rate_snapshot, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))',
            [$publicId, $user['id'], $gateway['id'], 'IRR', $amount, $package['stars'], $package['bonus_stars'], $rate['minor_per_star'], self::CREATED]
        );
        $this->event($paymentId, null, self::CREATED);

        $callback = rtrim((string) Env::get('APP_URL', ''), '/') . '/payments/callback/' . $gateway['code']
            . '?p=' . $uid . '&s=' . self::sign($uid);
        try {
            $res = $this->gateways[$gateway['code']]->start([
                'public_id' => $uid,
                'amount_minor' => $amount,
                'currency' => 'IRR',
                'description' => 'خرید ' . $package['stars'] . ' Star - سامانه توسعه تجارت',
                'email' => (string) $user['email'],
                'mobile' => '0' . ltrim((string) $user['phone'], '0'),
            ], $callback);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!$res['ok']) {
            $this->transition($paymentId, self::CREATED, self::FAILED, substr((string) ($res['error'] ?? ''), 0, 250));
            $this->logger->error('Payment start failed', ['payment_id' => $paymentId, 'error' => $res['error'] ?? null]);
            return ['ok' => false, 'error' => 'اتصال به درگاه پرداخت برقرار نشد. چند دقیقه دیگر دوباره تلاش کنید.'];
        }
        $this->db->exec('UPDATE payments SET authority = ?, updated_at = NOW(3) WHERE id = ?', [$res['authority'], $paymentId]);
        $this->transition($paymentId, self::CREATED, self::PENDING);
        return ['ok' => true, 'redirect' => (string) $res['redirect']];
    }

    /**
     * Gateway returned the user. Idempotent: repeated callbacks never credit twice.
     * @param array<string, mixed> $query
     * @return string|null payment public id (for the result page), null if invalid
     */
    public function callback(string $gatewayCode, array $query): ?string
    {
        $uid = strtolower((string) ($query['p'] ?? ''));
        if (!Ulid::isValid($uid) || !hash_equals(self::sign($uid), (string) ($query['s'] ?? ''))) {
            return null;
        }
        $payment = $this->find($uid);
        if ($payment === null || $payment['gateway_code'] !== $gatewayCode) {
            return null;
        }
        $this->verify($payment, $query);
        return $uid;
    }

    /** Cron: finish payments whose callback never arrived or whose crediting was interrupted. */
    public function reconcile(): int
    {
        $n = 0;
        foreach ($this->db->select(
            'SELECT p.*, g.code AS gateway_code FROM payments p JOIN payment_gateways g ON g.id = p.gateway_id
             WHERE (p.status = ? AND p.updated_at < NOW(3) - INTERVAL 30 MINUTE)
                OR (p.status = ? AND p.updated_at < NOW(3) - INTERVAL 5 MINUTE)
                OR p.status = ?
             ORDER BY p.id LIMIT 100',
            [self::PENDING, self::VERIFYING, self::PAID]
        ) as $p) {
            $p['id'] = (int) $p['id'];
            if ((int) $p['status'] === self::PAID) {
                $this->credit($p);
            } elseif ((int) $p['status'] === self::VERIFYING) {
                $this->transition($p['id'], self::VERIFYING, self::PENDING, 'verify timeout, retrying');
                $p['status'] = self::PENDING;
                $this->verify($p, ['Authority' => $p['authority'], 'Status' => 'OK', '_reconcile' => true], true);
            } else {
                $this->verify($p, ['Authority' => $p['authority'], 'Status' => 'OK', '_reconcile' => true], true);
            }
            $n++;
        }
        return $n;
    }

    /** @return array<string, mixed>|null */
    public function find(string $uid, ?int $userId = null): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        $row = $this->db->first(
            'SELECT p.*, g.code AS gateway_code, g.name AS gateway_name
             FROM payments p JOIN payment_gateways g ON g.id = p.gateway_id WHERE p.public_id = ?'
                . ($userId !== null ? ' AND p.user_id = ?' : ''),
            $userId !== null ? [Ulid::toBinary($uid), $userId] : [Ulid::toBinary($uid)]
        );
        if ($row !== null) {
            $row['id'] = (int) $row['id'];
            $row['uid'] = strtolower($uid);
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function recentForUser(int $userId, int $limit = 10): array
    {
        return $this->db->select(
            'SELECT p.public_id, p.amount_minor, p.stars, p.bonus_stars, p.status, p.created_at, g.name AS gateway_name
             FROM payments p JOIN payment_gateways g ON g.id = p.gateway_id
             WHERE p.user_id = ? AND p.status <> ? ORDER BY p.id DESC LIMIT ' . $limit,
            [$userId, self::CREATED]
        );
    }

    private function verify(array $payment, array $callback, bool $fromCron = false): void
    {
        // Only one process may move pending → verifying.
        if (!$this->transition((int) $payment['id'], self::PENDING, self::VERIFYING, $fromCron ? 'reconcile' : 'callback')) {
            if ((int) $payment['status'] === self::PAID) {
                $this->credit($payment);
            }
            return;
        }
        $gw = $this->gateways[(string) $payment['gateway_code']] ?? null;
        if ($gw === null) {
            $this->transition((int) $payment['id'], self::VERIFYING, self::FAILED, 'gateway disabled: ' . $payment['gateway_code']);
            return;
        }
        try {
            $result = $gw->verify([
                'amount_minor' => (int) $payment['amount_minor'],
                'currency' => (string) $payment['currency'],
                'authority' => (string) $payment['authority'],
            ], $callback);
        } catch (Throwable $e) {
            $result = ['status' => 'retry', 'error' => $e->getMessage()];
        }

        switch ($result['status']) {
            case 'paid':
                try {
                    $this->db->exec(
                        'UPDATE payments SET status = ?, gateway_ref = ?, card_mask = ?, updated_at = NOW(3) WHERE id = ? AND status = ?',
                        [self::PAID, $result['ref'] ?: null, $result['card'] ?: null, $payment['id'], self::VERIFYING]
                    );
                    $this->event((int) $payment['id'], self::VERIFYING, self::PAID, 'ref ' . ($result['ref'] ?? ''));
                } catch (\PDOException $e) {
                    // Same gateway receipt used twice → never credit; needs finance review.
                    $this->transition((int) $payment['id'], self::VERIFYING, self::FAILED, 'duplicate gateway reference');
                    $this->logger->critical('Duplicate payment reference', ['payment_id' => $payment['id']]);
                    return;
                }
                $payment['status'] = self::PAID;
                $this->credit($payment);
                return;
            case 'canceled':
                $this->transition((int) $payment['id'], self::VERIFYING, $fromCron ? self::EXPIRED : self::CANCELED);
                return;
            case 'retry':
                $this->transition((int) $payment['id'], self::VERIFYING, self::PENDING, 'retry: ' . ($result['error'] ?? ''));
                return;
            default:
                $this->transition((int) $payment['id'], self::VERIFYING, $fromCron ? self::EXPIRED : self::FAILED, substr((string) ($result['error'] ?? ''), 0, 250));
        }
    }

    /** paid → credited, exactly once (idempotency keys per payment). */
    private function credit(array $payment): void
    {
        $id = (int) $payment['id'];
        if (!isset($this->gateways[(string) ($payment['gateway_code'] ?? '')])) {
            // e.g. a leftover "test" payment on production: never turn it into Stars.
            $this->transition($id, self::PAID, self::FAILED, 'gateway disabled: ' . ($payment['gateway_code'] ?? '?'));
            $this->logger->warning('Payment for a disabled gateway was not credited', ['payment_id' => $id, 'gateway' => $payment['gateway_code'] ?? null]);
            return;
        }
        $this->db->transaction(function (Connection $db) use ($payment, $id): void {
            $status = (int) $db->scalar('SELECT status FROM payments WHERE id = ? FOR UPDATE', [$id]);
            if ($status !== self::PAID) {
                return;
            }
            $txId = $this->wallet->credit((int) $payment['user_id'], (int) $payment['stars'], WalletService::T_PURCHASE,
                'purchase', 'payment', $id, 'payment:' . $id);
            if ((int) $payment['bonus_stars'] > 0) {
                $this->wallet->credit((int) $payment['user_id'], (int) $payment['bonus_stars'], WalletService::T_BONUS,
                    'purchase_bonus', 'payment', $id, 'payment-bonus:' . $id);
            }
            $db->exec('UPDATE payments SET status = ?, credited_tx_id = ?, updated_at = NOW(3) WHERE id = ?', [self::CREDITED, $txId, $id]);
            $this->event($id, self::PAID, self::CREDITED);
        });
    }

    private function transition(int $id, int $from, int $to, ?string $note = null): bool
    {
        $changed = $this->db->exec('UPDATE payments SET status = ?, updated_at = NOW(3) WHERE id = ? AND status = ?', [$to, $id, $from]);
        if ($changed === 1) {
            $this->event($id, $from, $to, $note);
            return true;
        }
        return false;
    }

    private function event(int $paymentId, ?int $from, int $to, ?string $note = null): void
    {
        $this->db->exec(
            'INSERT INTO payment_events (payment_id, from_status, to_status, note, created_at) VALUES (?, ?, ?, ?, NOW(3))',
            [$paymentId, $from, $to, $note]
        );
    }

    private static function sign(string $uid): string
    {
        $key = (string) Env::get('SIGNING_KEY', '');
        return substr(hash_hmac('sha256', 'payment:' . $uid, $key), 0, 32);
    }
}
