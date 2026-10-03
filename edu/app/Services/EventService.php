<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Activity;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Gate;

/**
 * Online events:
 *  - webinar  : costs its duration from the user's webinar hour credit
 *  - workshop : costs one workshop credit
 *  - meeting  : free for users with an active meeting subscription
 * Audience is limited by user type (segments) and/or a group.
 */
final class EventService
{
    public const TYPES = [
        'webinar' => ['label' => 'وبینار تجاری', 'plural' => 'وبینارهای تجاری', 'mine' => 'وبینارهای من', 'icon' => 'video', 'tone' => 'purple'],
        'workshop' => ['label' => 'کارگاه تجاری آنلاین', 'plural' => 'کارگاه‌های تجاری آنلاین', 'mine' => 'کارگاه‌های من', 'icon' => 'briefcase', 'tone' => 'warning'],
        'meeting' => ['label' => 'میتینگ آنلاین', 'plural' => 'میتینگ‌های آنلاین', 'mine' => 'میتینگ‌های من', 'icon' => 'users', 'tone' => 'info'],
    ];

    public static function type(string $t): array
    {
        return self::TYPES[$t] ?? throw new \App\Core\HttpException(404);
    }

    /** People who manage events see everything and never pay */
    public static function isStaff(?array $u = null): bool
    {
        $u ??= Auth::user();
        return $u && (Gate::isRoot($u) || Gate::allows('events.view', $u));
    }

