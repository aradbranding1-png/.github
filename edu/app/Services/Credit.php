<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Gate;
use App\Core\Notify;

/**
 * Minute credit (اعتبار زمانی): learners buy minutes; opening ("activating") a lesson costs its
 * duration_minutes once. Revisiting an activated lesson is free. Free-preview lessons and lessons
 * without a duration cost nothing. Managers/instructors (lessons.view) are exempt.
 * Every change is written to minute_ledger with the resulting balance.
 */
final class Credit
{
    public static function enabled(): bool
    {
        return setting('minutes_enabled', '1') === '1';
    }

    /** Staff who manage content never spend credit */
    public static function exempt(?array $user = null): bool
    {
        $user ??= Auth::user();
        if (!$user) return false;
        return Gate::isRoot($user) || Gate::allows('lessons.view', $user) || Gate::allows('courses.view', $user);
    }

    /** Does this user have to pay for lessons right now? */
    public static function applies(?array $user = null): bool
    {
        return self::enabled() && !self::exempt($user);
    }

    public static function balance(int $userId): int
    {
        return (int)DB::value('SELECT minute_balance FROM users WHERE id = ?', [$userId]);
    }

    public static function cost(array $lesson): int
    {
        if ((int)($lesson['is_preview'] ?? 0) === 1) return 0;
        return max(0, (int)($lesson['duration_minutes'] ?? 0));
    }

    public static function isUnlocked(int $userId, array $lesson): bool
    {
        if (self::cost($lesson) === 0) return true;
        return (bool)DB::value('SELECT 1 FROM lesson_unlocks WHERE user_id = ? AND lesson_id = ?', [$userId, (int)$lesson['id']]);
    }

    /**
     * May the user take this exam with respect to course credit?
     * Lesson exam  → that lesson must be activated (paid).
     * Course exam  → every paid lesson of the course must be activated.
     * @return array{ok:bool,need:int,balance:int,lesson:?array,course_id:?int}
     */
    public static function examAccess(array $exam, ?array $user = null): array
    {
        $user ??= Auth::user();
        $ok = ['ok' => true, 'need' => 0, 'balance' => 0, 'lesson' => null, 'course_id' => $exam['course_id'] ? (int)$exam['course_id'] : null];
        if (!$user || !self::applies($user)) return $ok;
        $uid = (int)$user['id'];
        if (!empty($exam['lesson_id'])) {
            $l = DB::one('SELECT id, course_id, title, duration_minutes, is_preview FROM lessons WHERE id = ? AND deleted_at IS NULL', [(int)$exam['lesson_id']]);
            if ($l && !self::isUnlocked($uid, $l)) return ['ok' => false, 'need' => self::cost($l), 'balance' => self::balance($uid), 'lesson' => $l, 'course_id' => (int)$l['course_id']];
            if ($l) return $ok;
        }
        if (!empty($exam['course_id'])) {
            $lessons = DB::all("SELECT id, course_id, title, duration_minutes, is_preview FROM lessons WHERE course_id = ? AND deleted_at IS NULL AND status = 'published'", [(int)$exam['course_id']]);
            $need = self::courseRemaining($uid, $lessons);
            if ($need > 0) return ['ok' => false, 'need' => $need, 'balance' => self::balance($uid), 'lesson' => null, 'course_id' => (int)$exam['course_id']];
        }
        return $ok;
    }

    /** ids of lessons of a course the user has activated */
    public static function unlockedIds(int $userId, int $courseId): array
    {
        return array_map('intval', DB::column('SELECT lesson_id FROM lesson_unlocks WHERE user_id = ? AND course_id = ?', [$userId, $courseId]));
    }

