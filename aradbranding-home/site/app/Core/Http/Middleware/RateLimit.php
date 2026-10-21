<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Container;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\RateLimiter;

/** 'throttle:login', 'throttle:api', ... Identity = user id when known, else IP. */
final class RateLimit implements Middleware
{
    public function __construct(private Container $c, private string $name)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = $request->attribute('user_id');
        $identity = $userId !== null ? 'u:' . $userId : 'ip:' . $request->ip();
        $result = $this->c->get(RateLimiter::class)->hit($this->name, $identity);

        if (!$result['allowed']) {
            throw new HttpException(429, '', ['Retry-After' => (string) $result['retry_after']]);
        }
        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }
}
