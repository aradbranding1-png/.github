<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Targeting;
use App\Services\UserService;

final class AuthController
{
    public function loginForm(): string
    {
        return view('auth/login', ['title' => 'ورود'], 'layouts/bare');
    }

    public function login(): never
    {
        $id = Request::str('identifier');
        $pass = (string)($_POST['password'] ?? '');
        $ipKey = 'login-ip:' . Request::ip();
        $idKey = Auth::loginKey($id);
        // per-IP limit is generous: many users share one IP (office network, mobile operator, CDN)
        if (RateLimiter::tooMany($idKey, Auth::MAX_TRIES) || RateLimiter::tooMany($ipKey, 60)) {
            $min = max(1, Auth::lockedMinutes($id));
            Auth::failed($id, Auth::findByIdentifier($id)['id'] ?? null, 'locked');
            flash('danger', 'به دلیل چند بار ورود ناموفق، ورود با این حساب موقتاً مسدود شده است. حدود ' . fa($min) . ' دقیقه دیگر دوباره تلاش کنید یا از پشتیبانی بخواهید مسدودی را بردارد.');
            keep_old(['identifier' => $id]);
            redirect('/login');
        }
        $res = $id !== '' ? Auth::attempt($id, $pass) : ['user' => null, 'reason' => 'not_found'];
        $user = $res['reason'] === 'ok' ? $res['user'] : null;
        if (!$user) {
            RateLimiter::hit($ipKey, 60, 900);
            RateLimiter::hit($idKey, Auth::MAX_TRIES, 900);
            Auth::failed($id, $res['user'] ? (int)$res['user']['id'] : null, $res['reason']);
            usleep(random_int(150000, 400000));
            flash('danger', match ($res['reason']) {
                'no_password' => 'برای این حساب هنوز رمز عبور تعیین نشده است. برای دریافت رمز با پشتیبانی آراد برندینگ تماس بگیرید.',
                'not_found' => 'حسابی با این مشخصات پیدا نشد. همان شماره موبایلی را وارد کنید که با آن ثبت‌نام شده‌اید (مثل 09121234567).',
                default => 'رمز عبور اشتباه است. به کوچک و بزرگ بودن حروف انگلیسی دقت کنید.',
            });
            keep_old(['identifier' => $id]);
            redirect('/login');
        }
        if ($user['status'] !== 'active') {
            Auth::failed($id, (int)$user['id'], $user['status'] === 'pending' ? 'pending' : 'inactive');
            flash('warning', self::statusMessage($user['status']));
            redirect('/login');
        }
        RateLimiter::clear($idKey);
        clear_old();
        Auth::login($user, 'password', ($_POST['remember'] ?? '') === '1');
        $to = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        redirect(is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
    }

    /** Forced password change (account created with a generated password) */
    public function passwordChangeForm(): string
    {
        $me = \App\Core\Auth::user();
        if ((int)($me['must_change_password'] ?? 0) !== 1) redirect('/profile');
        return view('auth/password_change', ['title' => 'تعیین رمز عبور جدید', 'me' => $me], 'layouts/bare');
    }

    public function passwordChange(): never
    {
        $me = Auth::user();
        if ((int)($me['must_change_password'] ?? 0) !== 1) redirect('/profile');
        $new = (string)($_POST['password'] ?? '');
        if ($new !== (string)($_POST['password_confirmation'] ?? '')) { flash('danger', 'رمز جدید و تکرار آن یکسان نیستند.'); redirect('/password/change'); }
        if (!Auth::passwordStrongEnough($new)) { flash('danger', 'رمز جدید باید حداقل ۸ کاراکتر و شامل حرف انگلیسی و عدد باشد.'); redirect('/password/change'); }
        if (Auth::verifyPassword($me, $new)) { flash('danger', 'رمز جدید باید با رمز موقت متفاوت باشد.'); redirect('/password/change'); }
        DB::update('users', ['password_hash' => Auth::hashPassword($new), 'must_change_password' => 0, 'updated_at' => now()], 'id = ?', [(int)$me['id']]);
        \App\Services\AradContact::forgetPassword((int)$me['id']);
        \App\Core\RememberMe::forgetUser((int)$me['id'], true);
        Audit::log('auth.password_forced_change', 'user', (int)$me['id']);
        Auth::refresh();
        flash('success', 'رمز عبور شما ثبت شد. از این پس با همین رمز وارد شوید.');
        redirect('/');
    }

    /** Friendly message for accounts that cannot sign in */
    public static function statusMessage(string $status): string
    {
        return $status === 'pending'
            ? 'حساب کاربری شما ثبت شده و در انتظار تأیید است. پس از بررسی و تأیید توسط مدیر سامانه، امکان ورود برای شما فعال می‌شود.'
            : 'حساب کاربری شما غیرفعال است. برای پیگیری با پشتیبانی آراد برندینگ تماس بگیرید.';
    }

    public function logout(): never
    {
        Auth::logout();
        redirect('/login');
    }

    public function registerForm(): string
    {
        if (setting('registration_enabled') !== '1') { flash('info', 'ثبت‌نام آنلاین در حال حاضر فعال نیست. برای ایجاد حساب کاربری با پشتیبانی آراد برندینگ تماس بگیرید.'); redirect('/login'); }
        $segments = array_intersect_key(\App\Core\Labels::SEGMENT_ONE, array_flip(explode(',', (string)setting('registration_segments'))));
        return view('auth/register', ['title' => 'ثبت‌نام', 'segments' => $segments], 'layouts/bare');
    }

    public function register(): never
    {
        if (setting('registration_enabled') !== '1') { flash('info', 'ثبت‌نام آنلاین در حال حاضر فعال نیست. برای ایجاد حساب کاربری با پشتیبانی آراد برندینگ تماس بگیرید.'); redirect('/login'); }
        if (!RateLimiter::hit('register:' . Request::ip(), 5, 3600)) { flash('danger', 'تعداد درخواست ثبت‌نام بیش از حد مجاز است.'); redirect('/register'); }
        $allowed = implode(',', explode(',', (string)setting('registration_segments')));
        $d = Validator::validate([
            'first_name' => 'required|max:100', 'last_name' => 'required|max:100',
            'mobile' => 'required|mobile|unique:users,mobile', 'email' => 'email|max:190|unique:users,email',
            'segment' => 'required|in:' . $allowed, 'password' => 'required|min:8|confirmed',
        ], ['first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'mobile' => 'موبایل', 'email' => 'ایمیل', 'segment' => 'نوع کاربری', 'password' => 'رمز عبور']);
        if (!Auth::passwordStrongEnough((string)$_POST['password'])) { keep_old($d); flash('danger', 'رمز عبور باید حداقل ۸ کاراکتر و شامل حرف و عدد باشد.'); redirect('/register'); }
        if (mobile_taken((string)$d['mobile'])) { keep_old($d); flash('danger', 'با این شماره موبایل قبلاً حساب ساخته شده است. از صفحه ورود وارد شوید یا با پشتیبانی تماس بگیرید.'); redirect('/register'); }
        $pending = setting('registration_requires_approval') === '1' || $d['segment'] === 'employee';
        $d['password'] = (string)$_POST['password'];
        $d['status'] = $pending ? 'pending' : 'active';
        $id = UserService::create($d);
        Audit::log('auth.register', 'user', $id, 'success', ['segment' => $d['segment']]);
        if ($pending || DB::value('SELECT status FROM users WHERE id = ?', [$id]) !== 'active') {
            flash('success', 'ثبت‌نام شما با موفقیت انجام شد. حساب کاربری شما پس از بررسی و تأیید مدیر سامانه فعال می‌شود و سپس می‌توانید وارد شوید.');
            redirect('/login');
        }
        Targeting::syncUser($id);
        Auth::login(DB::find('users', $id), 'register');
        flash('success', 'به سامانه آموزش آراد برندینگ خوش آمدید!');
        redirect('/');
    }

    public function stopImpersonation(): never
    {
        if (!Auth::isImpersonating()) redirect('/');
        $target = Auth::id();
        Auth::stopImpersonation();
        flash('success', 'به حساب مدیر کل بازگشتید.');
        redirect('/admin/users/' . $target);
    }
}
