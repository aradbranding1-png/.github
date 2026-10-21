<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Container;
use App\Core\Db\Connection;

/** proposal_sent → recipient's "proposals received" counter. */
final class CountProposalSends
{
    /** @param list<array<string, mixed>> $events */
    public function handle(Container $c, array $events): void
    {
        $per = [];
        foreach ($events as $e) {
            if ($e['subject_user_id'] !== null) {
                $per[(int) $e['subject_user_id']] = ($per[(int) $e['subject_user_id']] ?? 0) + 1;
            }
        }
        $db = $c->get(Connection::class);
        foreach ($per as $uid => $n) {
            $db->exec('UPDATE user_counters SET proposals = proposals + ?, updated_at = NOW(3) WHERE user_id = ?', [$n, $uid]);
        }
    }
}
