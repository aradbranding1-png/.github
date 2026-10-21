<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

final class InsufficientStars extends \RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct('Insufficient stars');
    }

    public function missing(): int
    {
        return max(0, $this->required - $this->balance);
    }
}
