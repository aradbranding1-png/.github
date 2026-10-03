<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Migrator;
use App\Core\Request;
use App\Controllers\FileController;
use App\Services\Backup;
use App\Services\Health;
use App\Services\Updater;

/** Backups, migrations and the in-panel update system */
final class MaintenanceController
{
    /** Sensitive actions require re-entering the root password */
    private function confirmPassword(): void
    {
        $me = Auth::user();
        if (!Auth::verifyPassword($me, (string)($_POST['confirm_password'] ?? ''))) {
            Audit::log('security.reauth_failed', 'user', (int)$me['id'], 'fail', ['path' => Request::path()]);
            flash('danger', 'رمز عبور تأیید صحیح نیست.');
            back();
        }
    }

    public function backups(): string
    {
        $rows = DB::all('SELECT b.*, u.first_name, u.last_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC');
        foreach ($rows as &$r) $r['exists'] = is_file(Backup::path($r));
        unset($r);
        return view('admin/system/backups', ['title' => 'پشتیبان‌گیری', 'rows' => $rows, 'free' => @disk_free_space(STORAGE_PATH)]);
    }

    public function createBackup(): never
    {
        $type = Request::str('type', 'db');
        if ($type === 'full' && !is_root()) $type = 'db';
        $b = Backup::create($type, Request::str('note'));
        flash('success', 'پشتیبان «' . $b['filename'] . '» (' . human_size($b['size']) . ') ایجاد شد.');
        redirect('/admin/system/backups');
    }

    public function deleteBackup(int $id): never
    {
        $b = DB::find('backups', $id) ?? throw new HttpException(404);
        @unlink(Backup::path($b));
        DB::delete('backups', 'id = ?', [$id]);
        Audit::log('backups.delete', 'backup', $id, 'success', ['file' => $b['filename']]);
        flash('success', 'پشتیبان حذف شد.');
        redirect('/admin/system/backups');
    }

    public function downloadBackup(int $id): void
    {
        $this->confirmPassword();
        $b = DB::find('backups', $id) ?? throw new HttpException(404);
        $path = Backup::path($b);
        if (!is_file($path)) throw new HttpException(404);
        Audit::log('backups.download', 'backup', $id, 'success', ['file' => $b['filename']]);
        FileController::stream($path, 'application/zip', (string)$b['filename'], true);
    }

    public function restoreBackup(int $id): never
    {
        $this->confirmPassword();
        $b = DB::find('backups', $id) ?? throw new HttpException(404);
        if (!is_file(Backup::path($b))) throw new HttpException(404);
        file_put_contents(STORAGE_PATH . '/maintenance.flag', 'restore');
        try {
            $safety = Backup::create('db', 'پیش از بازیابی پشتیبان #' . $id);
            $keep = Updater::preserveRows();
            $n = Backup::restoreDatabase($b);
            Updater::restoreRows($keep);
        } finally {
            @unlink(STORAGE_PATH . '/maintenance.flag');
        }
        Audit::log('backups.restore', 'backup', $id, 'success', ['statements' => $n, 'safety_backup' => $safety['filename']]);
        flash('success', 'دیتابیس از پشتیبان بازیابی شد (' . fa($n) . ' دستور). یک نسخه ایمنی از وضعیت قبلی نیز با نام «' . $safety['filename'] . '» ذخیره شد.');
        redirect('/admin/system/backups');
    }

    public function migrations(): string
    {
        return view('admin/system/migrations', ['title' => 'Migrationها', 'rows' => Migrator::status()]);
    }

    public function runMigrations(): never
    {
        $res = Migrator::migrate();
        Audit::log('migrations.run', 'system', null, $res['failed'] ? 'fail' : 'success', $res);
        if ($res['failed']) flash('danger', 'Migration «' . e($res['failed']) . '» ناموفق بود: ' . e($res['error']), true);
        else flash('success', $res['ran'] ? fa(count($res['ran'])) . ' Migration اجرا شد.' : 'Migration اجرانشده‌ای وجود ندارد.');
        redirect('/admin/system/migrations');
    }

