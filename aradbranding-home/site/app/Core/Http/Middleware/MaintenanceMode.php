<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * storage/framework/maintenance.json = {"secret": "...", "retry": 120}
 * Admins bypass by visiting /__bypass/{secret} once (sets a cookie).
 */
final class MaintenanceMode implements Middleware
{
    public const FILE = BASE_PATH . '/storage/framework/maintenance.json';
    private const COOKIE = 'sadt_bypass';

    public function handle(Request $request, callable $next): Response
    {
        if (!is_file(self::FILE)) {
            return $next($request);
        }
        $state = json_decode((string) file_get_contents(self::FILE), true) ?: [];
        $secret = (string) ($state['secret'] ?? '');
        $token = $secret === '' ? '' : hash_hmac('sha256', 'bypass', $secret);

        if ($secret !== '' && $request->path === '/__bypass/' . $secret) {
            return Response::redirect('/')->withHeader(
                'Set-Cookie',
                self::COOKIE . '=' . $token . '; Path=/; Max-Age=43200; HttpOnly; Secure; SameSite=Lax'
            );
        }
        if ($token !== '' && hash_equals($token, (string) $request->cookie(self::COOKIE))) {
            return $next($request);
        }
        if ($request->path === '/health/live') {
            return $next($request);
        }

        $retry = (string) (int) ($state['retry'] ?? 120);
        $response = $request->wantsJson()
            ? Response::json([], t('سامانه در حال به‌روزرسانی است.'), 503)
            : Response::html(self::page((string) $request->attribute('csp_nonce')), 503);
        return $response->withHeader('Retry-After', $retry)->withHeader('Cache-Control', 'no-store');
    }

    private static function page(string $nonce): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html ' . \App\Core\I18n\I18n::langAttrs() . '><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $esc(t('در حال به‌روزرسانی')) . '</title>'
            . '<style nonce="' . htmlspecialchars($nonce) . '">body{font-family:system-ui,sans-serif;background:#0b1530;'
            . 'color:#fff;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center}h1{color:#c9a45c}</style>'
            . '<body><div><h1>' . $esc(t('سامانه توسعه تجارت')) . '</h1><p>' . $esc(t('در حال به‌روزرسانی هستیم و به‌زودی برمی‌گردیم.')) . '</p></div></body></html>';
    }
}
