<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Container;
use App\Core\Db\Connection;
use App\Core\Log\Logger;
use Throwable;

/**
 * Reads unprocessed events in id order and calls the handlers registered in
 * config/events.php (event => list of handler classes with handle(Container, array $events)).
 * Handlers receive batches so they can use multi-row writes. Handlers must be idempotent.
 */
final class OutboxProcessor
{
    /** @param array<string, list<class-string>> $handlers */
    public function __construct(
        private Container $c,
        private Connection $db,
        private Logger $logger,
        private array $handlers,
    ) {
    }

    public function run(int $batchSize = 500, int $maxBatches = 20): int
    {
        $total = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $events = $this->db->select(
                "SELECT id, event, user_id, subject_user_id, ref_id, data, created_at
                 FROM activity_events
                 WHERE processed_at IS NULL AND created_at >= NOW(3) - INTERVAL 7 DAY
                 ORDER BY processed_at, id
                 LIMIT {$batchSize}"
            );
            if ($events === []) {
                break;
            }

            $byEvent = [];
            foreach ($events as $e) {
                $byEvent[$e['event']][] = $e;
            }
            foreach ($byEvent as $name => $group) {
                foreach ($this->handlers[$name] ?? [] as $class) {
                    try {
                        (new $class())->handle($this->c, $group);
                    } catch (Throwable $ex) {
                        $this->logger->error('Outbox handler failed', ['handler' => $class, 'error' => $ex->getMessage()]);
                        return $total; // stop; the batch is retried next run
                    }
                }
            }

            $ids = array_map('intval', array_column($events, 'id'));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $this->db->exec("UPDATE activity_events SET processed_at = NOW(3) WHERE id IN ({$marks})", $ids);
            $total += count($events);
            if (count($events) < $batchSize) {
                break;
            }
        }
        return $total;
    }
}
