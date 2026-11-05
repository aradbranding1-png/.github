<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Auth\Auth;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Support\Str;

/**
 * "Sign in with Google" for guests, and "connect Google" from Account › Security for signed-in members.
 * A guest whose Google account belongs to nobody yet signs up with it: /register/google.
 */
final class GoogleController extends Controller
{
    /** How long after a Google sign-in a new password may be set without the current one. */
    public const RESET_WINDOW = 900;
    /** Session key of a verified Google identity that has no account yet (AuthController::showGoogleRegister). */
    public const SIGNUP = '_google_signup';
    /** How long that identity may be used to finish signing up. */
    public const SIGNUP_WINDOW = 1800;

    public function start(Request $request): Response
    {
        $google = $this->c->get(GoogleAuth::class);
        $user = $this->c->get(Auth::class)->user();
        $back = $user !== null ? '/account?tab=security' : '/login';
        if (!$google->enabled()) {
            return $this->redirect($back, t('ورود با گوگل هنوز فعال نشده است.'), 'error');
        }
        if ($user !== null && Session::get('_impersonator') !== null) {
            return $this->redirect($back, t('هنگام ورود به جای کاربر نمی‌توان حساب گوگل را وصل کرد.'), 'error');
        }
        $state = GoogleAuth::b64url(random_bytes(24));
        $verifier = GoogleAuth::b64url(random_bytes(48));
        Session::put('_google', [
            'state' => $state,
            'verifier' => $verifier,
            'link' => $user !== null,
            'next' => Str::safeNext($request->query('next')),
            'remember' => $request->query('remember', '1') === '1',
            'at' => time(),
        ]);
        $hint = $user !== null ? ($this->c->get(\App\Core\Db\Connection::class)->scalar('SELECT google_email FROM users WHERE id = ?', [$user['id']]) ?: null) : null;
        return Response::redirect($google->authUrl($state, $verifier, $hint), 302);
    }

    public function callback(Request $request): Response
    {
        $flow = Session::get('_google');
        Session::forget('_google');
        $auth = $this->c->get(Auth::class);
        $user = $auth->user();
        $link = is_array($flow) && !empty($flow['link']);
        $back = $user !== null ? '/account?tab=security' : '/login';

        if (!is_array($flow) || time() - (int) $flow['at'] > 600
            || !hash_equals((string) $flow['state'], (string) $request->query('state', ''))
            || $link !== ($user !== null)) {
            return $this->redirect($back, t('ورود با گوگل منقضی شد یا نامعتبر بود. دوباره تلاش کنید.'), 'error');
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            // The member pressed "Cancel" on Google's screen.
            return $this->redirect($back, t('ورود با گوگل لغو شد.'), 'error');
        }
        $google = $this->c->get(GoogleAuth::class);
        $identity = $google->enabled() ? $google->exchange($code, (string) $flow['verifier']) : null;
        if ($identity === null) {
            return $this->redirect($back, t('گوگل حساب شما را تأیید نکرد. دوباره تلاش کنید.'), 'error');
        }

        $service = $this->c->get(AuthService::class);
        if ($link) {
            $error = $service->linkGoogle((int) $user['id'], $identity['sub'], $identity['email']);
            return $error === null
                ? $this->redirect('/account?tab=security', t('حساب گوگل :email وصل شد. از این پس می‌توانید با گوگل وارد شوید.', ['email' => $identity['email']]))
                : $this->redirect('/account?tab=security', $error, 'error');
        }

        $result = $service->attemptGoogle($identity['sub'], $identity['email'], $request->ip());
        if (!$result['ok'] && !empty($result['unknown'])) {
            // First visit with this Google account: create the member's account in one short step.
            Session::put(self::SIGNUP, $identity + ['next' => (string) $flow['next'], 'remember' => (bool) $flow['remember'], 'at' => time()]);
            return $this->redirect('/register/google');
        }
        if (!$result['ok']) {
            return $this->redirect('/login', $result['error'], 'error');
        }
        $auth->login((int) $result['user_id'], (bool) $flow['remember']);
        Session::put('_google_at', time());
        \App\Modules\System\LocaleController::afterLogin($this->c, $request, (int) $result['user_id']);
        return $this->redirect((string) $flow['next']);
    }
}
