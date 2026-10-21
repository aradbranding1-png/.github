<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Container;
use App\Core\Db\Connection;

/**
 * Outbox → notifications. Actor names are loaded once per batch.
 * letter_sent / letter_replied / proposal_sent: notify subject_user_id.
 * connection_created: notify both sides.
 */
final class NotifyHandler
{
    private const MAP = [
        'letter_sent' => 'letter', 'letter_replied' => 'reply', 'proposal_sent' => 'proposal', 'connection_created' => 'connection',
    ];

    /** @param list<array<string, mixed>> $events */
    public function handle(Container $c, array $events): void
    {
        $db = $c->get(Connection::class);
        $service = new NotificationService($db);
        $ids = [];
        foreach ($events as $e) {
            $ids[(int) $e['user_id']] = true;
            if ($e['subject_user_id'] !== null) {
                $ids[(int) $e['subject_user_id']] = true;
            }
        }
        $names = [];
        if ($ids !== []) {
            $list = array_keys($ids);
            foreach ($db->select(
                'SELECT u.id, u.first_name, u.last_name, p.company_name FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE u.id IN (' . implode(',', array_fill(0, count($list), '?')) . ')',
                $list
            ) as $u) {
                $names[(int) $u['id']] = trim($u['first_name'] . ' ' . $u['last_name']) . ($u['company_name'] ? ' (' . $u['company_name'] . ')' : '');
            }
        }

        foreach ($events as $e) {
            $type = self::MAP[$e['event']] ?? null;
            if ($type === null || $e['subject_user_id'] === null) {
                continue;
            }
            $actor = (int) $e['user_id'];
            $subject = (int) $e['subject_user_id'];
            $data = json_decode((string) ($e['data'] ?? '{}'), true) ?: [];
            $link = isset($data['thread']) ? '/letters/' . $data['thread'] : ($e['event'] === 'proposal_sent' ? '/proposals/received' : null);

            if ($type === 'connection') {
                $service->notify([$subject], $type, $actor, '/connections', ['name' => $names[$actor] ?? '']);
                $service->notify([$actor], $type, $subject, '/connections', ['name' => $names[$subject] ?? '']);
                continue;
            }
            $name = !empty($data['official']) ? 'آراد برندینگ' : ($names[$actor] ?? '');
            $service->notify([$subject], $type, $actor, $link, ['name' => $name]);
        }
    }
}
