<?php

declare(strict_types=1);

namespace App\Core\Cache;

final class Lock
{
    /** @param \Closure(): void $release */
    public function __construct(private \Closure $release)
    {
    }

    public function release(): void
    {
        ($this->release)();
        $this->release = static function (): void {
        };
    }

    public function __destruct()
    {
        $this->release();
    }
}
