<?php

declare(strict_types=1);

namespace App\Modules\Users;

final class ValidationFailed extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed');
    }
}
