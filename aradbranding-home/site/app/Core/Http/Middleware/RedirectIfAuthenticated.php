<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Auth\Auth;
use App\Core\Container;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** 'guest': login and register pages are only for signed-out visitors. */
final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private Container $c)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->c->get(Auth::class)->user() !== null) {
            return Response::redirect('/dashboard');
        }
        return $next($request);
    }
}
