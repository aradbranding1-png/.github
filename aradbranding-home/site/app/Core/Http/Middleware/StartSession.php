<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Container;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;

/**
 * 'session'          → always start.
 * 'session:optional' → start only if the visitor already has a session cookie,
 *                      so anonymous public pages stay publicly cacheable.
 */
final class StartSession implements Middleware
{
    public function __construct(private Container $c, private bool $optional = false)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $secure = $request->isSecure();
        // __Host- prefix requires Secure; plain name only for local http development.
        $name = $secure ? '__Host-sadt' : 'sadt_session';

        if ($this->optional && $request->cookie($name) === null) {
            return $next($request);
        }

        session_set_save_handler(
            $this->c->get(\App\Core\Session\SessionStore::class)->handler($request->ip(), $request->userAgent()),
            true
        );

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_probability', '0');
        ini_set('session.lazy_write', '1');
        session_cache_limiter('');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        Session::ageFlash();

        $request->withAttribute('user_id', Session::userId());
        $response = $next($request);
        session_write_close();
        return $response;
    }
}
