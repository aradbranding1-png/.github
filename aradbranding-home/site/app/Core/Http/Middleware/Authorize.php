<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Auth\Gate;
use App\Core\Container;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** 'can:pages.approve' — must run after 'auth'. Scope is exposed to the controller. */
final class Authorize implements Middleware
{
    public function __construct(private Container $c, private string $permission)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = $request->attribute('user_id');
        $gate = $this->c->get(Gate::class);
        if ($userId === null || !$gate->allows((int) $userId, $this->permission)) {
            throw new HttpException(403);
        }
        $request->withAttribute('scope', $gate->scope((int) $userId, $this->permission));
        return $next($request);
    }
}
