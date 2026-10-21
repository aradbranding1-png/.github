<?php

declare(strict_types=1);

namespace App\Core\Http;

interface Middleware
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response;
}
