<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Read-only audit trail, keyset-paginated, partition-pruned to the last 90 days by default. */
final class AuditController extends AdminController
{
    public function index(Request $request): Response
    {
        $action = trim((string) $request->query('action', ''));
        $cursor = (string) $request->query('cursor', '');
        $days = min(365, max(1, (int) $request->query('days', '90')));
        $where = ['a.created_at > NOW(3) - INTERVAL ' . $days . ' DAY'];
        $bind = [];
        if ($action !== '' && preg_match('/^[a-z_.]+$/', $action)) {
            $where[] = 'a.action LIKE ?';
            $bind[] = $action . '%';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)_(\d+)$/', $cursor, $m)) {
            $where[] = '(a.created_at < ? OR (a.created_at = ? AND a.id < ?))';
            array_push($bind, $m[1], $m[1], (int) $m[2]);
        }
        $rows = $this->c->get(Connection::class)->select(
            'SELECT a.id, a.action, a.target_type, a.target_id, a.result, a.ip, a.device, a.meta, a.created_at, a.impersonator_id,
                    u.first_name, u.last_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 51',
            $bind
        );
        $next = null;
        if (count($rows) > 50) {
            array_pop($rows);
            $last = end($rows);
            $next = $last['created_at'] . '_' . $last['id'];
        }
        foreach ($rows as &$r) {
            $r['ip_text'] = $r['ip'] !== null ? (string) @inet_ntop($r['ip']) : '';
        }
        return $this->view($request, 'admin/audit', ['title' => 'رویدادهای امنیتی', 'rows' => $rows, 'next' => $next, 'action' => $action, 'days' => $days]);
    }
}
