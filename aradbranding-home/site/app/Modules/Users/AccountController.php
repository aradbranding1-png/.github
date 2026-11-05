<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\ContactGuard;
use App\Core\Session\Session;
use App\Core\Storage\ImageUploader;
use App\Core\Storage\UploadException;
use App\Core\Support\Str;
use App\Core\Validation\Validator;
use App\Modules\Reference\ReferenceData;

final class AccountController extends Controller
{
    public function show(Request $request, array $errors = [], ?string $tab = null, int $status = 200): Response
    {
        $user = $this->user($request);
        $db = $this->c->get(Connection::class);
        $ref = $this->c->get(ReferenceData::class);
        $profile = $db->first('SELECT * FROM user_profiles WHERE user_id = ?', [$user['id']]) ?? [];
        return $this->view($request, 'account/show', [
            'title' => t('حساب کاربری'),
            'profile' => $profile,
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'errors' => $errors,
            'tab' => $tab ?? (string) $request->query('tab', 'profile'),
            'categories' => $this->c->get(\App\Modules\Reference\ReferenceData::class)->categories(),
            'interests' => array_map('intval', array_column($db->select('SELECT category_id FROM user_interests WHERE user_id = ?', [$user['id']]), 'category_id')),
            'input' => $status === 422 ? array_diff_key($request->all(), array_flip(['_token', 'password', 'password_confirmation', 'current_password'])) : [],
            'google' => $db->first('SELECT google_email, google_sub IS NOT NULL AS linked, google_linked_at FROM users WHERE id = ?', [$user['id']]) ?? [],
            'googleEnabled' => $this->c->get(GoogleAuth::class)->enabled(),
            'googleReset' => self::googleReset(),
        ], 'layouts/app', $status);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->user($request);
        $ref = $this->c->get(ReferenceData::class);
        [$d, $errors] = Validator::make($request->all(), [
            'first_name' => ['required', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'country_id' => ['required', 'int'],
            'language_id' => ['required', 'int'],
            'phone_cc' => ['required', 'digits', 'max:4'],
            'phone' => ['required', 'digits', 'min:6', 'max:15'],
            'city' => ['max:100'],
            'company_name' => ['max:200'],
            'business_area' => ['max:200'],
            'bio' => ['max:2000'],
            'trade_role' => ['max:20'],
            'remove_avatar' => ['bool'],
        ], ['first_name' => t('نام'), 'last_name' => t('نام خانوادگی'), 'phone' => t('شماره تلفن'), 'phone_cc' => t('کد کشور'),
            'city' => t('شهر'), 'company_name' => t('نام شرکت'), 'business_area' => t('حوزه فعالیت'), 'bio' => t('درباره من')]);

        if (!isset(TradeRoles::TRADE[(string) ($d['trade_role'] ?? '')])) {
            $errors['trade_role'] = t('نقش تجاری را از فهرست انتخاب کنید.');
        }
        // Profile text is seen by other traders; it may not carry off-platform contact details.
        foreach (['city', 'company_name', 'business_area', 'bio'] as $field) {
            if (!isset($errors[$field]) && is_string($d[$field] ?? null) && ContactGuard::contains($d[$field])) {
                $errors[$field] = ContactGuard::message();
            }
        }
        if (!isset($errors['country_id']) && $ref->country($d['country_id']) === null) {
            $errors['country_id'] = t('کشور را از فهرست انتخاب کنید.');
        }
        if (!isset($errors['language_id']) && $ref->language($d['language_id']) === null) {
            $errors['language_id'] = t('زبان را از فهرست انتخاب کنید.');
        }

        $images = $this->c->get(ImageUploader::class);
        $newAvatar = null;
        if ($errors === [] && ($file = $request->file('avatar')) !== null) {
            try {
                $newAvatar = $images->store($file, 'avatar');
            } catch (UploadException $e) {
                $errors['avatar'] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            return $this->show($request, $errors, 'profile', 422);
        }

        $avatar = $newAvatar ?? ($d['remove_avatar'] ? null : $user['avatar_path']);
        $db = $this->c->get(Connection::class);
        try {
            $db->transaction(function (Connection $db) use ($user, $d, $avatar): void {
                $db->exec(
                    'UPDATE users SET first_name = ?, last_name = ?, search_name = ?, country_id = ?, language_id = ?,
                            phone_cc = ?, phone = ?, avatar_path = ?, updated_at = NOW(3)
                     WHERE id = ?',
                    [$d['first_name'], $d['last_name'], Str::normalize($d['first_name'] . ' ' . $d['last_name']),
                        $d['country_id'], $d['language_id'], $d['phone_cc'], AuthService::normalizePhone($d['phone']),
                        $avatar, $user['id']]
                );
                $db->exec(
                    'UPDATE user_profiles SET city = ?, company_name = ?, business_area = ?, trade_role = ?, bio = ?, updated_at = NOW(3)
                     WHERE user_id = ?',
                    [$d['city'], $d['company_name'], $d['business_area'], $d['trade_role'], $d['bio'], $user['id']]
                );
            });
        } catch (\PDOException $e) {
            $images->delete($newAvatar);
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->show($request, ['phone' => t('این شماره تلفن قبلاً ثبت شده است.')], 'profile', 422);
            }
            throw $e;
        }
        if ($avatar !== $user['avatar_path']) {
            $images->delete($user['avatar_path']);
        }
        $this->c->get(Cache::class)->bump('owner:' . $user['id']);
        return $this->redirect('/account', t('پروفایل ذخیره شد.'));
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->user($request);
        // Right after a Google sign-in (forgotten password) the current password is not asked for.
        $reset = self::googleReset();
        [$d, $errors] = Validator::make($request->all(), [
            'current_password' => $reset ? [] : ['required'],
            'password' => ['required', 'min:8', 'max:128'],
            'password_confirmation' => ['required', 'same:password'],
        ], ['current_password' => t('رمز عبور فعلی'), 'password' => t('رمز عبور جدید'), 'password_confirmation' => t('تکرار رمز عبور')]);

        if ($errors === []) {
            $service = $this->c->get(AuthService::class);
            if ($reset) {
                $service->setPassword($user['id'], $d['password']);
                Session::forget('_google_at');
                $error = null;
            } else {
                $error = $service->changePassword($user['id'], $d['current_password'], $d['password']);
            }
            if ($error === null) {
                Session::regenerate();
                return $this->redirect('/account?tab=security', t('رمز عبور تغییر کرد. سایر دستگاه‌ها از حساب خارج شدند.'));
            }
            $errors['current_password'] = $error;
        }
        return $this->show($request, $errors, 'security', 422);
    }

    /** Saves the Gmail address that may sign in to this account with Google (bound at its first Google sign-in). */
    public function updateGoogle(Request $request): Response
    {
        $user = $this->user($request);
        $db = $this->c->get(Connection::class);
        if ((int) $db->scalar('SELECT google_sub IS NOT NULL FROM users WHERE id = ?', [$user['id']]) === 1) {
            return $this->show($request, ['google_email' => t('یک حساب گوگل به این حساب وصل است. برای تغییر جیمیل، ابتدا آن را جدا کنید.')], 'security', 422);
        }
        $raw = trim(Str::latinDigits((string) $request->input('google_email', '')));
        if ($raw === '') {
            $db->exec('UPDATE users SET google_email = NULL, updated_at = NOW(3) WHERE id = ?', [$user['id']]);
            return $this->redirect('/account?tab=security', t('جیمیل ورود با گوگل حذف شد.'));
        }
        if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false || mb_strlen($raw) > 190) {
            return $this->show($request, ['google_email' => t('آدرس جیمیل معتبر نیست.')], 'security', 422);
        }
        $email = GoogleAuth::canonical($raw);
        if ($db->scalar('SELECT 1 FROM users WHERE google_email = ? AND google_sub IS NOT NULL AND id <> ? AND deleted_at IS NULL LIMIT 1', [$email, $user['id']]) !== null) {
            return $this->show($request, ['google_email' => t('این جیمیل به حساب دیگری در سامانه وصل است.')], 'security', 422);
        }
        $db->exec('UPDATE users SET google_email = ?, updated_at = NOW(3) WHERE id = ?', [$email, $user['id']]);
        return $this->redirect('/account?tab=security', t('جیمیل ثبت شد. از این پس با «ورود با گوگل» و همین جیمیل وارد شوید؛ اولین ورود، حساب گوگل را به حساب شما وصل می‌کند.'));
    }

