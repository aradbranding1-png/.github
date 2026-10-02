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
    public const NETWORKS = [
        'whatsapp' => 'واتساپ', 'telegram' => 'تلگرام', 'instagram' => 'اینستاگرام', 'linkedin' => 'لینکدین',
        'facebook' => 'فیسبوک', 'x' => 'X', 'youtube' => 'یوتیوب', 'website' => 'وب‌سایت', 'other' => 'سایر',
    ];

    public function show(Request $request, array $errors = [], ?string $tab = null, int $status = 200): Response
    {
        $user = $this->user($request);
        $db = $this->c->get(Connection::class);
        $ref = $this->c->get(ReferenceData::class);
        $profile = $db->first('SELECT * FROM user_profiles WHERE user_id = ?', [$user['id']]) ?? [];
        $socials = array_column(
            $db->select('SELECT network, value, is_public FROM user_socials WHERE user_id = ?', [$user['id']]),
            null,
            'network'
        );
        return $this->view($request, 'account/show', [
            'title' => 'حساب کاربری',
            'profile' => $profile,
            'socials' => $socials,
            'networks' => self::NETWORKS,
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'errors' => $errors,
            'tab' => $tab ?? (string) $request->query('tab', 'profile'),
            'categories' => $this->c->get(\App\Modules\Reference\ReferenceData::class)->categories(),
            'interests' => array_map('intval', array_column($db->select('SELECT category_id FROM user_interests WHERE user_id = ?', [$user['id']]), 'category_id')),
            'input' => $status === 422 ? array_diff_key($request->all(), array_flip(['_token', 'password', 'password_confirmation', 'current_password'])) : [],
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
            'remove_avatar' => ['bool'],
        ], ['first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'phone' => 'شماره تلفن', 'phone_cc' => 'کد کشور',
            'city' => 'شهر', 'company_name' => 'نام شرکت', 'business_area' => 'حوزه فعالیت', 'bio' => 'درباره من']);

        // Profile text is seen by other traders; it may not carry off-platform contact details.
        foreach (['city', 'company_name', 'business_area', 'bio'] as $field) {
            if (!isset($errors[$field]) && is_string($d[$field] ?? null) && ContactGuard::contains($d[$field])) {
                $errors[$field] = ContactGuard::message();
            }
        }
        if (!isset($errors['country_id']) && $ref->country($d['country_id']) === null) {
            $errors['country_id'] = 'کشور را از فهرست انتخاب کنید.';
        }
        if (!isset($errors['language_id']) && $ref->language($d['language_id']) === null) {
            $errors['language_id'] = 'زبان را از فهرست انتخاب کنید.';
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
                    'UPDATE user_profiles SET city = ?, company_name = ?, business_area = ?, bio = ?, updated_at = NOW(3)
                     WHERE user_id = ?',
                    [$d['city'], $d['company_name'], $d['business_area'], $d['bio'], $user['id']]
                );
            });
        } catch (\PDOException $e) {
            $images->delete($newAvatar);
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->show($request, ['phone' => 'این شماره تلفن قبلاً ثبت شده است.'], 'profile', 422);
            }
            throw $e;
        }
        if ($avatar !== $user['avatar_path']) {
            $images->delete($user['avatar_path']);
        }
        $this->c->get(Cache::class)->bump('owner:' . $user['id']);
        return $this->redirect('/account', 'پروفایل ذخیره شد.');
    }

    public function updateSocials(Request $request): Response
    {
        $user = $this->user($request);
        $errors = [];
        $rows = [];
        foreach (array_keys(self::NETWORKS) as $network) {
            $value = trim((string) $request->input("social_{$network}", ''));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 255) {
                $errors["social_{$network}"] = 'حداکثر ۲۵۵ نویسه.';
                continue;
            }
            if (in_array($network, ['website', 'linkedin', 'facebook', 'youtube', 'other'], true)
                && !preg_match('~^https?://[^\s]+$~i', $value)) {
                $errors["social_{$network}"] = 'آدرس باید با https:// شروع شود.';
                continue;
            }
            $rows[] = [$user['id'], $network, $value, $request->input("public_{$network}") ? 1 : 0];
        }
        if ($errors !== []) {
            return $this->show($request, $errors, 'socials', 422);
        }

        $this->c->get(Connection::class)->transaction(function (Connection $db) use ($user, $rows): void {
            $db->exec('DELETE FROM user_socials WHERE user_id = ?', [$user['id']]);
            if ($rows !== []) {
                $db->exec(
                    'INSERT INTO user_socials (user_id, network, value, is_public) VALUES '
                        . implode(',', array_fill(0, count($rows), '(?, ?, ?, ?)')),
                    array_merge(...$rows)
                );
            }
        });
        $this->c->get(Cache::class)->bump('owner:' . $user['id']);
        return $this->redirect('/account?tab=socials', 'شبکه‌های اجتماعی ذخیره شد.');
    }

    public function updatePrivacy(Request $request): Response
    {
        $user = $this->user($request);
        $this->c->get(Connection::class)->exec(
            'UPDATE user_profiles SET show_phone = ?, show_email = ?, accept_public_letters = ?, updated_at = NOW(3) WHERE user_id = ?',
            [
                $request->input('show_phone') ? 1 : 0,
                $request->input('show_email') ? 1 : 0,
                $request->input('accept_public_letters') ? 1 : 0,
                $user['id'],
            ]
        );
        $this->c->get(Cache::class)->bump('owner:' . $user['id']);
        return $this->redirect('/account?tab=privacy', 'تنظیمات حریم خصوصی ذخیره شد.');
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->user($request);
        [$d, $errors] = Validator::make($request->all(), [
            'current_password' => ['required'],
            'password' => ['required', 'min:8', 'max:128'],
            'password_confirmation' => ['required', 'same:password'],
        ], ['current_password' => 'رمز عبور فعلی', 'password' => 'رمز عبور جدید', 'password_confirmation' => 'تکرار رمز عبور']);

        if ($errors === []) {
            $error = $this->c->get(AuthService::class)->changePassword($user['id'], $d['current_password'], $d['password']);
            if ($error === null) {
                Session::regenerate();
                return $this->redirect('/account?tab=security', 'رمز عبور تغییر کرد. سایر دستگاه‌ها از حساب خارج شدند.');
            }
            $errors['current_password'] = $error;
        }
        return $this->show($request, $errors, 'security', 422);
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
            return $this->show($request, ['interests' => 'حداکثر ۵ دسته انتخاب کنید.'], 'interests', 422);
        }
        $this->c->get(Connection::class)->transaction(function (Connection $db) use ($user, $ids): void {
            $db->exec('DELETE FROM user_interests WHERE user_id = ?', [$user['id']]);
            foreach ($ids as $id) {
                $db->exec('INSERT INTO user_interests (user_id, category_id) VALUES (?, ?)', [$user['id'], $id]);
            }
        });
        return $this->redirect('/account?tab=interests', 'علاقه‌مندی‌ها ذخیره شد. فید «برای شما» بر اساس آن‌ها چیده می‌شود.');
    }
}
