<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\Str;
use App\Core\View\View;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $cursor = $request->query('cursor');
        $list = $this->c->get(NotificationService::class)->list($user['id'], is_string($cursor) ? $cursor : null);
        return $this->view($request, 'notifications/index', ['title' => 'اعلان‌ها', 'list' => $list]);
    }

    /**
     * GET /notifications/peek — the latest notifications as an HTML fragment for the top-bar dropdown.
     * Opening the dropdown counts as seeing them, so the unread counter is cleared afterwards.
     */
    public function peek(Request $request): Response
    {
        $user = $this->user($request);
        $service = $this->c->get(NotificationService::class);
        $list = $service->list($user['id'], null);
        $html = $this->c->get(View::class)->partial('notifications/_peek', ['rows' => array_slice($list['rows'], 0, 8)]);
        if ((int) ($user['unread_notifications'] ?? 0) > 0) {
            $service->markAllRead($user['id']);
        }
        return Response::html($html)->withHeader('Cache-Control', 'private, no-store');
    }

    public function readAll(Request $request): Response
    {
        $this->c->get(NotificationService::class)->markAllRead($this->user($request)['id']);
        return $this->redirect(Str::safeNext((string) $request->input('back', ''), '/notifications'));
    }
}