    /** Minutes still needed to activate every remaining paid lesson of a course */
    public static function courseRemaining(int $userId, array $lessons): int
    {
        if (!$lessons) return 0;
        $courseId = (int)($lessons[0]['course_id'] ?? 0);
        $un = $courseId ? self::unlockedIds($userId, $courseId) : [];
        $sum = 0;
        foreach ($lessons as $l) if (!in_array((int)$l['id'], $un, true)) $sum += self::cost($l);
        return $sum;
    }

    /**
     * Activate one lesson: deducts its minutes once. Returns [ok, message].
     * Row-locks the user so two tabs can't spend the same minutes twice.
     */
    public static function unlock(int $userId, array $lesson): array
    {
        $cost = self::cost($lesson);
        if ($cost === 0) return [true, ''];
        $result = [false, ''];
        DB::transaction(function () use ($userId, $lesson, $cost, &$result) {
            $bal = (int)DB::value('SELECT minute_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (DB::value('SELECT 1 FROM lesson_unlocks WHERE user_id = ? AND lesson_id = ?', [$userId, (int)$lesson['id']])) { $result = [true, '']; return; }
            if ($bal < $cost) {
                $result = [false, 'اعتبار زمانی شما برای فعال‌سازی این درس کافی نیست. این درس ' . fa($cost) . ' دقیقه است و موجودی شما ' . fa($bal) . ' دقیقه است.'];
                return;
            }
            DB::run('UPDATE users SET minute_balance = minute_balance - ? WHERE id = ?', [$cost, $userId]);
            DB::insert('lesson_unlocks', ['user_id' => $userId, 'lesson_id' => (int)$lesson['id'], 'course_id' => (int)$lesson['course_id'], 'minutes' => $cost, 'created_at' => now()]);
            DB::insert('minute_ledger', [
                'user_id' => $userId, 'delta' => -$cost, 'balance_after' => $bal - $cost, 'kind' => 'consume',
                'lesson_id' => (int)$lesson['id'], 'course_id' => (int)$lesson['course_id'], 'note' => mb_substr((string)$lesson['title'], 0, 250),
                'created_by' => $userId, 'created_at' => now(),
            ]);
            $result = [true, ''];
        });
        return $result;
    }

    /** Activate all remaining paid lessons of a course in one go (all or nothing) */
    public static function unlockCourse(int $userId, array $lessons): array
    {
        $need = self::courseRemaining($userId, $lessons);
        if ($need === 0) return [true, ''];
        $bal = self::balance($userId);
        if ($bal < $need) return [false, 'برای فعال‌سازی کامل این دوره ' . fa($need) . ' دقیقه اعتبار لازم است و موجودی شما ' . fa($bal) . ' دقیقه است.'];
        foreach ($lessons as $l) {
            [$ok, $msg] = self::unlock($userId, $l);
            if (!$ok) return [false, $msg];
        }
        return [true, ''];
    }

    /** credit types stored as a counter on users */
    public const COLUMNS = ['course' => 'minute_balance', 'webinar' => 'webinar_minutes', 'workshop' => 'workshop_credits'];
    public const LABELS = ['course' => 'اعتبار دوره‌ها', 'webinar' => 'اعتبار وبینار', 'workshop' => 'اعتبار کارگاه آنلاین', 'meeting' => 'اشتراک میتینگ آنلاین', 'account' => 'اکانت سامانه آموزش'];

    /** Human-readable amount for a credit type */
    public static function amount(string $type, int $value): string
    {
        return $type === 'workshop' ? fa($value) . ' عدد' : (in_array($type, ['meeting', 'account'], true) ? fa($value) . ' روز' : self::format($value));
    }

    /** Manual charge (+) or deduction (−) of course minutes by an admin */
    public static function adjust(int $userId, int $delta, string $note = '', ?int $by = null): array
    {
        return self::adjustType($userId, 'course', $delta, $note, $by);
    }

    /**
     * Change a counter credit (course minutes, webinar minutes, workshop count).
     * $kind: charge|deduct (admin), consume (used), refund.
     */
    public static function adjustType(int $userId, string $type, int $delta, string $note = '', ?int $by = null, ?int $eventId = null, ?string $kind = null, bool $notify = true): array
    {
        $col = self::COLUMNS[$type] ?? null;
        if (!$col) return [false, 'نوع اعتبار نامعتبر است.'];
        if ($delta === 0) return [false, 'مقدار را وارد کنید.'];
        $result = [false, ''];
        DB::transaction(function () use ($userId, $type, $col, $delta, $note, $by, $eventId, $kind, &$result) {
            $bal = (int)DB::value("SELECT $col FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if ($bal + $delta < 0) { $result = [false, 'موجودی کاربر ' . self::amount($type, $bal) . ' است و نمی‌توان بیش از آن کسر کرد.']; return; }
            DB::run("UPDATE users SET $col = $col + ? WHERE id = ?", [$delta, $userId]);
            DB::insert('minute_ledger', [
                'user_id' => $userId, 'credit_type' => $type, 'delta' => $delta, 'balance_after' => $bal + $delta,
                'kind' => $kind ?? ($delta > 0 ? 'charge' : 'deduct'), 'event_id' => $eventId,
                'note' => $note !== '' ? mb_substr($note, 0, 250) : null, 'created_by' => $by, 'created_at' => now(),
            ]);
            $result = [true, ''];
        });
        if ($result[0] && ($kind === null || $kind === 'charge' || $kind === 'deduct')) {
            Audit::log('credits.adjust', 'user', $userId, 'success', ['type' => $type, 'delta' => $delta, 'note' => $note]);
            if ($delta > 0 && $notify) {
                $now = (int)DB::value("SELECT $col FROM users WHERE id = ?", [$userId]);
                Notify::send($userId, 'system', self::LABELS[$type] . ' شما ' . self::amount($type, $delta) . ' شارژ شد', 'موجودی فعلی: ' . self::amount($type, $now), url('/me/credits'));
            }
        }
        return $result;
    }

    // ------------------------------------------------------------------ meeting subscription

    public static function meetingActive(array $user): bool
    {
        return !empty($user['meeting_until']) && $user['meeting_until'] >= date('Y-m-d') && (empty($user['meeting_from']) || $user['meeting_from'] <= date('Y-m-d'));
    }

    public static function meetingDaysLeft(array $user): int
    {
        if (empty($user['meeting_until']) || $user['meeting_until'] < date('Y-m-d')) return 0;
        return (int)floor((strtotime($user['meeting_until']) - strtotime(date('Y-m-d'))) / 86400) + 1;
    }

    /**
     * Add months/days to the meeting subscription. If the subscription is running, it is extended
     * from its end; otherwise it starts on $from (default today).
     */
    public static function meetingExtend(int $userId, int $months, int $days, ?string $from, string $note = '', ?int $by = null): array
    {
        if ($months <= 0 && $days <= 0) return [false, 'مدت اشتراک را وارد کنید.'];
        $u = DB::one('SELECT meeting_from, meeting_until FROM users WHERE id = ?', [$userId]);
        $today = date('Y-m-d');
        $running = $u && $u['meeting_until'] && $u['meeting_until'] >= $today;
        $startNew = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today;
        $base = $running ? date('Y-m-d', strtotime($u['meeting_until'] . ' +1 day')) : $startNew;
        $until = date('Y-m-d', strtotime($base . ($months ? " +$months months" : '') . ($days ? " +$days days" : '') . ' -1 day'));
        $upd = ['meeting_until' => $until];
        if (!$running) $upd['meeting_from'] = $startNew;
        DB::update('users', $upd, 'id = ?', [$userId]);
        $added = (int)round((strtotime($until) - strtotime($base)) / 86400) + 1;
        $left = self::meetingDaysLeft(array_merge($u ?: [], $upd));
        DB::insert('minute_ledger', [
            'user_id' => $userId, 'credit_type' => 'meeting', 'delta' => $added, 'balance_after' => $left, 'kind' => 'charge',
            'note' => mb_substr(trim(($note !== '' ? $note . ' — ' : '') . 'فعال تا ' . jdate($until)), 0, 250), 'created_by' => $by, 'created_at' => now(),
        ]);
        Audit::log('credits.meeting', 'user', $userId, 'success', ['until' => $until, 'added_days' => $added]);
        Notify::send($userId, 'system', 'اشتراک میتینگ آنلاین شما فعال شد', 'فعال تا ' . jdate($until), url('/learn/events/meeting'));
        return [true, $until];
    }

    /** End the meeting subscription today (admin) */
    public static function meetingEnd(int $userId, string $note = '', ?int $by = null): void
    {
        $u = DB::one('SELECT meeting_until FROM users WHERE id = ?', [$userId]);
        $left = self::meetingDaysLeft($u ?: []);
        DB::update('users', ['meeting_until' => date('Y-m-d', strtotime('-1 day'))], 'id = ?', [$userId]);
        DB::insert('minute_ledger', [
            'user_id' => $userId, 'credit_type' => 'meeting', 'delta' => -$left, 'balance_after' => 0, 'kind' => 'deduct',
            'note' => $note !== '' ? mb_substr($note, 0, 250) : 'پایان اشتراک توسط مدیر', 'created_by' => $by, 'created_at' => now(),
        ]);
        Audit::log('credits.meeting_end', 'user', $userId);
    }

    /** All balances of a user */
    public static function balances(int $userId): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$userId]) ?: [];
        $acc = ['meeting_from' => $u['account_from'] ?? null, 'meeting_until' => $u['account_until'] ?? null];
        return [
            'course' => (int)($u['minute_balance'] ?? 0), 'webinar' => (int)($u['webinar_minutes'] ?? 0), 'workshop' => (int)($u['workshop_credits'] ?? 0),
            'meeting_from' => $u['meeting_from'] ?? null, 'meeting_until' => $u['meeting_until'] ?? null,
            'meeting_active' => self::meetingActive($u), 'meeting_days' => self::meetingDaysLeft($u),
            // yearly platform account (set by Arad Contact); null until = never purchased
            'account_from' => $acc['meeting_from'], 'account_until' => $acc['meeting_until'],
            'account_active' => self::meetingActive($acc), 'account_days' => self::meetingDaysLeft($acc),
        ];
    }

    public static function format(int $minutes): string
    {
        if ($minutes < 60) return fa($minutes) . ' دقیقه';
        $h = intdiv($minutes, 60); $m = $minutes % 60;
        return fa($h) . ' ساعت' . ($m ? ' و ' . fa($m) . ' دقیقه' : '');
    }

    public static function ledger(int $userId, int $limit = 50): array
    {
        return DB::all('SELECT ml.*, l.title AS lesson_title, c.title AS course_title, ev.title AS event_title, u.first_name AS by_first, u.last_name AS by_last
                          FROM minute_ledger ml LEFT JOIN lessons l ON l.id = ml.lesson_id LEFT JOIN courses c ON c.id = ml.course_id LEFT JOIN events ev ON ev.id = ml.event_id LEFT JOIN users u ON u.id = ml.created_by
                         WHERE ml.user_id = ? ORDER BY ml.id DESC LIMIT ' . max(1, $limit), [$userId]);
    }

    public static function totals(int $userId): array
    {
        $r = DB::one("SELECT COALESCE(SUM(CASE WHEN delta > 0 THEN delta END),0) charged, COALESCE(-SUM(CASE WHEN kind = 'consume' THEN delta END),0) used FROM minute_ledger WHERE user_id = ? AND credit_type = 'course'", [$userId]);
        return ['charged' => (int)($r['charged'] ?? 0), 'used' => (int)($r['used'] ?? 0), 'balance' => self::balance($userId)];
    }
}
