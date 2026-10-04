<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Auth\Gate;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Session\Session;
use App\Modules\Letters\AnnouncementService;

/**
 * "تازه‌های سامانه" — release notes. Everyone reads; holders of updates.manage write.
 * Each note can be hidden, and can optionally be announced to everyone as an official letter.
 */
final class ReleaseNotesController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $canWrite = $this->c->get(Gate::class)->allows((int) $user['id'], 'updates.manage');
        $rows = $this->c->get(Connection::class)->select(
            'SELECT id, version, title, body, visible, announced, published_at FROM system_updates'
                . ($canWrite ? '' : ' WHERE visible = 1') . ' ORDER BY published_at DESC, id DESC LIMIT 50'
        );
        $draft = $canWrite ? Session::get('_update_result') : null;
        return $this->view($request, 'updates/index', [
            'title' => t('تازه‌های سامانه'),
            'rows' => $rows,
            'canWrite' => $canWrite,
            'prefill' => is_array($draft) && !empty($draft['ok']) && empty($draft['note_published']) ? ['version' => $draft['to'], 'body' => $draft['notes']] : [],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $admin = $this->user($request);
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));
        $version = trim((string) $request->input('version', ''));
        if ($title === '' || mb_strlen($title) > 150 || $body === '') {
            return $this->redirect('/updates', 'عنوان و متن بروزرسانی را بنویسید.', 'error');
        }
        $visible = (bool) $request->input('visible');
        $announce = (bool) $request->input('announce');
        $db = $this->c->get(Connection::class);
        $db->insert(
            'INSERT INTO system_updates (version, title, body, visible, announced, created_by, published_at) VALUES (?, ?, ?, ?, ?, ?, NOW(3))',
            [mb_substr($version, 0, 40) ?: null, $title, $body, $visible ? 1 : 0, $announce ? 1 : 0, $admin['id']]
        );
        if ($announce) {
            $this->c->get(AnnouncementService::class)->create((int) $admin['id'], 'بروزرسانی سامانه: ' . $title, $body);
        }
        Session::forget('_update_result');
        $this->c->get(Audit::class)->log('updates.note', $admin['id'], null, null, 'success', $request, ['title' => $title, 'announce' => $announce]);
        return $this->redirect('/updates', $announce ? 'منتشر شد و برای همه کاربران نامه رسمی ارسال شد.' : 'یادداشت بروزرسانی ذخیره شد.');
    }

    public function toggle(Request $request): Response
    {
        $this->c->get(Connection::class)->exec('UPDATE system_updates SET visible = 1 - visible WHERE id = ?', [(int) $request->param('id')]);
        return $this->redirect('/updates', 'وضعیت نمایش تغییر کرد.');
    }
}
