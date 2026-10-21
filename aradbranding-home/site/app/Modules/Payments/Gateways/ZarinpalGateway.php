<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Core\Env;

/**
 * Zarinpal REST v4. Amounts in IRR (Rial).
 * .env: ZARINPAL_MERCHANT_ID, ZARINPAL_SANDBOX=true|false, optional ZARINPAL_BASE_URL.
 * Verify endpoint codes: 100 = verified now, 101 = already verified (both = paid).
 */
final class ZarinpalGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'zarinpal';
    }

    public function available(): bool
    {
        return (string) Env::get('ZARINPAL_MERCHANT_ID', '') !== '';
    }

    public function start(array $payment, string $callbackUrl): array
    {
        $res = $this->post('/pg/v4/payment/request.json', [
            'merchant_id' => (string) Env::get('ZARINPAL_MERCHANT_ID'),
            'amount' => $payment['amount_minor'],
            'currency' => 'IRR',
            'callback_url' => $callbackUrl,
            'description' => $payment['description'],
            'metadata' => array_filter(['email' => $payment['email'], 'mobile' => $payment['mobile']]),
        ]);
        $code = (int) ($res['data']['code'] ?? 0);
        $authority = (string) ($res['data']['authority'] ?? '');
        if ($code !== 100 || $authority === '') {
            return ['ok' => false, 'error' => 'zarinpal request failed: ' . json_encode($res['errors'] ?? $res, JSON_UNESCAPED_UNICODE)];
        }
        return ['ok' => true, 'authority' => $authority, 'redirect' => $this->base() . '/pg/StartPay/' . rawurlencode($authority)];
    }

    public function verify(array $payment, array $callback): array
    {
        $authority = (string) ($callback['Authority'] ?? '');
        if ($authority === '' || !hash_equals($payment['authority'], $authority)) {
            return ['status' => 'failed', 'error' => 'authority mismatch'];
        }
        if (($callback['Status'] ?? '') !== 'OK') {
            return ['status' => 'canceled'];
        }
        $res = $this->post('/pg/v4/payment/verify.json', [
            'merchant_id' => (string) Env::get('ZARINPAL_MERCHANT_ID'),
            'amount' => $payment['amount_minor'],
            'authority' => $authority,
        ]);
        if ($res === null) {
            return ['status' => 'retry', 'error' => 'network'];
        }
        $code = (int) ($res['data']['code'] ?? 0);
        if ($code === 100 || $code === 101) {
            return [
                'status' => 'paid',
                'ref' => (string) ($res['data']['ref_id'] ?? ''),
                'card' => (string) ($res['data']['card_pan'] ?? ''),
            ];
        }
        return ['status' => 'failed', 'error' => 'verify code ' . json_encode($res['errors'] ?? $code)];
    }

    private function base(): string
    {
        $custom = (string) Env::get('ZARINPAL_BASE_URL', '');
        if ($custom !== '') {
            return rtrim($custom, '/');
        }
        return Env::get('ZARINPAL_SANDBOX', false) ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    /** @return array<string, mixed>|null null on network error */
    private function post(string $path, array $body): ?array
    {
        $ch = curl_init($this->base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
