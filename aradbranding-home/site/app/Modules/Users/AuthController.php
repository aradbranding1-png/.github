<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Auth\Auth;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\I18n\I18n;
use App\Core\Session\Session;
use App\Core\Support\Str;
use App\Core\Validation\Validator;
use App\Modules\Reference\ReferenceData;

final class AuthController extends Controller
{
    private const LABELS = [
        'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'email' => 'ایمیل', 'password' => 'رمز عبور',
        'password_confirmation' => 'تکرار رمز عبور', 'country_id' => 'کشور', 'language_id' => 'زبان',
        'phone_cc' => 'کد کشور', 'phone' => 'شماره تلفن',
    ];

    public function showLogin(Request $request): Response
    {
        return $this->view($request, 'auth/login', [
            'title' => t('ورود'),
            'old' => ['email' => ''],
            'next' => Str::safeNext($request->query('next')),
            'error' => null,
            'googleEnabled' => $this->c->get(GoogleAuth::class)->enabled(),
        ], 'layouts/guest');
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        $next = Str::safeNext($request->input('next'));

        $result = $email === '' || $password === ''
            ? ['ok' => false, 'error' => t('ایمیل و رمز عبور را وارد کنید.')]
            : $this->c->get(AuthService::class)->attempt($email, $password, $request->ip());

        if (!$result['ok']) {
            return $this->view($request, 'auth/login', [
                'title' => t('ورود'),
                'old' => ['email' => $email],
                'next' => $next,
                'error' => $result['error'],
                'googleEnabled' => $this->c->get(GoogleAuth::class)->enabled(),
            ], 'layouts/guest', 422);
        }

        $this->c->get(Auth::class)->login($result['user_id'], (bool) $request->input('remember'));
        \App\Modules\System\LocaleController::afterLogin($this->c, $request, (int) $result['user_id']);
        return $this->redirect($next);
    }

    public function showRegister(Request $request): Response
    {
        $ref = $this->c->get(ReferenceData::class);
        return $this->view($request, 'auth/register', [
            'title' => t('عضویت رایگان'),
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'old' => [],
            'errors' => [],
            'googleEnabled' => $this->c->get(GoogleAuth::class)->enabled(),
        ], 'layouts/guest');
    }

    /** The Google identity waiting to become an account, or null when there is none (or it is too old). */
    private function googleSignup(): ?array
    {
        $g = Session::get(GoogleController::SIGNUP);
        if (!is_array($g) || time() - (int) ($g['at'] ?? 0) > GoogleController::SIGNUP_WINDOW || !isset($g['sub'], $g['email'])) {
            Session::forget(GoogleController::SIGNUP);
            return null;
        }
        return $g;
    }

    /** Sign-up with Google, step 2: Google gave the name and a verified address; the member adds mobile, country and language. */
    public function showGoogleRegister(Request $request): Response
    {
        $g = $this->googleSignup();
        if ($g === null) {
            return $this->redirect('/register', t('مهلت عضویت با گوگل تمام شد. دوباره روی «ثبت‌نام با گوگل» بزنید.'), 'error');
        }
        $ref = $this->c->get(ReferenceData::class);
        [$first, $last] = [(string) $g['given_name'], (string) $g['family_name']];
        if ($first === '' && $last === '' && $g['name'] !== '') {
            $parts = preg_split('/\s+/u', (string) $g['name'], 2) ?: [];
            [$first, $last] = [$parts[0] ?? '', $parts[1] ?? ''];
        }
        // Country from the visitor's IP / time zone, language from the interface: usually only the mobile is left to type.
        $country = I18n::country() !== null ? $ref->countryByCode((string) I18n::country()) : null;
        $language = $ref->languageByCode(I18n::locale());
        return $this->view($request, 'auth/register', [
            'title' => t('عضویت با گوگل'),
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'old' => [
                'first_name' => $first, 'last_name' => $last,
                'country_id' => $country['id'] ?? '', 'language_id' => $language['id'] ?? '',
            ],
            'errors' => [],
            'google' => $g,
        ], 'layouts/guest');
    }

