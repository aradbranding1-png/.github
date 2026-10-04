<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Auth\Auth;
use App\Core\Container;
use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\I18n\I18n;
use App\Core\Support\Str;
use Throwable;

/**
 * Language switcher: /lang/{code}?to=/path stores the visitor's choice in the `lang` cookie (one year) and, for a
 * signed-in member, on the account so the same language follows them to other devices.
 */
final class LocaleController
{
    public function __construct(private Container $c)
    {
    }

    public function switch(Request $request): Response
    {
        $code = (string) $request->param('code');
        $to = Str::safeNext($request->query('to'));
        if (str_starts_with($to, '/lang/')) {
            $to = '/';
        }
        if (!isset(I18n::enabled()[$code])) {
            return Response::redirect($to, 303);
        }
        self::remember($code, $request->isSecure());
        try {
            $uid = $this->c->get(Auth::class)->id();
            if ($uid !== null) {
                $this->c->get(Connection::class)->exec('UPDATE users SET ui_locale = ? WHERE id = ?', [$code, $uid]);
            }
        } catch (Throwable) {
        }
        return Response::redirect($to, 303)->withHeader('Cache-Control', 'no-store');
    }

    public static function remember(string $code, bool $secure): void
    {
        setcookie('lang', $code, ['expires' => time() + 31_536_000, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax']);
    }

    /** At sign-in: a member's saved language wins on a device where nothing was chosen yet; a choice made here is saved. */
    public static function afterLogin(Container $c, Request $request, int $userId): void
    {
        try {
            $db = $c->get(Connection::class);
            $chosen = (string) $request->cookie('lang');
            if (isset(I18n::enabled()[$chosen])) {
                $db->exec('UPDATE users SET ui_locale = ? WHERE id = ?', [$chosen, $userId]);
                return;
            }
            $saved = (string) $db->scalar('SELECT ui_locale FROM users WHERE id = ?', [$userId]);
            if ($saved !== '' && isset(I18n::enabled()[$saved])) {
                self::remember($saved, $request->isSecure());
            }
        } catch (Throwable) {
        }
    }
}
