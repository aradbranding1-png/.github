<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Auth\Password;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Session\Session;
use App\Core\Update\UpdateException;
use App\Core\Update\UpdateManager;

/** /admin/system-update — upload a release ZIP and update in place. Requires updates.manage + password. */
final class SystemUpdateController extends Controller
{
    public function show(Request $request, array $errors = [], int $status = 200, ?array $result = null): Response
    {
        $manager = $this->c->get(UpdateManager::class);
        return $this->view($request, 'admin/system_update', [
            'title' => 'بروزرسانی سامانه',
            'version' => $manager->currentVersion(),
            'history' => $manager->history(),
            'result' => $result ?? Session::get('_update_result'),
            'errors' => $errors,
            'uploadLimit' => ini_get('upload_max_filesize') ?: '?',
        ], 'layouts/app', $status);
    }

    public function upload(Request $request): Response
    {
        $admin = $this->user($request);
        $hash = (string) $this->c->get(Connection::class)->scalar('SELECT password_hash FROM users WHERE id = ?', [$admin['id']]);
        if (!Password::verify((string) $request->input('password', ''), $hash)) {
            $this->c->get(Audit::class)->log('system.update', $admin['id'], null, null, 'denied', $request, ['reason' => 'password']);
            return $this->show($request, ['password' => 'رمز عبور درست نیست.'], 422);
        }
        $file = $request->file('package');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $msg = ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
                ? 'حجم فایل از سقف آپلود سرور (' . ini_get('upload_max_filesize') . ') بیشتر است.'
                : 'فایل ZIP را انتخاب کنید.';
            return $this->show($request, ['package' => $msg], 422);
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            return $this->show($request, ['package' => 'فقط فایل ZIP پذیرفته می‌شود.'], 422);
        }
        $manager = $this->c->get(UpdateManager::class);
        try {
            $result = $manager->apply($file['tmp_name'], (int) $admin['id'], (bool) $request->input('full_backup'));
        } catch (UpdateException $e) {
            return $this->show($request, ['package' => $e->getMessage()], 422);
        }
        $this->c->get(Audit::class)->log('system.update', $admin['id'], null, null, $result['ok'] ? 'success' : 'failed', $request,
            ['from' => $result['from'], 'to' => $result['to']]);

        // Optional: publish the release note (from the package's CHANGELOG) right away.
        if ($result['ok'] && $request->input('publish_note')) {
            $announce = (bool) $request->input('announce');
            $title = 'بروزرسانی نسخه ' . $result['to'];
            $body = $result['notes'] !== '' ? $result['notes'] : 'سامانه به نسخه ' . $result['to'] . ' بروزرسانی شد.';
            $this->c->get(Connection::class)->insert(
                'INSERT INTO system_updates (version, title, body, visible, announced, created_by, published_at) VALUES (?, ?, ?, 1, ?, ?, NOW(3))',
                [$result['to'], $title, $body, $announce ? 1 : 0, $admin['id']]
            );
            if ($announce) {
                $this->c->get(\App\Modules\Letters\AnnouncementService::class)->create((int) $admin['id'], 'بروزرسانی سامانه: ' . $title, $body);
            }
            $result['note_published'] = true;
            $result['message'] .= $announce
                ? ' یادداشت «تازه‌های سامانه» منتشر و برای همه کاربران نامه رسمی ارسال شد.'
                : ' یادداشت «تازه‌های سامانه» منتشر شد.';
        }
        Session::put('_update_result', $result);
        return $this->redirect('/admin/system-update', $result['message'], $result['ok'] ? 'success' : 'error');
    }

    /** Builds a ZIP of the current code, streams it, and deletes it from the host as soon as the transfer ends. */
    public function export(Request $request): Response
    {
        $admin = $this->user($request);
        $hash = (string) $this->c->get(Connection::class)->scalar('SELECT password_hash FROM users WHERE id = ?', [$admin['id']]);
        if (!Password::verify((string) $request->input('password', ''), $hash)) {
            $this->c->get(Audit::class)->log('system.export', $admin['id'], null, null, 'denied', $request, ['reason' => 'password']);
            return $this->show($request, ['export_password' => 'رمز عبور درست نیست.'], 422);
        }
        $dir = BASE_PATH . '/storage/framework';
        $tmp = $dir . '/export-' . bin2hex(random_bytes(8)) . '.zip';
        $manager = $this->c->get(UpdateManager::class);
        try {
            $files = $manager->exportCurrent($tmp);
        } catch (UpdateException $e) {
            @unlink($tmp);
            return $this->show($request, ['export' => $e->getMessage()], 500);
        }
        $this->c->get(Audit::class)->log('system.export', $admin['id'], null, null, 'success', $request, ['files' => $files]);

        // The file must disappear even if the download is cancelled or PHP stops half way.
        ignore_user_abort(true);
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $name = 'sadt-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $manager->currentVersion()) . '-' . gmdate('Ymd-His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tmp));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