    public function unlinkGoogle(Request $request): Response
    {
        $user = $this->user($request);
        $this->c->get(Connection::class)->exec('UPDATE users SET google_sub = NULL, google_email = NULL, google_linked_at = NULL, updated_at = NOW(3) WHERE id = ?', [$user['id']]);
        return $this->redirect('/account?tab=security', t('حساب گوگل جدا شد. ورود فقط با ایمیل/موبایل و رمز عبور ممکن است.'));
    }

    private static function googleReset(): bool
    {
        return time() - (int) Session::get('_google_at', 0) < GoogleController::RESET_WINDOW;
    }

    public function updateInterests(Request $request): Response
    {
        $user = $this->user($request);
        $valid = $this->c->get(ReferenceData::class)->categories();
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('interests', [])),
            static fn (int $id): bool => isset($valid[$id])
        )));
        if (count($ids) > 5) {
            return $this->show($request, ['interests' => t('حداکثر ۵ دسته انتخاب کنید.')], 'interests', 422);
        }
        $this->c->get(Connection::class)->transaction(function (Connection $db) use ($user, $ids): void {
            $db->exec('DELETE FROM user_interests WHERE user_id = ?', [$user['id']]);
            foreach ($ids as $id) {
                $db->exec('INSERT INTO user_interests (user_id, category_id) VALUES (?, ?)', [$user['id'], $id]);
            }
        });
        return $this->redirect('/account?tab=interests', t('علاقه‌مندی‌ها ذخیره شد. فید «برای شما» بر اساس آن‌ها چیده می‌شود.'));
    }
}
