<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

/** One class per payment provider. Secrets come from .env, never from the database. */
interface PaymentGateway
{
    public function code(): string;

    /** Whether credentials are configured and the gateway can take payments now. */
    public function available(): bool;

    /**
     * Registers the payment with the provider.
     * @param array{public_id: string, amount_minor: int, currency: string, description: string, email: string, mobile: string} $payment
     * @return array{ok: bool, authority?: string, redirect?: string, error?: string}
     */
    public function start(array $payment, string $callbackUrl): array;

    /**
     * Server-to-server verification. Amount comes from our database, never from the callback.
     * @param array{amount_minor: int, currency: string, authority: string} $payment
     * @param array<string, mixed> $callback query parameters returned by the provider
     * @return array{status: string, ref?: string, card?: string, error?: string} status: paid | failed | canceled | retry
     */
    public function verify(array $payment, array $callback): array;
}
