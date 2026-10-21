<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;

/** Synchronizer token for every state-changing request. Must run after "session". */
final class VerifyCsrf implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->isReadOnly()) {
            return $next($request);
        }
        $sent = (string) ($request->header('x-csrf-token') ?? $request->input('_token', ''));
        $expected = Session::active() ? (string) Session::get('_csrf', '') : '';
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419, 'CSRF token mismatch');
        }
        return $next($request);
    }
}