    /** SQL filter for events visible to a user (alias e) */
    public static function visibleSql(array $u, string $a = 'e'): array
    {
        $base = "$a.deleted_at IS NULL";
        if (self::isStaff($u)) return [$base, []];
        return [
            "$base AND $a.status = 'active' AND ($a.segments IS NULL OR $a.segments = '' OR FIND_IN_SET(?, $a.segments) > 0)
             AND ($a.group_id IS NULL OR EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = $a.group_id AND gm.user_id = ?)
                  OR EXISTS (SELECT 1 FROM event_registrations r0 WHERE r0.event_id = $a.id AND r0.user_id = ?))",
            [(string)$u['segment'], (int)$u['id'], (int)$u['id']],
        ];
    }

    public static function canSee(array $u, array $e): bool
    {
        [$w, $p] = self::visibleSql($u);
        return (bool)DB::value("SELECT 1 FROM events e WHERE e.id = ? AND $w", array_merge([(int)$e['id']], $p));
    }

    public static function endsAt(array $e): ?string
    {
        return $e['starts_at'] ? date('Y-m-d H:i:s', strtotime($e['starts_at']) + (int)$e['duration_minutes'] * 60) : null;
    }

    /** upcoming | live | ended | unscheduled */
    public static function phase(array $e): string
    {
        if (!$e['starts_at']) return 'unscheduled';
        $now = time(); $s = strtotime($e['starts_at']); $end = $s + (int)$e['duration_minutes'] * 60;
        if ($now < $s) return 'upcoming';
        if ($now <= $end) return 'live';
        return 'ended';
    }

    /** What a registration costs, in the unit of the type's credit */
    public static function cost(array $e): int
    {
        return match ($e['type']) { 'webinar' => max(0, (int)$e['duration_minutes']), 'workshop' => 1, default => 0 };
    }

    public static function registration(int $userId, int $eventId): ?array
    {
        return DB::one("SELECT * FROM event_registrations WHERE event_id = ? AND user_id = ? AND status = 'registered'", [$eventId, $userId]);
    }

    /** Register and pay. Returns [ok, message]. */
    public static function register(array $u, array $e): array
    {
        $uid = (int)$u['id'];
        if ($e['type'] === 'meeting') return [false, 'برای میتینگ‌ها ثبت‌نام لازم نیست؛ با اشتراک فعال مستقیم وارد شوید.'];
        if (self::registration($uid, (int)$e['id'])) return [true, 'already'];
        if (self::phase($e) === 'ended') return [false, 'این ' . self::TYPES[$e['type']]['label'] . ' برگزار شده و ثبت‌نام آن بسته است.'];
        $staff = self::isStaff($u);
        $cost = $staff ? 0 : self::cost($e);
        $creditType = $e['type'];
        $result = [false, ''];
        DB::transaction(function () use ($uid, $e, $cost, $creditType, &$result) {
            $col = Credit::COLUMNS[$creditType];
            $bal = (int)DB::value("SELECT $col FROM users WHERE id = ? FOR UPDATE", [$uid]);
            if (DB::value("SELECT 1 FROM event_registrations WHERE event_id = ? AND user_id = ? AND status = 'registered'", [(int)$e['id'], $uid])) { $result = [true, 'already']; return; }
            if ($bal < $cost) {
                $result = [false, 'اعتبار شما کافی نیست. این ' . self::TYPES[$e['type']]['label'] . ' ' . Credit::amount($creditType, $cost) . ' اعتبار لازم دارد و موجودی شما ' . Credit::amount($creditType, $bal) . ' است.'];
                return;
            }
            if ($cost > 0) {
                DB::run("UPDATE users SET $col = $col - ? WHERE id = ?", [$cost, $uid]);
                DB::insert('minute_ledger', [
                    'user_id' => $uid, 'credit_type' => $creditType, 'delta' => -$cost, 'balance_after' => $bal - $cost, 'kind' => 'consume',
                    'event_id' => (int)$e['id'], 'note' => mb_substr((string)$e['title'], 0, 250), 'created_by' => $uid, 'created_at' => now(),
                ]);
            }
            DB::run("INSERT INTO event_registrations (event_id, user_id, cost, status, created_at) VALUES (?,?,?,'registered',?)
                     ON DUPLICATE KEY UPDATE status = 'registered', cost = VALUES(cost), created_at = VALUES(created_at)", [(int)$e['id'], $uid, $cost, now()]);
            $result = [true, ''];
        });
        if ($result[0] && $result[1] === '') {
            Activity::track($uid, 'event_register', 'event', (int)$e['id'], ['type' => $e['type']]);
            Audit::log('events.register', 'event', (int)$e['id'], 'success', ['user' => $uid, 'cost' => $cost]);
        }
        return $result;
    }

    /** Admin cancels a registration, optionally refunding the credit */
    public static function cancel(int $regId, bool $refund, ?int $by): void
    {
        $r = DB::one('SELECT r.*, e.type, e.title FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ?', [$regId]);
        if (!$r || $r['status'] !== 'registered') return;
        DB::update('event_registrations', ['status' => 'cancelled'], 'id = ?', [$regId]);
        if ($refund && (int)$r['cost'] > 0 && isset(Credit::COLUMNS[$r['type']])) {
            Credit::adjustType((int)$r['user_id'], $r['type'], (int)$r['cost'], 'بازگشت اعتبار — لغو ثبت‌نام «' . $r['title'] . '»', $by, (int)$r['event_id'], 'refund', false);
        }
        Audit::log('events.cancel', 'event', (int)$r['event_id'], 'success', ['user' => (int)$r['user_id'], 'refund' => $refund]);
    }

    /** May this user open the join link? Returns [ok, reason] */
    public static function mayJoin(array $u, array $e): array
    {
        if (self::isStaff($u)) return [true, ''];
        if ($e['type'] === 'meeting') {
            return Credit::meetingActive($u) ? [true, ''] : [false, 'برای ورود به میتینگ‌ها اشتراک فعال میتینگ آنلاین لازم است.'];
        }
        return self::registration((int)$u['id'], (int)$e['id']) ? [true, ''] : [false, 'ابتدا در این ' . self::TYPES[$e['type']]['label'] . ' ثبت‌نام کنید.'];
    }

    /** Record a click on the join link (meetings create an attendance row) */
    public static function recordJoin(array $u, array $e): void
    {
        $uid = (int)$u['id'];
        if ($e['type'] === 'meeting') {
            DB::run("INSERT INTO event_registrations (event_id, user_id, cost, status, join_count, joined_at, created_at) VALUES (?,?,0,'registered',1,?,?)
                     ON DUPLICATE KEY UPDATE join_count = join_count + 1, joined_at = COALESCE(joined_at, VALUES(joined_at))", [(int)$e['id'], $uid, now(), now()]);
        } else {
            DB::run('UPDATE event_registrations SET join_count = join_count + 1, joined_at = COALESCE(joined_at, ?) WHERE event_id = ? AND user_id = ?', [now(), (int)$e['id'], $uid]);
        }
        Activity::track($uid, 'event_join', 'event', (int)$e['id'], ['type' => $e['type']]);
        TraderGrowth::refresh($uid);
    }

    /** Events a user registered for / may attend, soonest first (for dashboard & "my services") */
    public static function mine(array $u, string $type, int $limit = 100): array
    {
        if ($type === 'meeting') {
            if (!Credit::meetingActive($u) && !self::isStaff($u)) {
                // past attendance only
                return DB::all("SELECT e.*, r.join_count, r.joined_at, r.created_at AS reg_at FROM event_registrations r JOIN events e ON e.id = r.event_id
                                 WHERE r.user_id = ? AND e.type = 'meeting' AND e.deleted_at IS NULL ORDER BY e.starts_at DESC LIMIT $limit", [(int)$u['id']]);
            }
            [$w, $p] = self::visibleSql($u);
            return DB::all("SELECT e.*, r.join_count, r.joined_at FROM events e LEFT JOIN event_registrations r ON r.event_id = e.id AND r.user_id = ?
                             WHERE e.type = 'meeting' AND $w ORDER BY (e.starts_at < ?), e.starts_at ASC LIMIT $limit", array_merge([(int)$u['id']], $p, [date('Y-m-d H:i:s', time() - 86400)]));
        }
        return DB::all("SELECT e.*, r.cost, r.join_count, r.joined_at, r.created_at AS reg_at FROM event_registrations r JOIN events e ON e.id = r.event_id
                         WHERE r.user_id = ? AND r.status = 'registered' AND e.type = ? AND e.deleted_at IS NULL
                         ORDER BY (e.starts_at < ?), e.starts_at ASC LIMIT $limit", [(int)$u['id'], $type, date('Y-m-d H:i:s', time() - 86400)]);
    }

    /** Next sessions for the dashboard: registered webinars/workshops + meetings (with subscription) */
    public static function upcomingFor(array $u, int $limit = 4): array
    {
        $uid = (int)$u['id'];
        $rows = DB::all("SELECT e.*, 1 AS registered FROM event_registrations r JOIN events e ON e.id = r.event_id
                          WHERE r.user_id = ? AND r.status = 'registered' AND e.type IN ('webinar','workshop') AND e.deleted_at IS NULL
                            AND e.starts_at >= ? ORDER BY e.starts_at LIMIT $limit", [$uid, date('Y-m-d H:i:s', time() - 10800)]);
        if (Credit::meetingActive($u)) {
            [$w, $p] = self::visibleSql($u);
            $rows = array_merge($rows, DB::all("SELECT e.*, 1 AS registered FROM events e WHERE e.type = 'meeting' AND $w AND e.starts_at >= ? ORDER BY e.starts_at LIMIT $limit", array_merge($p, [date('Y-m-d H:i:s', time() - 10800)])));
        }
        usort($rows, fn($a, $b) => strcmp((string)$a['starts_at'], (string)$b['starts_at']));
        return array_slice($rows, 0, $limit);
    }
}
