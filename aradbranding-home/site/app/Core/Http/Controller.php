<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Container;
use App\Core\View\View;

abstract class Controller
{
    public function __construct(protected Container $c)
    {
    }

    /** @param array<string, mixed> $data */
    protected function view(Request $request, string $template, array $data = [], string $layout = 'layouts/app', int $status = 200): Response
    {
        $user = $request->attribute('user');
        $perms = is_array($user) ? $this->c->get(\App\Core\Auth\Gate::class)->permissions((int) $user['id']) : [];
        $data += [
            'perms' => $perms,
            'isStaff' => $perms !== [],
            'impersonating' => \App\Core\Session\Session::get('_impersonator') !== null,
            'canManage' => is_array($user) && $this->c->get(\App\Core\Auth\Gate::class)->allows((int) $user['id'], 'settings.manage'),
            'canOfficial' => is_array($user) && $this->c->get(\App\Core\Auth\Gate::class)->allows((int) $user['id'], 'letters.official'),
            'canUpdate' => is_array($user) && $this->c->get(\App\Core\Auth\Gate::class)->allows((int) $user['id'], 'updates.manage'),
            'user' => $user,
            'path' => $request->path,
            'flashes' => \App\Core\Session\Session::flashes(),
            // Buying Stars can be switched off by the admin (e.g. while no payment gateway is connected).
            'buyEnabled' => (bool) $this->c->get(\App\Core\Settings\Settings::class)->get('payments.purchase_enabled', false),
        ];
        return Response::html($this->c->get(View::class)->render($template, $data, $layout), $status);
    }

    protected function redirect(string $to, ?string $flash = null, string $type = 'success'): Response
    {
        if ($flash !== null) {
            \App\Core\Session\Session::flash($type, $flash);
        }
        return Response::redirect($to, 303);
    }

    /** @return array<string, mixed> */
    protected function user(Request $request): array
    {
        $user = $request->attribute('user');
        if (!is_array($user)) {
            throw new HttpException(401);
        }
        return $user;
    }
}
