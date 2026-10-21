<?php

declare(strict_types=1);

namespace App\Core\Console;

/** Runs each named task at most once per interval. State lives in storage/framework. */
final class Scheduler
{
    /** @var list<array{name: string, every: int, task: \Closure}> */
    private array $tasks = [];

    public function every(int $seconds, string $name, \Closure $task): void
    {
        $this->tasks[] = ['name' => $name, 'every' => $seconds, 'task' => $task];
    }

    /** @return array<string, string> task => result */
    public function runDue(): array
    {
        $results = [];
        foreach ($this->tasks as $t) {
            $stamp = BASE_PATH . '/storage/framework/task-' . $t['name'] . '.last';
            $last = is_file($stamp) ? (int) file_get_contents($stamp) : 0;
            // 60s tolerance so a 15-minute cron does not drift past a 15-minute task.
            if (time() - $last < $t['every'] - 60) {
                continue;
            }
            try {
                $results[$t['name']] = (string) ($t['task'])();
                file_put_contents($stamp, (string) time());
            } catch (\Throwable $e) {
                $results[$t['name']] = 'ERROR: ' . $e->getMessage();
            }
        }
        return $results;
    }
}
