<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;

final class ExportController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->view($request, 'admin/exports', [
            'title' => 'خروجی Excel',
            'rows' => $this->c->get(ExportService::class)->listFor((int) $this->user($request)['id']),
            'types' => ExportService::TYPES,
        ]);
    }

    public function create(Request $request): Response
    {
        $type = (string) $request->input('type', '');
        $format = $request->input('format') === 'csv' ? 'csv' : 'xlsx';
        if (!isset(ExportService::TYPES[$type])) {
            throw new HttpException(400);
        }
        if (in_array($type, ['payments', 'wallet'], true) && !$this->allows($request, 'payments.view') && !$this->allows($request, 'wallet.view')) {
            throw new HttpException(403);
        }
        $this->c->get(ExportService::class)->request((int) $this->user($request)['id'], $type, $format);
        $this->c->get(Audit::class)->log('reports.export', (int) $this->user($request)['id'], null, null, 'success', $request, ['type' => $type, 'format' => $format]);
        return $this->redirect('/admin/exports', 'درخواست ثبت شد. فایل تا چند دقیقه دیگر آماده می‌شود.');
    }

    public function download(Request $request): Response
    {
        $e = $this->c->get(ExportService::class)->find((string) $request->param('uid'), (int) $this->user($request)['id']);
        $path = $e ? ExportService::DIR . '/' . basename((string) $e['file']) : null;
        if ($path === null || !is_file($path)) {
            throw new HttpException(404);
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filename = 'sadt-' . $e['type'] . '-' . substr((string) $e['created_at'], 0, 10) . '.' . $ext;
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $ext === 'csv' ? 'text/csv; charset=utf-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
