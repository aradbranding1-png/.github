<?php

declare(strict_types=1);

namespace App\Modules\Pages;

use App\Core\Auth\Auth;
use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\ContactGuard;
use App\Core\Security\Idempotency;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\WalletService;

/**
 * /p/{handle} and /p/{handle}/{lang}
 * Anonymous visitors and search engines get the free teaser layer (publicly cacheable).
 * Signed-in visitors get the full layer. Phase 2 inserts Stars billing in front of the full layer.
 */
final class PublicPageController extends Controller
{
    public function show(Request $request): Response
    {
        $router = $this->c->get(PageRouter::class);
        $owner = $router->ownerByHandle((string) $request->param('handle'));
        if ($owner === null || in_array($owner['status'], [Auth::STATUS_SUSPENDED, Auth::STATUS_BANNED, Auth::STATUS_DELETED], true)) {
            throw new HttpException(404);
        }

        $viewer = $this->c->get(Auth::class)->user();
        $lang = $request->param('lang');
        if ($lang !== null) {
            $pageId = $router->pageInLanguage($owner['id'], strtolower($lang));
        } elseif ($viewer !== null) {
            $pageId = $router->resolve($owner['id'], (int) $viewer['country_id'], (int) $viewer['language_id']);
        } else {
            $pageId = $router->resolve($owner['id'], null, null);
        }
        if ($pageId === null) {
            throw new HttpException(404);
        }

        $set = $router->routeSet($owner['id']);
        $model = $this->model($pageId, $set['pages'][$pageId]['version'], $set['owner_version']);
        $alternates = array_values(array_map(static fn (array $p): string => $p['code'], $set['pages']));

        $full = false;
        $unlock = null;
        if ($viewer !== null) {
            $gate = $this->c->get(PageViewGate::class);
            $full = $gate->hasAccess($viewer, $owner['id'], $pageId);
            if (!$full) {
                $unlock = [
                    'price' => $gate->price($viewer, (int) $model['country_id']),
                    'balance' => $this->c->get(WalletService::class)->balance((int) $viewer['id']),
                    'token' => Idempotency::token(),
                    'action' => '/p/' . strtolower((string) $request->param('handle')) . '/' . $model['lang_code'] . '/unlock',
                    'domestic' => (int) $viewer['country_id'] === (int) $model['country_id'],
                ];
            }
        }

        $html = $this->c->get(View::class)->render('public/page', [
            'page' => $model,
            'full' => $full,
            'unlock' => $unlock,
            'viewer' => $viewer,
            'isOwner' => $viewer !== null && (int) $viewer['id'] === $owner['id'],
            'alternates' => $alternates,
            'handle' => strtolower((string) $request->param('handle')),
            'labels' => PageLabels::for($model['lang_code']),
            'baseUrl' => rtrim((string) \App\Core\Env::get('APP_URL', ''), '/'),
            'flashes' => Session::flashes(),
            'buyEnabled' => (bool) $this->c->get(\App\Core\Settings\Settings::class)->get('payments.purchase_enabled', false),
        ], 'layouts/public');

        $response = Response::html($html);
        if ($viewer === null && session_status() !== PHP_SESSION_ACTIVE) {
            return $response
                ->withHeader('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600')
                ->withEtag($request);
        }
        return $response->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function model(int $pageId, int $version, int $ownerVersion): array
    {
        return $this->c->get(Cache::class)->remember("pagemodel:{$pageId}:v{$version}:o{$ownerVersion}", 3600, function () use ($pageId): array {
            $row = $this->c->get(Connection::class)->first(
                'SELECT p.id, p.title, p.company_name, p.teaser, p.about, p.content, p.cover_path, p.avatar_path, p.updated_at,
                        l.code AS lang_code, l.direction, l.name AS lang_name,
                        u.first_name, u.last_name, u.avatar_path AS owner_avatar, u.business_verified_at,
                        p.country_id, p.user_id, c.code AS country_code, c.name_fa AS country_fa, c.name_en AS country_en
                 FROM pages p
                 JOIN languages l ON l.id = p.language_id
                 JOIN users u ON u.id = p.user_id
                 JOIN countries c ON c.id = p.country_id
                 WHERE p.id = ?',
                [$pageId]
            );
            if ($row === null) {
                throw new HttpException(404);
            }
            $row['content'] = json_decode((string) ($row['content'] ?? '{}'), true) ?: [];
            foreach (['title', 'company_name', 'teaser'] as $public) {
                if (is_string($row[$public] ?? null)) {
                    $row[$public] = ContactGuard::mask($row[$public]);
                }
            }
            $row['verified'] = $row['business_verified_at'] !== null;
            unset($row['business_verified_at']);
            return $row;
        });
    }

    /** POST /p/{handle}/{lang}/unlock — charge Stars and open the full layer. */
    public function unlock(Request $request): Response
    {
        $viewer = $this->user($request);
        $router = $this->c->get(PageRouter::class);
        $handle = strtolower((string) $request->param('handle'));
        $lang = strtolower((string) $request->param('lang'));
        $owner = $router->ownerByHandle($handle);
        $pageId = $owner === null ? null : $router->pageInLanguage($owner['id'], $lang);
        if ($owner === null || $pageId === null) {
            throw new HttpException(404);
        }
        $set = $router->routeSet($owner['id']);
        $model = $this->model($pageId, $set['pages'][$pageId]['version'], $set['owner_version']);
        $back = '/p/' . $handle . '/' . $lang;

        $token = (string) $request->input('token', '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new HttpException(400);
        }
        try {
            $this->c->get(PageViewGate::class)->unlock($viewer, $owner['id'], (int) $model['country_id'], $pageId, $token);
        } catch (InsufficientStars $e) {
            return $this->redirect('/wallet?need=' . $e->missing() . '&next=' . rawurlencode($back),
                'موجودی Stars کافی نیست. برای مشاهده این صفحه ' . fa_num($e->missing()) . ' Star دیگر لازم دارید.', 'error');
        }
        return Response::redirect($back, 303);
    }
}
