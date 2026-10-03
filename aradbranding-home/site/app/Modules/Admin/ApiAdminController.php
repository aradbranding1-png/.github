<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Session\Session;
use App\Modules\Integrations\ApiClients;

/** «اتصال API»: keys for partner systems such as Arad Contact (create — shown once —, list, revoke, recent calls). */
final class ApiAdminController extends AdminController
{
    public function index(Request $request, array $errors = [], int $status = 200): Response
    {
        $clients = $this->clients();
        $newKey = Session::get('api_new_key');
        Session::forget('api_new_key');
        return $this->view($request, 'admin/api', [
            'title' => 'اتصال API',
            'clients' => $clients->all(),
            'recent' => $clients->recent(20),
            'scopes' => ApiClients::SCOPES,
            'newKey' => is_array($newKey) ? $newKey : null,
            'memberKeys' => $this->c->get(\App\Modules\Integrations\ApiKeys::class)->all(50),
            'apiEnabled' => (bool) $this->c->get(\App\Core\Settings\Settings::class)->get('api.enabled', true),
            'baseUrl' => rtrim((string) \App\Core\Env::get('APP_URL', ''), '/'),
            'errors' => $errors,
            'old' => $status === 422 ? $request->all() : [],
        ], 'layouts/app', $status);
    }

    public function create(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $scopes = array_values(array_intersect(array_keys(ApiClients::SCOPES), (array) $request->input('scopes', [])));
        $ips = trim((string) $request->input('ips', ''));
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'نام اتصال را بنویسید (مثلاً «آراد کانتکت»).';
        }
        if ($scopes === []) {
            $errors['scopes'] = 'حداقل یک دسترسی انتخاب کنید.';
        }
        $ipList = array_values(array_filter(array_map('trim', preg_split('/[\s,،]+/u', $ips) ?: [])));
        foreach ($ipList as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors['ips'] = 'نشانی IP «' . $ip . '» معتبر نیست.';
            }
        }
        if ($errors === [] && !$this->reauth($request)) {
            $errors['password'] = 'رمز عبور شما درست نیست.';
        }
        if ($errors !== []) {
            return $this->index($request, $errors, 422);
        }
        $actor = (int) $this->user($request)['id'];
        $made = $this->clients()->create($name, $scopes, $ipList === [] ? null : implode(',', $ipList), $actor);
        $this->c->get(Audit::class)->log('api.key_create', $actor, 'api_client', $made['id'], 'success', $request, ['name' => $name, 'scopes' => $scopes, 'ips' => $ipList]);
        Session::put('api_new_key', ['name' => $name, 'key' => $made['key']]);
        return $this->redirect('/admin/api', 'کلید ساخته شد. آن را همین حالا کپی کنید؛ دوباره نمایش داده نمی‌شود.');
    }

    public function revoke(Request $request): Response
    {
        $id = (int) $request->param('id');
        if (!$this->reauth($request)) {
            return $this->index($request, ['revoke' => 'برای باطل‌کردن کلید رمز عبور خود را وارد کنید.'], 422);
        }
        $this->clients()->revoke($id);
        $this->c->get(Audit::class)->log('api.key_revoke', (int) $this->user($request)['id'], 'api_client', $id, 'success', $request);
        return $this->redirect('/admin/api', 'کلید باطل شد و دیگر پذیرفته نمی‌شود.');
    }

    private function clients(): ApiClients
    {
        return new ApiClients($this->c->get(Connection::class));
    }

    /** POST /admin/api/settings — turn the members' official API on or off. */
    public function settings(Request $request): Response
    {
        $on = (string) $request->input('enabled', '0') === '1';
        $actor = (int) $this->user($request)['id'];
        $this->c->get(\App\Core\Settings\Settings::class)->set('api.enabled', $on, $actor);
        $this->c->get(\App\Core\Security\Audit::class)->log('api.settings', $actor, 'setting', null, 'success', $request, ['enabled' => $on]);
        return $this->redirect('/admin/api#member-keys', $on ? 'API اعضا فعال شد.' : 'API اعضا غیرفعال شد؛ همه کلیدهای شخصی تا فعال‌شدن دوباره پاسخ ۵۰۳ می‌گیرند.');
    }

    /** POST /admin/api/keys/{id}/revoke — revoke a member's personal key (e.g. leaked or abused). */
    public function revokeKey(Request $request): Response
    {
        $id = (int) $request->param('id');
        $actor = (int) $this->user($request)['id'];
        if ($this->c->get(\App\Modules\Integrations\ApiKeys::class)->revoke($id)) {
            $this->c->get(\App\Core\Security\Audit::class)->log('api_key.revoke', $actor, 'api_key', $id, 'success', $request, ['by' => 'admin']);
        }
        return $this->redirect('/admin/api#member-keys', 'کلید باطل شد.');
    }
}
