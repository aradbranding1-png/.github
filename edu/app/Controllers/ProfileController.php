<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Upload;
use App\Core\Validator;
use App\Services\SsoClient;
use App\Services\UserReport;

final class ProfileController
{
    public function show(): string
    {
        $me = Auth::user();
        $roles = DB::all('SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [(int)$me['id']]);
        $groups = DB::all('SELECT g.* FROM `groups` g JOIN group_members gm ON gm.group_id = g.id WHERE gm.user_id = ?', [(int)$me['id']]);
        $org = DB::all('SELECT o.name, t.name AS type_name FROM user_org_units x JOIN org_units o ON o.id = x.org_unit_id JOIN org_unit_types t ON t.id = o.type_id WHERE x.user_id = ?', [(int)$me['id']]);
        return view('profile/show', ['title' => 'پروفایل من', 'me' => $me, 'roles' => $roles, 'groups' => $groups, 'org' => $org, 'sso' => SsoClient::enabled()]);
    }

    public function update(): never
    {
        $me = Auth::user();
        $d = Validator::validate([
            'first_name' => 'required|max:100', 'last_name' => 'required|max:100',
            'email' => 'email|max:190|unique:users,email,' . (int)$me['id'], 'job_title' => 'max:150', 'bio' => 'max:1000',
        ], ['first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'email' => 'ایمیل', 'job_title' => 'عنوان شغلی', 'bio' => 'درباره من']);
        DB::update('users', ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'email' => ($d['email'] ?? '') !== '' ? strtolower($d['email']) : null, 'job_title' => $d['job_title'] ?? null, 'bio' => $d['bio'] ?? null, 'updated_at' => now()], 'id = ?', [(int)$me['id']]);
        flash('success', 'پروفایل به‌روزرسانی شد.');
        redirect('/profile');
    }

    public function password(): never
    {
        $me = Auth::user();
        if ($me['password_hash'] && !Auth::verifyPassword($me, (string)($_POST['current_password'] ?? ''))) { flash('danger', 'رمز عبور فعلی صحیح نیست.'); redirect('/profile'); }
        $new = (string)($_POST['password'] ?? '');
        if ($new !== (string)($_POST['password_confirmation'] ?? '') || !Auth::passwordStrongEnough($new)) { flash('danger', 'رمز جدید باید حداقل ۸ کاراکتر، شامل حرف و عدد و با تکرار آن یکسان باشد.'); redirect('/profile'); }
        DB::update('users', ['password_hash' => Auth::hashPassword($new), 'must_change_password' => 0, 'updated_at' => now()], 'id = ?', [(int)$me['id']]);
        \App\Services\AradContact::forgetPassword((int)$me['id']);
        Audit::log('profile.password', 'user', (int)$me['id']);
        flash('success', 'رمز عبور تغییر کرد.');
        redirect('/profile');
    }

    public function avatar(): never
    {
        $me = Auth::user();
        $f = Request::file('avatar');
        if (!$f) { flash('danger', 'تصویری انتخاب نشده است.'); redirect('/profile'); }
        $path = Upload::avatar($f);
        $this->removeOldAvatar($me);
        DB::update('users', ['avatar_path' => $path, 'avatar_source' => 'edu', 'updated_at' => now()], 'id = ?', [(int)$me['id']]);
        flash('success', 'تصویر پروفایل به‌روزرسانی شد. (این تغییر فقط در سامانه آموزش اعمال می‌شود و تصویر شما در my تغییر نمی‌کند)');
        redirect('/profile');
    }

    public function avatarSource(): never
    {
        $me = Auth::user();
        $src = Request::str('source');
        if ($src === 'my') {
            if (!$me['my_avatar_url']) { flash('warning', 'تصویری در my.aradbranding.me برای شما ثبت نشده است.'); redirect('/profile'); }
            $path = SsoClient::downloadAvatar((string)$me['my_avatar_url']);
            if (!$path) { flash('danger', 'دریافت تصویر از my ناموفق بود.'); redirect('/profile'); }
            $this->removeOldAvatar($me);
            DB::update('users', ['avatar_path' => $path, 'avatar_source' => 'my'], 'id = ?', [(int)$me['id']]);
            flash('success', 'تصویر پروفایل از my.aradbranding.me همگام شد.');
        } elseif ($src === 'none') {
            $this->removeOldAvatar($me);
            DB::update('users', ['avatar_path' => null, 'avatar_source' => 'none'], 'id = ?', [(int)$me['id']]);
            flash('success', 'تصویر پروفایل حذف شد.');
        }
        redirect('/profile');
    }

    private function removeOldAvatar(array $u): void
    {
        if (!empty($u['avatar_path']) && str_starts_with((string)$u['avatar_path'], 'avatars/')) @unlink(STORAGE_PATH . '/' . basename(dirname((string)$u['avatar_path'])) . '/' . basename((string)$u['avatar_path']));
    }

    public function linkMy(): never
    {
        if (!SsoClient::enabled()) { flash('warning', 'اتصال به my.aradbranding.me هنوز فعال نشده است.'); redirect('/profile'); }
        header('Location: ' . SsoClient::authorizeUrl('link'));
        exit;
    }

    public function report(): string
    {
        $R = UserReport::build((int)Auth::id(), Request::str('view', 'month'), Request::intOrNull('y'), Request::intOrNull('m'), Request::intOrNull('d'));
        $needs = DB::all('SELECT n.*, c.name AS category_name FROM training_needs n LEFT JOIN categories c ON c.id = n.category_id WHERE n.user_id = ? ORDER BY n.id DESC', [(int)Auth::id()]);
        $cats = DB::pairs('SELECT id, name FROM categories ORDER BY sort, name');
        return view('profile/report', ['title' => 'گزارش فعالیت من', 'R' => $R, 'needs' => $needs, 'cats' => $cats]);
    }

    public function addNeed(): never
    {
        $d = Validator::validate(['title' => 'required|max:200', 'description' => 'max:2000'], ['title' => 'عنوان نیاز', 'description' => 'توضیحات']);
        DB::insert('training_needs', ['user_id' => (int)Auth::id(), 'title' => $d['title'], 'description' => $d['description'] ?? null, 'category_id' => Request::intOrNull('category_id'), 'priority' => 'medium', 'status' => 'open', 'source' => 'self', 'created_by' => Auth::id(), 'created_at' => now()]);
        flash('success', 'نیاز آموزشی شما ثبت شد و برای پیشنهاد دوره‌ها استفاده می‌شود.');
        redirect('/me/report');
    }
}
