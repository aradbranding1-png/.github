<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Container;
use App\Core\Queue\Job;

final class ExportJob implements Job
{
    public function handle(Container $c, array $payload): void
    {
        $c->get(ExportService::class)->run((int) $payload['export_id']);
    }

    public function maxAttempts(): int
    {
        return 3;
    }
}
