<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;

final class NotificationController
{
    public function index(): string
    {
        $page = DB::paginate('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC', [(int)Auth::id()], 25);
        $html = view('notifications/index', ['title' => 'اعلان‌ها', 'page' => $page]);
        DB::run('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [(int)Auth::id()]);
        return $html;
    }

    public function readAll(): never
    {
        DB::run('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [(int)Auth::id()]);
        if (\App\Core\Request::isAjax()) json_out(['ok' => true]);
        redirect('/notifications');
    }
}
