<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Auth\Auth;
use App\Core\Container;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** 'auth': requires an active account. Suspended/banned/deleted users are signed out. */
final class Authenticate implements Middleware
{
    public function __construct(private Container $c)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $auth = $this->c->get(Auth::class);
        $user = $auth->user();

        if ($user !== null && in_array((int) $user['status'], [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED, Auth::STATUS_DELETED], true)) {
            $auth->logout();
            $user = null;
        }
        if ($user === null) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            $next_ = $request->isReadOnly() ? '?next=' . rawurlencode($request->path) : '';
            return Response::redirect('/login' . $next_);
        }
        // At most one write per user per hour (DAU/WAU/MAU only need day precision). The WHERE clause
        // makes parallel requests at the hour boundary a no-op instead of N row writes.
        // The first request of a new (Tehran) day also writes, so user_activity_days lists every member
        // who was active that day — not only those whose last write was more than an hour ago.
        $dayStart = \App\Modules\Analytics\ActiveDays::todayStartUtc();
        $last = $user['last_active_at'] === null ? null : (string) $user['last_active_at'];
        if ($last === null || strtotime($last . ' UTC') < time() - 3600 || $last < $dayStart) {
            $this->c->get(\App\Core\Db\Connection::class)->exec(
                'UPDATE users SET last_active_at = NOW(3)
                  WHERE id = ? AND (last_active_at IS NULL OR last_active_at < NOW(3) - INTERVAL 1 HOUR OR last_active_at < ?)',
                [$user['id'], $dayStart]
            );
            $this->c->get(\App\Modules\Analytics\ActiveDays::class)->touch((int) $user['id']);
        }
        $request->withAttribute('user_id', (int) $user['id']);
        $impersonator = \App\Core\Session\Session::get('_impersonator');
        if ($impersonator !== null) {
            $request->withAttribute('impersonator_id', (int) $impersonator);
        }
        $request->withAttribute('user', $user);
        return $next($request)->withHeader('Cache-Control', 'private, no-store');
    }
}
