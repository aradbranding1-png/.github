<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Config;
use App\Core\Container;
use App\Core\Db\Connection;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Log\Logger;

/**
 * Adds Server-Timing and X-Request-Id. Enforces the per-route query budget in
 * development so N+1 queries fail loudly instead of reaching production.
 */
final class RequestTiming implements Middleware
{
    public function __construct(private Container $c)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);
        $appMs = (hrtime(true) - $start) / 1e6;

        $config = $this->c->get(Config::class);
        $stats = ['count' => 0, 'ms' => 0.0];
        if ($this->c->has(Connection::class)) {
            $stats = $this->c->get(Connection::class)->stats();
        }

        $response->withHeader('X-Request-Id', (string) $request->attribute('request_id'));
        if ($config->get('app.server_timing', true)) {
            $response->withHeader('Server-Timing', sprintf(
                'app;dur=%.1f, db;dur=%.1f;desc="%d queries"',
                $appMs,
                $stats['ms'],
                $stats['count']
            ));
        }

        $budget = (int) $config->get('app.query_budget', 8);
        if ($stats['count'] > $budget) {
            $this->c->get(Logger::class)->warning('Query budget exceeded', [
                'path' => $request->path,
                'queries' => $stats['count'],
                'budget' => $budget,
            ]);
            if ($config->get('app.env') === 'development') {
                return Response::html(
                    "<h1>Query budget exceeded</h1><p>{$stats['count']} queries &gt; {$budget}. Possible N+1.</p>",
                    500
                );
            }
        }
        return $response;
    }
}