    // ------------------------------------------------------------------ updates
    public function updates(): string
    {
        $rows = DB::all('SELECT s.*, u.first_name, u.last_name, b.filename AS backup_file FROM system_updates s LEFT JOIN users u ON u.id = s.started_by LEFT JOIN backups b ON b.id = s.backup_id ORDER BY s.id DESC');
        return view('admin/system/updates', ['title' => 'بروزرسانی سامانه', 'rows' => $rows, 'maintenance' => is_file(STORAGE_PATH . '/maintenance.flag'),
            'limits' => ['upload' => ini_get('upload_max_filesize'), 'post' => ini_get('post_max_size')]]);
    }

    public function uploadUpdate(): never
    {
        $f = Request::file('package');
        if (!$f) { flash('danger', 'فایل ZIP بسته بروزرسانی را انتخاب کنید.'); redirect('/admin/system/updates'); }
        $id = Updater::upload($f);
        redirect('/admin/system/updates/' . $id);
    }

    public function updatePlan(int $id): string
    {
        $u = DB::find('system_updates', $id) ?? throw new HttpException(404);
        $v = json_decode((string)$u['plan_json'], true) ?: ['errors' => [], 'warnings' => [], 'plan' => [], 'manifest' => []];
        $backup = $u['backup_id'] ? DB::find('backups', (int)$u['backup_id']) : null;
        return view('admin/system/update_plan', ['title' => 'بروزرسانی #' . $id, 'u' => $u, 'v' => $v, 'backup' => $backup, 'health' => $u['status'] === 'validated' ? Health::run() : [],
            'healthBefore' => json_decode((string)$u['health_before'], true) ?: []]);
    }

    public function applyUpdate(int $id): never
    {
        $this->confirmPassword();
        $status = Updater::apply($id, Request::bool('with_backup'));
        if ($status === 'success') flash('success', 'بروزرسانی با موفقیت انجام شد. نسخه فعلی: ' . trim((string)file_get_contents(BASE_PATH . '/VERSION')));
        elseif ($status === 'rolled_back') flash('danger', 'بروزرسانی با خطا مواجه شد و سامانه به وضعیت قبل بازگردانده شد. جزئیات در گزارش همین صفحه.');
        else flash('danger', 'بروزرسانی انجام نشد. جزئیات را بررسی کنید.');
        redirect('/admin/system/updates/' . $id);
    }

    public function rollbackUpdate(int $id): never
    {
        $this->confirmPassword();
        Updater::rollback($id, Request::bool('restore_db'));
        flash('success', 'بروزرسانی بازگردانی شد.');
        redirect('/admin/system/updates/' . $id);
    }

    public function discardUpdate(int $id): never
    {
        $u = DB::find('system_updates', $id) ?? throw new HttpException(404);
        if (in_array($u['status'], ['uploaded', 'validated', 'fail'], true)) {
            @unlink(Updater::dir('packages') . '/' . $id . '.zip');
            DB::update('system_updates', ['status' => $u['status'] === 'fail' ? 'fail' : 'denied', 'finished_at' => now()], 'id = ?', [$id]);
            Audit::log('updates.discard', 'update', $id);
            flash('success', 'بسته کنار گذاشته شد.');
        }
        redirect('/admin/system/updates');
    }

    public function maintenance(): never
    {
        $f = STORAGE_PATH . '/maintenance.flag';
        if (Request::bool('on')) file_put_contents($f, 'manual'); else @unlink($f);
        Audit::log('system.maintenance', 'system', null, 'success', ['on' => Request::bool('on')]);
        flash('success', Request::bool('on') ? 'حالت تعمیر فعال شد؛ فقط مدیر کل به سامانه دسترسی دارد.' : 'حالت تعمیر غیرفعال شد.');
        redirect('/admin/system/updates');
    }
}
