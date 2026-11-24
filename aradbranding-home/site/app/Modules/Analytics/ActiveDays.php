<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Db\Connection;
use App\Modules\Admin\MetricsService;

/**
 * Which member was active on which local (Tehran) calendar day: user_activity_days.
 * touch() runs from the auth middleware at most about once an hour per member (and on every sign-in),
 * so it costs one small upsert per member-hour, never one per request.
 */
final class ActiveDays
{
    /** What a member did, in the third person, for admin pages. Keys are activity_events.event values. */
    public const EVENTS = [
        'letter_sent' => 'نامه فرستاد',
        'letter_replied' => 'به نامه پاسخ داد',
        'proposal_sent' => 'پیشنهاد تجاری فرستاد',
        'proposal_viewed' => 'پیشنهاد تجاری دید',
        'page_viewed' => 'صفحه تجاری کامل را باز کرد',
        'connection_created' => 'ارتباط تجاری ساخت',
        'stars_spent' => 'Stars خرج کرد',
        'stars_refunded' => 'Stars بازپرداخت گرفت',
        'user_registered' => 'عضو شد',
    ];

    public function __construct(private Connection $db)
    {
    }

    /** UTC start of today in the display time zone ('Y-m-d H:i:s'). */
    public static function todayStartUtc(): string
    {
        return self::utcRange(MetricsService::today(), MetricsService::today())[0];
    }

    /**
     * UTC [from, to) of the local days $fromDay..$toDay inclusive.
     * @return array{0: string, 1: string}
     */
    public static function utcRange(string $fromDay, string $toDay): array
    {
        $utc = new \DateTimeZone('UTC');
        $tz = MetricsService::timezone();
        $a = new \DateTimeImmutable($fromDay . ' 00:00:00', $tz);
        $b = (new \DateTimeImmutable($toDay . ' 00:00:00', $tz))->modify('+1 day');
        return [$a->setTimezone($utc)->format('Y-m-d H:i:s'), $b->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** Record that $userId is active now (idempotent; keeps the first and the latest time of the day). */
    public function touch(int $userId): void
    {
        try {
            $this->db->exec(
                'INSERT INTO user_activity_days (day, user_id, first_seen_at, last_seen_at) VALUES (?, ?, NOW(3), NOW(3))
                 ON DUPLICATE KEY UPDATE last_seen_at = NOW(3)',
                [MetricsService::today(), $userId]
            );
        } catch (\Throwable) {
            // Before the 1.19.21 migration has run the table does not exist; never break a page for analytics.
        }
    }

    /** Number of members active on a local day. */
    public function count(string $day): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM user_activity_days WHERE day = ?', [$day]);
    }
}
