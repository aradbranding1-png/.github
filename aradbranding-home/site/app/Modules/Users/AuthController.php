<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Auth\Auth;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
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
        ], 'layouts/guest');
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
        ], 'layouts/guest', 422);
    }

    public function logout(Request $request): Response
    {
        $this->c->get(Auth::class)->logout();
        return Response::redirect('/login', 303);
    }
}