    public function googleRegister(Request $request): Response
    {
        $g = $this->googleSignup();
        if ($g === null) {
            return $this->redirect('/register', t('مهلت عضویت با گوگل تمام شد. دوباره روی «ثبت‌نام با گوگل» بزنید.'), 'error');
        }
        $ref = $this->c->get(ReferenceData::class);
        [$data, $errors] = Validator::make($request->all(), [
            'first_name' => ['required', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'country_id' => ['required', 'int'],
            'language_id' => ['required', 'int'],
            'phone_cc' => ['required', 'digits', 'max:4'],
            'phone' => ['required', 'digits', 'min:6', 'max:15'],
        ], self::LABELS);
        if (!isset($errors['country_id']) && $ref->country($data['country_id'] ?? null) === null) {
            $errors['country_id'] = t('کشور را از فهرست انتخاب کنید.');
        }
        if (!isset($errors['language_id']) && $ref->language($data['language_id'] ?? null) === null) {
            $errors['language_id'] = t('زبان را از فهرست انتخاب کنید.');
        }

        if ($errors === []) {
            try {
                $userId = $this->c->get(AuthService::class)->registerGoogle($data, (string) $g['sub'], (string) $g['email'], $request->file('avatar'));
                Session::forget(GoogleController::SIGNUP);
                $this->c->get(Auth::class)->login($userId, (bool) ($g['remember'] ?? true));
                // Like a Google sign-in: a password can be set right away in Account › Security, without a current one.
                Session::put('_google_at', time());
                \App\Modules\System\LocaleController::afterLogin($this->c, $request, $userId);
                return $this->redirect(Str::safeNext((string) ($g['next'] ?? '')), t('حساب شما با گوگل ساخته شد. خوش آمدید! از این پس با «ورود با گوگل» وارد شوید.'));
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }

        $old = $request->all();
        unset($old['_token']);
        return $this->view($request, 'auth/register', [
            'title' => t('عضویت با گوگل'),
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'old' => $old,
            'errors' => $errors,
            'google' => $g,
        ], 'layouts/guest', 422);
    }

    public function register(Request $request): Response
    {
        $ref = $this->c->get(ReferenceData::class);
        [$data, $errors] = Validator::make($request->all(), [
            'first_name' => ['required', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'max:128'],
            'password_confirmation' => ['required', 'same:password'],
            'country_id' => ['required', 'int'],
            'language_id' => ['required', 'int'],
            'phone_cc' => ['required', 'digits', 'max:4'],
            'phone' => ['required', 'digits', 'min:6', 'max:15'],
        ], self::LABELS);

        if (!isset($errors['country_id']) && $ref->country($data['country_id'] ?? null) === null) {
            $errors['country_id'] = t('کشور را از فهرست انتخاب کنید.');
        }
        if (!isset($errors['language_id']) && $ref->language($data['language_id'] ?? null) === null) {
            $errors['language_id'] = t('زبان را از فهرست انتخاب کنید.');
        }

        if ($errors === []) {
            try {
                $userId = $this->c->get(AuthService::class)->register($data, $request->file('avatar'));
                $this->c->get(Auth::class)->login($userId, true);
                \App\Modules\System\LocaleController::afterLogin($this->c, $request, $userId);
                return $this->redirect('/dashboard', t('حساب شما ساخته شد. خوش آمدید!'));
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }

        $old = $request->all();
        unset($old['password'], $old['password_confirmation'], $old['_token']);
        return $this->view($request, 'auth/register', [
            'title' => t('عضویت رایگان'),
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'old' => $old,
            'errors' => $errors,
            'googleEnabled' => $this->c->get(GoogleAuth::class)->enabled(),
        ], 'layouts/guest', 422);
    }

    public function logout(Request $request): Response
    {
        $this->c->get(Auth::class)->logout();
        return Response::redirect('/login', 303);
    }
}
