<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Db\Connection;

/** Phase-1 queue on MySQL using SELECT ... FOR UPDATE SKIP LOCKED. */
final class DatabaseQueue
{
    /** Backoff in seconds per attempt number. */
    private const BACKOFF = [1 => 10, 2 => 60, 3 => 300, 4 => 1800];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param class-string<Job> $job
     * @param array<string, mixed> $payload
     */
    public function push(string $queue, string $job, array $payload = [], int $delaySeconds = 0): int
    {
        return $this->db->insert(
            'INSERT INTO jobs (queue, job, payload, attempts, available_at, created_at)
             VALUES (?, ?, ?, 0, NOW(3) + INTERVAL ? SECOND, NOW(3))',
            [$queue, $job, json_encode($payload, JSON_THROW_ON_ERROR), $delaySeconds]
        );
    }

    /** @return list<array{id: int, job: string, payload: string, attempts: int}> */
    public function reserve(string $queue, int $limit, string $worker): array
    {
        return $this->db->transaction(function (Connection $db) use ($queue, $limit, $worker): array {
            $rows = $db->select(
                "SELECT id, job, payload, attempts FROM jobs
                 WHERE queue = ? AND reserved_at IS NULL AND available_at <= NOW(3)
                 ORDER BY available_at, id
                 LIMIT {$limit}
                 FOR UPDATE SKIP LOCKED",
                [$queue]
            );
            if ($rows === []) {
                return [];
            }
            $ids = array_column($rows, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $db->exec(
                "UPDATE jobs SET reserved_at = NOW(3), reserved_by = ?, attempts = attempts + 1 WHERE id IN ({$marks})",
                [$worker, ...$ids]
            );
            return array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'job' => (string) $r['job'],
                'payload' => (string) $r['payload'],
                'attempts' => (int) $r['attempts'] + 1,
            ], $rows);
        });
    }

    public function complete(int $id): void
    {
        $this->db->exec('DELETE FROM jobs WHERE id = ?', [$id]);
    }

    /** @param array{id: int, job: string, payload: string, attempts: int} $job */
    public function retryOrFail(array $job, \Throwable $error, int $maxAttempts): void
    {
        if ($job['attempts'] >= $maxAttempts) {
            $this->db->transaction(function (Connection $db) use ($job, $error): void {
                $db->insert(
                    'INSERT INTO failed_jobs (queue, job, payload, attempts, error, failed_at)
                     SELECT queue, job, payload, attempts, ?, NOW(3) FROM jobs WHERE id = ?',
                    [substr(get_class($error) . ': ' . $error->getMessage(), 0, 4000), $job['id']]
                );
                $db->exec('DELETE FROM jobs WHERE id = ?', [$job['id']]);
            });
            return;
        }
        $delay = self::BACKOFF[$job['attempts']] ?? 3600;
        $this->db->exec(
            'UPDATE jobs SET reserved_at = NULL, reserved_by = NULL, available_at = NOW(3) + INTERVAL ? SECOND WHERE id = ?',
            [$delay, $job['id']]
        );
    }

    /**
     * Handle jobs whose worker died or hung (reserved longer than $timeoutSeconds).
     * A job that has already used $maxAttempts reservations is parked in failed_jobs instead of
     * being released again, so a job that kills its worker cannot loop forever.
     */
    public function reclaimStale(int $timeoutSeconds = 1200, int $maxAttempts = 5): int
    {
        return $this->db->transaction(function (Connection $db) use ($timeoutSeconds, $maxAttempts): int {
            $db->exec(
                'INSERT INTO failed_jobs (queue, job, payload, attempts, error, failed_at)
                 SELECT queue, job, payload, attempts, ?, NOW(3) FROM jobs
                 WHERE reserved_at IS NOT NULL AND reserved_at < NOW(3) - INTERVAL ? SECOND AND attempts >= ?',
                ['Worker timed out or died while running this job', $timeoutSeconds, $maxAttempts]
            );
            $db->exec(
                'DELETE FROM jobs
                 WHERE reserved_at IS NOT NULL AND reserved_at < NOW(3) - INTERVAL ? SECOND AND attempts >= ?',
                [$timeoutSeconds, $maxAttempts]
            );
            return $db->exec(
                'UPDATE jobs SET reserved_at = NULL, reserved_by = NULL
                 WHERE reserved_at IS NOT NULL AND reserved_at < NOW(3) - INTERVAL ? SECOND',
                [$timeoutSeconds]
            );
        });
    }

    /** @return array<string, int> queue => waiting jobs */
    public function backlog(): array
    {
        $rows = $this->db->select('SELECT queue, COUNT(*) AS n FROM jobs WHERE reserved_at IS NULL GROUP BY queue');
        return array_column($rows, 'n', 'queue');
    }

    public function retryFailed(?int $id = null): int
    {
        $where = $id === null ? '' : ' WHERE id = ' . (int) $id;
        return $this->db->transaction(function (Connection $db) use ($where): int {
            $n = $db->exec(
                'INSERT INTO jobs (queue, job, payload, attempts, available_at, created_at)
                 SELECT queue, job, payload, 0, NOW(3), NOW(3) FROM failed_jobs' . $where
            );
            $db->exec('DELETE FROM failed_jobs' . $where);
            return $n;
        });
    }
}
