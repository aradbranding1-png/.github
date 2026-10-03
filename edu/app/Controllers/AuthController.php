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
use App\Services\SsoClient;
use App\Services\Targeting;
use App\Services\UserService;

final class AuthController
{
    public function loginForm(): string
    {
        return view('auth/login', ['title' => 'ورود', 'sso' => SsoClient::enabled()], 'layouts/bare');
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
                'no_password' => 'برای این حساب هنوز رمز عبور تعیین نشده است. از دکمه «ورود با my» استفاده کنید یا از پشتیبانی بخواهید برایتان رمز تعیین کند.',
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
        Auth::login($user, 'password');
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
        $ssoLogout = (string)setting('sso_logout_url');
        $wasSso = !empty($_SESSION['sso_token']);
        Auth::logout();
        if ($wasSso && $ssoLogout !== '' && SsoClient::enabled()) {
            header('Location: ' . $ssoLogout . (str_contains($ssoLogout, '?') ? '&' : '?') . 'post_logout_redirect_uri=' . rawurlencode(url('/login')));
            exit;
        }
        redirect('/login');
    }

    public function registerForm(): string
    {
        if (setting('registration_enabled') !== '1') { flash('info', 'ثبت‌نام آنلاین در حال حاضر فعال نیست. برای ایجاد حساب کاربری با پشتیبانی آراد برندینگ تماس بگیرید.'); redirect('/login'); }
        $segments = array_intersect_key(\App\Core\Labels::SEGMENT_ONE, array_flip(explode(',', (string)setting('registration_segments'))));
        return view('auth/register', ['title' => 'ثبت‌نام', 'segments' => $segments, 'sso' => SsoClient::enabled()], 'layouts/bare');
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

    // ------------------------------------------------------------------ SSO

    public function ssoRedirect(): never
    {
        if (!SsoClient::enabled()) throw new HttpException(404);
        header('Location: ' . SsoClient::authorizeUrl(Auth::check() ? 'link' : 'login'));
        exit;
    }

    public function ssoCallback(): never
    {
        if (!SsoClient::enabled()) throw new HttpException(404);
        $p = SsoClient::handleCallback($_GET);
        if (!$p['active']) { flash('danger', 'حساب شما در my.aradbranding.me فعال نیست.'); redirect('/login'); }

        // 1) already linked account
        $linked = DB::one('SELECT * FROM users WHERE my_user_id = ? AND deleted_at IS NULL', [$p['id']]);
        if ($p['intent'] === 'link' && Auth::check()) {
            $me = Auth::user();
            if ($linked && (int)$linked['id'] !== (int)$me['id']) { flash('danger', 'این حساب my قبلاً به کاربر دیگری متصل شده است. برای ادغام با پشتیبانی تماس بگیرید.'); redirect('/profile'); }
            $this->link((int)$me['id'], $p);
            flash('success', 'حساب شما با موفقیت به my.aradbranding.me متصل شد.');
            redirect('/profile');
        }
        if ($linked) {
            if ($linked['status'] !== 'active') { flash('warning', self::statusMessage((string)$linked['status'])); redirect('/login'); }
            $this->sync($linked, $p);
            Auth::login(DB::find('users', (int)$linked['id']), 'sso');
            redirect('/');
        }
        // 2) a local account with same mobile/email exists → require proof of ownership (no silent merge)
        $candidate = null;
        if ($p['mobile'] && ($t2 = mobile_taken((string)$p['mobile']))) $candidate = DB::find('users', (int)$t2['id']);
        if (!$candidate && $p['email']) $candidate = DB::one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$p['email']]);
        if ($candidate) {
            $_SESSION['sso_pending'] = ['profile' => $p, 'user_id' => (int)$candidate['id'], 'at' => time()];
            redirect('/sso/link');
        }
        // 3) new user
        if (setting('sso_auto_register') !== '1') { flash('warning', 'حساب شما در سامانه آموزش تعریف نشده است. با پشتیبانی تماس بگیرید.'); redirect('/login'); }
        $id = UserService::create([
            'first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'mobile' => $p['mobile'], 'email' => $p['email'],
            'segment' => (string)setting('sso_default_segment', 'merchant'), 'my_user_id' => $p['id'], 'status' => 'active',
        ]);
        $u = DB::find('users', $id);
        $this->sync($u, $p);
        Audit::log('sso.register', 'user', $id, 'success');
        if ($u['status'] !== 'active') { flash('success', 'حساب شما از my.aradbranding.me ایجاد شد. ' . self::statusMessage((string)$u['status'])); redirect('/login'); }
        Targeting::syncUser($id);
        Auth::login(DB::find('users', $id), 'sso');
        flash('success', 'به سامانه آموزش خوش آمدید! حساب شما از my.aradbranding.me ایجاد شد.');
        redirect('/');
    }

    public function ssoLinkForm(): string
    {
        $pending = $_SESSION['sso_pending'] ?? null;
        if (!$pending || time() - $pending['at'] > 900) throw new HttpException(404);
        $u = DB::find('users', (int)$pending['user_id']);
        return view('auth/sso_link', ['title' => 'اتصال حساب', 'profile' => $pending['profile'], 'user' => $u], 'layouts/bare');
    }

    public function ssoLink(): never
    {
        $pending = $_SESSION['sso_pending'] ?? null;
        if (!$pending || time() - $pending['at'] > 900) throw new HttpException(419);
        $u = DB::find('users', (int)$pending['user_id']);
        $key = 'sso-link:' . $pending['user_id'];
        if (!$u || !RateLimiter::hit($key, 5, 900)) { unset($_SESSION['sso_pending']); flash('danger', 'تلاش‌های ناموفق زیاد. بعداً دوباره تلاش کنید.'); redirect('/login'); }
        if (!Auth::verifyPassword($u, (string)($_POST['password'] ?? ''))) {
            Auth::failed((string)$u['mobile'], (int)$u['id']);
            flash('danger', 'رمز عبور حساب سامانه آموزش صحیح نیست.');
            redirect('/sso/link');
        }
        unset($_SESSION['sso_pending']);
        $this->link((int)$u['id'], $pending['profile']);
        if ($u['status'] !== 'active') { flash('warning', 'حساب‌ها متصل شدند. ' . self::statusMessage((string)$u['status'])); redirect('/login'); }
        Auth::login(DB::find('users', (int)$u['id']), 'sso');
        flash('success', 'حساب‌ها متصل شدند. از این پس با حساب my وارد شوید؛ همه سوابق آموزشی شما حفظ شده است.');
        redirect('/');
    }

    private function link(int $userId, array $p): void
    {
        DB::update('users', ['my_user_id' => $p['id'], 'my_linked_at' => now()], 'id = ?', [$userId]);
        Audit::log('sso.link', 'user', $userId, 'success', ['my_user_id' => $p['id']]);
        $this->sync(DB::find('users', $userId), $p);
    }

    /** Sync profile fields from my. Local custom avatar is never overwritten; edu never writes back to my. */
    private function sync(array $u, array $p): void
    {
        $upd = ['my_synced_at' => now()];
        if ($p['first_name'] !== '' && $u['first_name'] === '') $upd['first_name'] = $p['first_name'];
        if ($p['last_name'] !== '' && $u['last_name'] === '') $upd['last_name'] = $p['last_name'];
        if ($p['email'] && empty($u['email']) && !DB::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$p['email'], $u['id']])) $upd['email'] = $p['email'];
        if ($p['mobile'] && empty($u['mobile']) && !DB::value('SELECT 1 FROM users WHERE mobile = ? AND id <> ?', [$p['mobile'], $u['id']])) $upd['mobile'] = $p['mobile'];
        $upd['my_avatar_url'] = $p['avatar'];
        if (setting('sso_sync_avatar') === '1' && $p['avatar'] && in_array($u['avatar_source'], ['none', 'my'], true) && ($u['my_avatar_url'] !== $p['avatar'] || empty($u['avatar_path']))) {
            $path = SsoClient::downloadAvatar($p['avatar']);
            if ($path) { $upd['avatar_path'] = $path; $upd['avatar_source'] = 'my'; }
        }
        DB::update('users', $upd, 'id = ?', [(int)$u['id']]);
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
