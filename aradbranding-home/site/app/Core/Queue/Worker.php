<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Container;
use App\Core\Log\Logger;
use Throwable;

/** Processes queues in priority order until the time or memory budget is spent. */
final class Worker
{
    /** A single job is interrupted after this many seconds (needs pcntl; DB-side limits still apply without it). */
    private const JOB_TIMEOUT = 300;
    /** Reservations older than this belong to a dead or hung worker. Must be well above JOB_TIMEOUT. */
    private const STALE_AFTER = 1200;

    private bool $stop = false;
    private bool $alarms = false;

    /** @param list<string> $queues highest priority first */
    public function __construct(
        private Container $c,
        private DatabaseQueue $queue,
        private Logger $logger,
        private array $queues,
        private ?\Closure $onIdle = null,
        private ?\Closure $heartbeat = null,
    ) {
    }

    public function run(int $maxSeconds, int $memoryLimitMb = 128): int
    {
        $deadline = time() + $maxSeconds;
        $workerId = gethostname() . ':' . getmypid();
        $processed = 0;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
            if (function_exists('pcntl_alarm')) {
                $this->alarms = true;
                pcntl_signal(SIGALRM, static function (): void {
                    throw new \RuntimeException('Job exceeded ' . self::JOB_TIMEOUT . 's and was interrupted');
                });
            }
        }

        // Jobs left behind by a worker that died or hung: release them now instead of waiting for cron.
        try {
            $this->queue->reclaimStale(self::STALE_AFTER);
        } catch (Throwable $e) {
            $this->logger->error('Queue reclaim failed', ['error' => $e->getMessage()]);
        }

        while (!$this->stop && time() < $deadline) {
            if (memory_get_usage(true) > $memoryLimitMb * 1024 * 1024) {
                $this->logger->warning('Worker memory limit reached, exiting');
                break;
            }
            $didWork = false;
            $this->beat();
            foreach ($this->queues as $name) {
                // One job at a time: jobs reserved but still waiting would age while a long job runs,
                // get reclaimed and be executed twice by the next worker.
                $jobs = $this->queue->reserve($name, 1, $workerId);
                if ($jobs === []) {
                    continue;
                }
                foreach ($jobs as $job) {
                    $this->process($job);
                    $processed++;
                }
                $didWork = true;
                break; // re-check higher-priority queues first
            }
            if (!$didWork) {
                $idleWork = $this->onIdle !== null ? (int) ($this->onIdle)() : 0;
                if ($idleWork === 0) {
                    usleep(500_000);
                }
            }
        }
        return $processed;
    }

    /** @param array{id: int, job: string, payload: string, attempts: int} $job */
    private function process(array $job): void
    {
        $max = 5;
        $this->beat();
        try {
            if (!class_exists($job['job']) || !is_subclass_of($job['job'], Job::class)) {
                throw new \RuntimeException('Unknown job class: ' . $job['job']);
            }
            $class = $job['job'];
            /** @var Job $instance */
            $instance = new $class();
            $max = $instance->maxAttempts();
            if ($this->alarms) {
                pcntl_alarm(self::JOB_TIMEOUT);
            }
            try {
                $instance->handle($this->c, json_decode($job['payload'], true, 32, JSON_THROW_ON_ERROR));
            } finally {
                if ($this->alarms) {
                    pcntl_alarm(0);
                }
            }
            $this->queue->complete($job['id']);
        } catch (Throwable $e) {
            $this->logger->error('Job failed', [
                'job_id' => $job['id'],
                'job' => $job['job'],
                'attempt' => $job['attempts'],
                'error' => $e->getMessage(),
            ]);
            $this->queue->retryOrFail($job, $e, $max);
        }
    }

    private function beat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }
}
