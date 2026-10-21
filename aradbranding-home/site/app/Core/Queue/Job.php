<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Container;

/**
 * Jobs must be idempotent: a job may run more than once (at-least-once delivery).
 * Payloads carry ids only; the job reloads fresh state from the database.
 */
interface Job
{
    /** @param array<string, mixed> $payload */
    public function handle(Container $c, array $payload): void;

    public function maxAttempts(): int;
}
