<?php

declare(strict_types=1);

namespace App\Modules\Letters;

use App\Core\Container;
use App\Core\Queue\Job;

/** Queue job: delivers one batch of a public letter, then queues the next batch. */
final class DeliverCampaignBatch implements Job
{
    public function handle(Container $c, array $payload): void
    {
        $c->get(CampaignService::class)->deliverBatch((int) $payload['campaign_id']);
    }

    public function maxAttempts(): int
    {
        return 5;
    }
}
