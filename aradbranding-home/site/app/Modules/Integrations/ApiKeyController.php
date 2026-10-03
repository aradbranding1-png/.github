<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Auth\Password;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Session\Session;
use App\Core\Settings\Settings;

/** «API و کلید دسترسی»: the Super Admin creates, sees and revokes personal API keys (official API v1). */
final class ApiKeyController extends Controller
{
    public function index(Request $request, array $errors = [], int $status = 200): Response
    {
        $user = $this->guard($request);
        $newKey = Session::get('member_new_api_key');
        Session::forget('member_new_api_key');
        return $this->view($request, 'account/api', [
            'title' => 'API و کلید دسترسی',
            'enabled' => (bool) $this->c->get(Settings::class)->get('api.enabled', true),
            'keys' => $this->c->get(ApiKeys::class)->forUser((int) $user['id']),
            'scopes' => ApiKeys::SCOPES,
            'newKey' => is_array($newKey) ? $newKey : null,
            'baseUrl' => (rtrim((string) \App\Core\Env::get('APP_URL', ''), '/') ?: 'https://aradbranding.app') . '/api/v1',
            'errors' => $errors,
            'old' => $status === 422 ? $request->all() : [],
        ], 'layouts/app', $status);
    }

    public function create(Request $request): Response
    {
        $user = $this->guard($request);
        if (!(bool) $this->c->get(Settings::class)->get('api.enabled', true)) {
            return $this->redirect('/account/api', 'API فعلاً توسط مدیر سامانه غیرفعال شده است.', 'error');
        }
        $keys = $this->c->get(ApiKeys::class);
        $name = trim((string) $request->input('name', ''));
        $scopes = array_values(array_intersect(array_keys(ApiKeys::SCOPES), (array) $request->input('scopes', [])));
        $days = (int) $request->input('days', 90);
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'یک نام برای کلید بنویسید (مثلاً «CRM فروش»).';
        }
        if ($scopes === []) {
            $errors['scopes'] = 'دست‌کم یک دسترسی انتخاب کنید.';
        }
        if (!in_array($days, ApiKeys::EXPIRY_DAYS, true)) {
            $days = 90;
        }
        if ($keys->activeCount((int) $user['id']) >= ApiKeys::MAX_ACTIVE) {
            $errors['name'] = 'حداکثر ' . fa_int(ApiKeys::MAX_ACTIVE) . ' کلید فعال می‌توانید داشته باشید؛ ابتدا یک کلید را باطل کنید.';
        }
        $hash = (string) $this->c->get(Connection::class)->scalar('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if ($errors === [] && !Password::verify((string) $request->input('password', ''), $hash)) {
            $errors['password'] = 'رمز عبور درست نیست.';
        }
        if ($errors !== []) {
            return $this->index($request, $errors, 422);
        }
        $made = $keys->create((int) $user['id'], $name, $scopes, $days);
        Session::put('member_new_api_key', ['name' => $name, 'key' => $made['key']]);
        $this->c->get(Audit::class)->log('api_key.create', (int) $user['id'], 'api_key', $made['id'], 'success', $request, ['scopes' => $scopes, 'days' => $days]);
        return $this->redirect('/account/api', 'کلید ساخته شد. همین حالا آن را کپی کنید؛ دوباره نمایش داده نمی‌شود.');
    }

    public function revoke(Request $request): Response
    {
        $user = $this->guard($request);
        $id = (int) $request->param('id');
        if ($this->c->get(ApiKeys::class)->revoke($id, (int) $user['id'])) {
            $this->c->get(Audit::class)->log('api_key.revoke', (int) $user['id'], 'api_key', $id, 'success', $request);
        }
        return $this->redirect('/account/api', 'کلید باطل شد و دیگر کار نمی‌کند.');
    }

    /** Only the Super Admin may use the official API; everyone else gets 404. @return array<string, mixed> */
    private function guard(Request $request): array
    {
        $user = $this->user($request);
        if (!$this->c->get(ApiKeys::class)->allowed((int) $user['id'])) {
            throw new \App\Core\Http\HttpException(404);
        }
        return $user;
    }
}
