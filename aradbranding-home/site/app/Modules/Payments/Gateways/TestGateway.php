<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Core\Env;

/**
 * Simulated gateway for testing the whole flow without real money.
 *
 * Fail-closed: it works ONLY when BOTH are true
 *   - PAYMENT_TEST_GATEWAY=true in .env, and
 *   - APP_ENV is not "production".
 * So a forgotten PAYMENT_TEST_GATEWAY=true on the live site can never hand out free Stars.
 */
final class TestGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'test';
    }

    public function available(): bool
    {
        return self::enabled();
    }

    /** Single source of truth for the controller, the service and the health check. */
    public static function enabled(): bool
    {
        if (Env::get('PAYMENT_TEST_GATEWAY', false) !== true) {
            return false;
        }
        return strtolower((string) Env::get('APP_ENV', 'production')) !== 'production';
    }

    /** True when .env still asks for the test gateway on a production site (shown as a health warning). */
    public static function misconfigured(): bool
    {
        return Env::get('PAYMENT_TEST_GATEWAY', false) === true
            && strtolower((string) Env::get('APP_ENV', 'production')) === 'production';
    }

    public function start(array $payment, string $callbackUrl): array
    {
        $authority = 'TEST-' . strtoupper(bin2hex(random_bytes(8)));
        return ['ok' => true, 'authority' => $authority, 'redirect' => '/payments/test?authority=' . $authority . '&cb=' . rawurlencode($callbackUrl)];
    }

    public function verify(array $payment, array $callback): array
    {
        if (!$this->available()) {
            return ['status' => 'failed', 'error' => 'test gateway disabled'];
        }
        if (!empty($callback['_reconcile'])) {
            return ['status' => 'canceled']; // an abandoned test payment is never "paid" by the cron
        }
        if (($callback['Authority'] ?? '') !== $payment['authority']) {
            return ['status' => 'failed', 'error' => 'authority mismatch'];
        }
        return ($callback['Status'] ?? '') === 'OK'
            ? ['status' => 'paid', 'ref' => 'T' . time() . random_int(100, 999), 'card' => '6037-****-****-0000']
            : ['status' => 'canceled'];
    }
}
