<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Container;
use App\Core\Db\Connection;

/** Outbox handler: page_viewed → owner's precomputed counter (one UPDATE per owner per batch). */
final class CountPageViews
{
    /** @param list<array<string, mixed>> $events */
    public function handle(Container $c, array $events): void
    {
        $perOwner = [];
        foreach ($events as $e) {
            if ($e['subject_user_id'] !== null) {
                $perOwner[(int) $e['subject_user_id']] = ($perOwner[(int) $e['subject_user_id']] ?? 0) + 1;
            }
        }
        $db = $c->get(Connection::class);
        foreach ($perOwner as $ownerId => $n) {
            $db->exec(
                'UPDATE user_counters SET page_views_received = page_views_received + ?, updated_at = NOW(3) WHERE user_id = ?',
                [$n, $ownerId]
            );
        }
    }
}
