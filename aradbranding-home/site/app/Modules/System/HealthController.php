<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Container;
use App\Core\Env;
use App\Core\Health\HealthCheck;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class HealthController
{
    public function __construct(private Container $c)
    {
    }

    /** For load balancers and uptime monitors. No DB, no secrets. */
    public function live(Request $request): Response
    {
        return Response::json(['status' => 'alive'])->withHeader('Cache-Control', 'no-store');
    }

    /** Detailed checks. Requires HEALTH_TOKEN via X-Health-Token header. */
    public function full(Request $request): Response
    {
        $token = (string) Env::get('HEALTH_TOKEN', '');
        $sent = (string) ($request->header('x-health-token') ?? '');
        if ($token === '' || !hash_equals($token, $sent)) {
            throw new HttpException(404);
        }
        $checks = (new HealthCheck($this->c))->run();
        $overall = HealthCheck::overall($checks);
        return Response::json(['status' => $overall, 'checks' => $checks], '', $overall === 'fail' ? 503 : 200, $overall !== 'fail')
            ->withHeader('Cache-Control', 'no-store');
    }
}
