<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Container;
use App\Core\Db\Connection;

/** proposal_viewed → proposal_views rows (partitioned) + precomputed counters, batched. */
final class CountProposalViews
{
    /** @param list<array<string, mixed>> $events */
    public function handle(Container $c, array $events): void
    {
        $db = $c->get(Connection::class);
        $rows = [];
        $perProposal = [];
        $perOwner = [];
        foreach ($events as $e) {
            $pid = (int) $e['ref_id'];
            $rows[] = [$pid, (int) $e['user_id'], (string) $e['created_at']];
            $perProposal[$pid] = ($perProposal[$pid] ?? 0) + 1;
            if ($e['subject_user_id'] !== null) {
                $perOwner[(int) $e['subject_user_id']] = ($perOwner[(int) $e['subject_user_id']] ?? 0) + 1;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->exec(
                'INSERT INTO proposal_views (proposal_id, viewer_id, created_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)')),
                array_merge(...$chunk)
            );
        }
        foreach ($perProposal as $pid => $n) {
            $db->exec('UPDATE proposals SET view_count = view_count + ? WHERE id = ?', [$n, $pid]);
            $db->exec('UPDATE proposal_feed SET views_7d = views_7d + ? WHERE proposal_id = ?', [$n, $pid]);
        }
        foreach ($perOwner as $uid => $n) {
            $db->exec('UPDATE user_counters SET proposal_views_received = proposal_views_received + ?, updated_at = NOW(3) WHERE user_id = ?', [$n, $uid]);
        }
    }
}
