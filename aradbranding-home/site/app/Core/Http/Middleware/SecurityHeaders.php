<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Config;
use App\Core\Container;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class SecurityHeaders implements Middleware
{
    public function __construct(private Container $c)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $config = $this->c->get(Config::class);
        $nonce = (string) $request->attribute('csp_nonce');

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data: blob: " . $config->get('app.media_url', ''),
            "font-src 'self' " . $config->get('app.asset_url', ''),
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self' " . implode(' ', (array) $config->get('security.form_action_hosts', [])),
            "base-uri 'none'",
            "object-src 'none'",
        ];

        $headers = [
            'Content-Security-Policy' => implode('; ', array_map('trim', $csp)),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
        }
        foreach ($headers as $name => $value) {
            if ($response->header($name) === null) {
                $response->withHeader($name, $value);
            }
        }

        // Safe default: nothing is publicly cacheable unless a controller says so.
        if ($response->header('Cache-Control') === null) {
            $response->withHeader('Cache-Control', 'private, no-cache');
            $response->withHeader('Vary', 'Cookie');
        }
        return $response;
    }
}
