<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Modules\System\BackupService;

/** /admin/backups — list, create now, download database file, delete. Requires backup.manage. */
final class BackupController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->view($request, 'admin/backups', ['title' => 'نسخه‌های پشتیبان', 'rows' => $this->c->get(BackupService::class)->list()]);
    }

    public function create(Request $request): Response
    {
        if (!$this->reauth($request)) {
            return $this->redirect('/admin/backups', 'رمز عبور شما درست نیست.', 'error');
        }
        $name = $this->c->get(BackupService::class)->create('manual');
        $this->c->get(Audit::class)->log('backup.create', (int) $this->user($request)['id'], null, null, 'success', $request, ['name' => $name]);
        return $this->redirect('/admin/backups', 'نسخه پشتیبان دیتابیس ساخته شد.');
    }

    public function download(Request $request): Response
    {
        $path = $this->c->get(BackupService::class)->path((string) $request->param('name'), (string) $request->param('file'));
        if ($path === null) {
            throw new HttpException(404);
        }
        $this->c->get(Audit::class)->log('backup.download', (int) $this->user($request)['id'], null, null, 'success', $request, ['file' => basename(dirname($path)) . '/' . basename($path)]);
        // Stream directly: backups can be large.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename(dirname($path)) . '-' . basename($path) . '"');
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    public function delete(Request $request): Response
    {
        $this->c->get(BackupService::class)->delete((string) $request->param('name'));
        $this->c->get(Audit::class)->log('backup.delete', (int) $this->user($request)['id'], null, null, 'success', $request, ['name' => $request->param('name')]);
        return $this->redirect('/admin/backups', 'نسخه پشتیبان حذف شد.');
    }
}
