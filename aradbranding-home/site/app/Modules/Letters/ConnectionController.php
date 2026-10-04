<?php

declare(strict_types=1);

namespace App\Modules\Letters;

use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class ConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $before = $request->query('before');
        $bind = [$user['id']];
        $cond = '';
        if (is_string($before) && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)_(\d+)$/', $before, $m)) {
            $cond = ' AND (created_at < ? OR (created_at = ? AND peer_id < ?))';
            array_push($bind, $m[1], $m[1], (int) $m[2]);
        }
        $rows = $this->c->get(Connection::class)->select(
            'SELECT peer_id, source, created_at FROM user_connections WHERE user_id = ?' . $cond . '
             ORDER BY created_at DESC, peer_id DESC LIMIT 31',
            $bind
        );
        $next = null;
        if (count($rows) > 30) {
            array_pop($rows);
            $last = end($rows);
            $next = $last['created_at'] . '_' . $last['peer_id'];
        }
        $cards = $this->c->get(LetterService::class)->userCards(array_column($rows, 'peer_id'));
        return $this->view($request, 'connections/index', ['title' => t('ارتباطات تجاری'), 'rows' => $rows, 'cards' => $cards, 'next' => $next]);
    }
}
