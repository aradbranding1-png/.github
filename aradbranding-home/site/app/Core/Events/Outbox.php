<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Db\Connection;

/**
 * Transactional outbox. Call record() INSIDE the business transaction:
 * the event commits or rolls back with the data it describes.
 * OutboxProcessor later fans events out to counters, analytics and notifications.
 */
final class Outbox
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function record(
        string $event,
        ?int $userId,
        ?int $subjectUserId = null,
        ?int $refId = null,
        array $data = [],
    ): void {
        $this->db->insert(
            'INSERT INTO activity_events (event, user_id, subject_user_id, ref_id, data, created_at)
             VALUES (?, ?, ?, ?, ?, NOW(3))',
            [
                $event,
                $userId,
                $subjectUserId,
                $refId,
                $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }
}
