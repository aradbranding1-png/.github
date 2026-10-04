<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Db\Connection;

/** Notifications: batch writes + precomputed unread counter; keyset-paginated list. */
final class NotificationService
{
    /** type => sentence with :name / :subject / :n placeholders */
    public const TEXT = [
        'letter' => ':name برای شما نامه فرستاد.',
        'reply' => ':name به نامه شما پاسخ داد.',
        'proposal' => ':name یک پیشنهاد تجاری برای شما فرستاد.',
        'connection' => 'ارتباط تجاری شما با :name ثبت شد. شبکه شما در حال رشد است!',
        'public_letter' => 'نامه عمومی جدید از :name: «:subject»',
        'campaign_done' => 'نامه عمومی «:subject» به :n تاجر رسید.',
        'digest_proposals' => ':n پیشنهاد جدید مرتبط با حوزه فعالیت شما منتشر شد.',
        'digest_views' => 'پیشنهادهای شما امروز :n بار توسط تجار دیده شد.',
        'digest_page' => 'صفحه تجاری شما امروز :n بار به‌طور کامل مشاهده شد.',
        'wallet_credit' => ':n Star به کیف پول شما اضافه شد. :subject',
        'wallet_debit' => ':n Star از کیف پول شما کسر شد. :subject',
        'wallet_refund' => ':n Star بابت «:subject» به کیف پول شما برگشت داده شد.',
        'report_resolved' => 'گزارش شما درباره :name بررسی شد: :subject',
        'trust_warning' => 'اخطار تیم آراد برندینگ: :subject',
        'trust_hidden' => 'یکی از محتواهای شما به دلیل نقض قوانین پنهان شد: :subject',
        'trust_restricted' => 'حساب شما محدود شد و فعلاً امکان ارسال یا انتشار ندارید. دلیل: :subject',
        'trust_restored' => 'محدودیت حساب شما برداشته شد. خوش برگشتید!',
    ];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<int> $userIds
     * @param array<string, mixed> $data
     */
    public function notify(array $userIds, string $type, ?int $actorId, ?string $link, array $data = []): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $uid) {
                array_push($rows, $uid, $type, $actorId, $link, $json);
            }
            $this->db->exec(
                'INSERT INTO notifications (user_id, type, actor_id, link, data, created_at) VALUES '
                    . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, NOW(3))')),
                $rows
            );
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $this->db->exec("UPDATE user_counters SET unread_notifications = unread_notifications + 1 WHERE user_id IN ({$marks})", $chunk);
        }
    }

    /** @return array{rows: list<array<string, mixed>>, next: ?string} */
    public function list(int $userId, ?string $cursor): array
    {
        $bind = [$userId];
        $cond = '';
        if ($cursor !== null && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)_(\d+)$/', $cursor, $m)) {
            $cond = ' AND (created_at < ? OR (created_at = ? AND id < ?))';
            array_push($bind, $m[1], $m[1], (int) $m[2]);
        }
        $rows = $this->db->select(
            'SELECT id, type, link, data, is_read, created_at FROM notifications
             WHERE user_id = ?' . $cond . ' ORDER BY created_at DESC, id DESC LIMIT 31',
            $bind
        );
        $next = null;
        if (count($rows) > 30) {
            array_pop($rows);
            $last = end($rows);
            $next = $last['created_at'] . '_' . $last['id'];
        }
        foreach ($rows as &$r) {
            $d = json_decode((string) ($r['data'] ?? '{}'), true) ?: [];
            $r['text'] = t(self::TEXT[$r['type']] ?? '', [
                'name' => (string) ($d['name'] ?? ''),
                'subject' => (string) ($d['subject'] ?? ''),
                'n' => fa_int((int) ($d['n'] ?? 0)),
            ]);
        }
        return ['rows' => $rows, 'next' => $next];
    }

    public function markAllRead(int $userId): void
    {
        $this->db->exec(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND created_at > NOW(3) - INTERVAL 90 DAY',
            [$userId]
        );
        $this->db->exec('UPDATE user_counters SET unread_notifications = 0 WHERE user_id = ?', [$userId]);
    }

    /**
     * Daily engagement digest, set-based (a few INSERT … SELECT statements, no per-user loop):
     *  - new proposals in each user's interest categories,
     *  - views of each owner's proposals,
     *  - full views of each owner's business page.
     */
    public function dailyDigest(): int
    {
        $n = 0;
        $n += $this->db->exec(
            "INSERT INTO notifications (user_id, type, link, data, created_at)
             SELECT ui.user_id, 'digest_proposals', '/proposals', JSON_OBJECT('n', COUNT(*)), NOW(3)
             FROM proposal_feed f JOIN user_interests ui ON ui.category_id = f.category_id
             WHERE f.published_at > NOW(3) - INTERVAL 1 DAY AND f.user_id <> ui.user_id
             GROUP BY ui.user_id"
        );
        $n += $this->db->exec(
            "INSERT INTO notifications (user_id, type, link, data, created_at)
             SELECT p.user_id, 'digest_views', '/proposals/mine', JSON_OBJECT('n', COUNT(*)), NOW(3)
             FROM proposal_views v JOIN proposals p ON p.id = v.proposal_id
             WHERE v.created_at > NOW(3) - INTERVAL 1 DAY
             GROUP BY p.user_id"
        );
        $n += $this->db->exec(
            "INSERT INTO notifications (user_id, type, link, data, created_at)
             SELECT e.subject_user_id, 'digest_page', '/pages', JSON_OBJECT('n', COUNT(*)), NOW(3)
             FROM activity_events e
             WHERE e.event = 'page_viewed' AND e.created_at > NOW(3) - INTERVAL 1 DAY AND e.subject_user_id IS NOT NULL
             GROUP BY e.subject_user_id"
        );
        // One counter refresh for everyone who just received a digest.
        $this->db->exec(
            "UPDATE user_counters uc JOIN (
                SELECT user_id, COUNT(*) AS c FROM notifications
                WHERE type IN ('digest_proposals', 'digest_views', 'digest_page') AND created_at > NOW(3) - INTERVAL 10 MINUTE GROUP BY user_id
             ) d ON d.user_id = uc.user_id SET uc.unread_notifications = uc.unread_notifications + d.c"
        );
        return $n;
    }
}
