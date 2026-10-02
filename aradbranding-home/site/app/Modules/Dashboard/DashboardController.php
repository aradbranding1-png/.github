<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Pages\PageService;

/** Precomputed counters only — no COUNT(*) on large tables in the request path. */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $db = $this->c->get(Connection::class);
        $counters = $db->first('SELECT * FROM user_counters WHERE user_id = ?', [$user['id']]) ?? [];
        $pages = $this->c->get(PageService::class)->listForOwner($user['id']);
        $proposals = (int) $db->scalar('SELECT COUNT(*) FROM proposals WHERE user_id = ? AND deleted_at IS NULL', [$user['id']]);

        return $this->view($request, 'dashboard/index', [
            'title' => 'داشبورد',
            'counters' => $counters,
            'balance' => $this->c->get(\App\Modules\Wallet\WalletService::class)->balance($user['id']),
            'pages' => $pages,
            'steps' => [
                ['done' => $user['avatar_path'] !== null, 'label' => 'تصویر پروفایل را اضافه کنید.', 'href' => '/account'],
                ['done' => $pages !== [], 'label' => 'اولین صفحه تجاری خود را بسازید.', 'href' => '/pages/new'],
                ['done' => count($pages) > 1, 'label' => 'صفحه را به زبان دوم هم بسازید.', 'href' => '/pages/new'],
                ['done' => $proposals > 0, 'label' => 'اولین پیشنهاد تجاری خود را ثبت کنید.', 'href' => '/proposals/new'],
            ],
        ]);
    }
}
