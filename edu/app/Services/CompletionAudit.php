<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Notify;

/**
 * Detects lessons marked «completed» without really being watched / listened to / read, records them in
 * lesson_completion_flags and alerts the learner and the managers (root admins + the learner's supervisor).
 *
 * Signals (any one is enough):
 *  - impossible_time : finished faster than the content can be played even at 2× speed (opened → completed)
 *  - skipped         : the player measured how much was really played (seeking forward does not count) and it is far too little
 *  - low_time        : a video lesson with very little time on the page (a video can only be watched with the page open)
 *  - quick_read      : a text lesson «read» in a fraction of its reading time
 */
final class CompletionAudit
{
    public const REASONS = [
        'impossible_time' => 'زودتر از زمان واقعی درس تکمیل شده',
        'skipped' => 'بخش زیادی از فایل جلو زده شده (پخش نشده)',
        'low_time' => 'زمان حضور در صفحه بسیار کمتر از طول ویدیو',
        'quick_read' => 'متن درس در زمانی بسیار کوتاه «خوانده» شده',
    ];
    public const STATUSES = [
        'open' => 'در انتظار بررسی', 'reset' => 'برای دیدن دوباره باز شد', 'rewatched' => 'بعداً کامل دیده شد', 'dismissed' => 'تأیید شد (مشکلی نیست)',
    ];
    private const MIN_EXPECTED = 90;   // lessons shorter than 1.5 minutes are never flagged
    private const WORDS_PER_MIN = 200;

    public static function enabled(): bool
    {
        return setting('completion_audit', '1') !== '0';
    }

    /** Minimum share of a media file that must really be played (percent) */
    public static function minWatchedPct(): int
    {
        return max(20, min(95, (int)setting('completion_audit_pct', 60)));
    }

    /** Real length of a lesson in seconds (0 = unknown) */
    public static function expectedSeconds(array $lesson): int
    {
        $media = in_array($lesson['content_type'] ?? '', ['video', 'audio'], true);
        if ($media) return (int)($lesson['media_seconds'] ?? 0) ?: (int)($lesson['duration_minutes'] ?? 0) * 60 ?: self::observedLength((int)$lesson['id']);
        if (($lesson['content_type'] ?? '') === 'text') {
            if ((int)($lesson['duration_minutes'] ?? 0) > 0) return (int)$lesson['duration_minutes'] * 60;
            $words = count(preg_split('/\s+/u', trim(html_entity_decode(strip_tags((string)($lesson['body'] ?? '')))), -1, PREG_SPLIT_NO_EMPTY));
            return (int)round($words / self::WORDS_PER_MIN * 60);
        }
        return 0; // pdf / link / embed: no reliable measure
    }

    /**
     * Old lessons often have no length recorded. The furthest point any learner's player reached
     * (position reports every 15 s) is then a good measure of the file length — only trusted when
     * several learners got there, so a single odd value cannot create false alarms.
     */
    public static function observedLength(int $lessonId): int
    {
        static $cache = [];
        if (isset($cache[$lessonId])) return $cache[$lessonId];
        $row = DB::one('SELECT MAX(position_sec) mx, COUNT(*) n FROM lesson_progress WHERE lesson_id = ? AND position_sec > 0', [$lessonId]);
        $mx = (int)($row['mx'] ?? 0);
        $near = $mx > 0 ? (int)DB::value('SELECT COUNT(*) FROM lesson_progress WHERE lesson_id = ? AND position_sec >= ?', [$lessonId, (int)floor($mx * 0.9)]) : 0;
        return $cache[$lessonId] = ($mx >= self::MIN_EXPECTED && $near >= 2 && $mx <= 36000) ? $mx : 0;
    }

    /** Lessons with completions that cannot be judged because their length is unknown (to fix in the lesson form) */
    public static function unknownLength(): array
    {
        $rows = DB::all("SELECT l.id, l.title, l.content_type, l.course_id, l.media_seconds, l.duration_minutes, c.title AS course_title, COUNT(*) AS completions
                           FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id JOIN courses c ON c.id = l.course_id
                          WHERE lp.status = 'completed' AND l.deleted_at IS NULL AND l.content_type IN ('video','audio')
                            AND COALESCE(l.media_seconds, 0) = 0 AND COALESCE(l.duration_minutes, 0) = 0
                          GROUP BY l.id, l.title, l.content_type, l.course_id, l.media_seconds, l.duration_minutes, c.title ORDER BY completions DESC");
        return array_values(array_filter($rows, fn($l) => self::observedLength((int)$l['id']) === 0));
    }

    /** @return array|null  [reason, severity, expected, elapsed, spent, watched] when suspicious */
    public static function evaluate(array $lesson, array $lp): ?array
    {
        if (($lp['status'] ?? '') !== 'completed') return null;
        $expected = self::expectedSeconds($lesson);
        if ($expected < self::MIN_EXPECTED) return null;
        $elapsed = !empty($lp['first_viewed_at']) && !empty($lp['completed_at']) ? max(0, strtotime((string)$lp['completed_at']) - strtotime((string)$lp['first_viewed_at'])) : null;
        $spent = (int)($lp['time_spent_sec'] ?? 0);
        $watched = (int)($lp['watched_sec'] ?? 0);
        $type = (string)$lesson['content_type'];
        $out = fn(string $r, string $sev) => ['reason' => $r, 'severity' => $sev, 'expected_sec' => $expected, 'elapsed_sec' => $elapsed, 'spent_sec' => $spent, 'watched_sec' => $watched];

        if (in_array($type, ['video', 'audio'], true)) {
            // even at 2× speed the file needs half of its length (10% grace)
            if ($elapsed !== null && $elapsed < $expected * 0.45) return $out('impossible_time', 'high');
            // the new player measures real playback; old completions have watched_sec = 0 and are judged by the other signals
            if ($watched > 0 && $watched < $expected * self::minWatchedPct() / 100) return $out('skipped', 'high');
            // a video needs the page open: almost no time on the page means it was not watched (audio may play in the background)
            if ($type === 'video' && $watched === 0 && $spent < $expected * 0.25 && ($elapsed === null || $elapsed < $expected * 0.6)) return $out('low_time', 'medium');
            return null;
        }
        if ($type === 'text' && $expected >= 120 && ($elapsed === null || $elapsed < $expected * 0.2) && $spent < $expected * 0.2) return $out('quick_read', 'medium');
        return null;
    }

    /**
     * Would completing this lesson NOW be suspicious? Used before the learner presses «تکمیل درس»,
     * so a completion that does not meet the rules is refused instead of being flagged afterwards.
     * @return array|null  evaluate() result + 'msg' (Persian explanation for the learner)
     */
    public static function precheck(array $lesson, int $userId): ?array
    {
        if (!self::enabled()) return null;
        $lp = DB::one('SELECT * FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$userId, (int)$lesson['id']]);
        if (!$lp || ($lp['status'] ?? '') === 'completed') return null;
        $f = self::evaluate($lesson, ['status' => 'completed', 'completed_at' => now()] + $lp);
        if (!$f) return null;
        $len = self::minutes((int)$f['expected_sec']);
        // text lessons unlock by themselves: quick_read clears once 20% of the reading time has passed since the first visit
        $wait = $f['reason'] === 'quick_read' ? max(1, (int)ceil((int)$f['expected_sec'] * 0.2) - (int)($f['elapsed_sec'] ?? 0)) : 0;
        if ($wait) $f['wait_sec'] = $wait + 2;
        $f['msg'] = match ($f['reason']) {
            'impossible_time' => 'این درس ' . $len . ' است، ولی از اولین باری که آن را باز کرده‌اید فقط ' . self::minutes((int)$f['elapsed_sec']) . ' گذشته است. لطفاً درس را کامل ' . ($lesson['content_type'] === 'audio' ? 'گوش دهید' : 'ببینید') . ' و بعد دکمه تکمیل را بزنید.',
            'skipped' => 'از ' . $len . ' این فایل، فقط ' . self::minutes((int)$f['watched_sec']) . ' واقعاً پخش شده و بقیه جلو زده شده است. حداقل ' . fa(self::minWatchedPct()) . '٪ فایل باید پخش شود؛ جلو زدن به‌عنوان دیدن حساب نمی‌شود.',
            'low_time' => 'زمان حضور شما در صفحه این درس (' . self::minutes((int)$f['spent_sec']) . ') خیلی کمتر از طول ویدیو (' . $len . ') است. لطفاً ویدیو را کامل ببینید.',
            'quick_read' => 'مطالعه این درس حدود ' . $len . ' زمان لازم دارد. لطفاً متن را با دقت بخوانید؛ دکمه تکمیل پس از ' . self::minutes($wait) . ' به‌صورت خودکار فعال می‌شود.',
            default => 'شرایط تکمیل این درس هنوز رعایت نشده است. لطفاً درس را کامل ببینید.',
        };
        return $f;
    }

    /** Check one completion; records / notifies when suspicious. Returns the flag id or null. */
    public static function check(int $userId, int $lessonId, bool $notify = true): ?int
    {
        if (!self::enabled()) return null;
        try {
            $lesson = DB::one('SELECT * FROM lessons WHERE id = ?', [$lessonId]);
            $lp = DB::one('SELECT * FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$userId, $lessonId]);
            if (!$lesson || !$lp) return null;
            $f = self::evaluate($lesson, $lp);
            if (!$f) return null;
            $old = DB::one('SELECT * FROM lesson_completion_flags WHERE user_id = ? AND lesson_id = ?', [$userId, $lessonId]);
            // an admin already accepted this one → never raise it again
            if ($old && $old['status'] === 'dismissed') return null;
            // already watched properly afterwards: only a new (later) completion can raise it again
            if ($old && $old['status'] === 'rewatched' && (string)$old['completed_at'] === (string)$lp['completed_at']) return null;
            $row = $f + ['course_id' => (int)$lesson['course_id'], 'completed_at' => $lp['completed_at'], 'status' => 'open', 'updated_at' => now()];
            if ($old) { DB::update('lesson_completion_flags', $row, 'id = ?', [(int)$old['id']]); $id = (int)$old['id']; }
            else $id = DB::insert('lesson_completion_flags', $row + ['user_id' => $userId, 'lesson_id' => $lessonId, 'created_at' => now()]);
            if ($notify) self::notify($id);
            return $id;
        } catch (\Throwable $e) {
            Logger::warning('completion audit failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Alert the learner and the managers about one flag */
    public static function notify(int $flagId, bool $managers = true): void
    {
        $f = DB::one('SELECT f.*, l.title AS lesson_title, l.content_type, u.first_name, u.last_name, u.supervisor_id, u.segment
                        FROM lesson_completion_flags f JOIN lessons l ON l.id = f.lesson_id JOIN users u ON u.id = f.user_id WHERE f.id = ?', [$flagId]);
        if (!$f) return;
        $len = self::minutes((int)$f['expected_sec']);
        $verb = $f['content_type'] === 'audio' ? 'شنیدن' : ($f['content_type'] === 'text' ? 'مطالعه' : 'دیدن');
        $took = $f['elapsed_sec'] !== null ? ' در ' . self::minutes((int)$f['elapsed_sec']) : '';
        $round = substr(md5((string)$f['completed_at']), 0, 6); // a new suspicious completion of the same lesson alerts again
        Notify::send((int)$f['user_id'], 'warning', 'درس «' . $f['lesson_title'] . '» بدون ' . $verb . ' کامل تکمیل شده است',
            'این درس ' . $len . ' است ولی' . $took . ' علامت تکمیل خورده (' . self::REASONS[$f['reason']] . '). برای یادگیری واقعی و قبولی در آزمون، لطفاً درس را از ابتدا تا انتها ' . ($f['content_type'] === 'audio' ? 'گوش دهید' : ($f['content_type'] === 'text' ? 'مطالعه کنید' : 'ببینید')) . '. این مورد برای مسئول آموزش هم ثبت شده است.',
            url('/learn/lesson/' . $f['lesson_id']), 'cflag-' . $f['id'] . '-' . $round);
        if ($managers) {
            $to = array_map('intval', DB::column("SELECT id FROM users WHERE is_root = 1 AND status = 'active' AND deleted_at IS NULL"));
            if ((int)$f['supervisor_id'] > 0) $to[] = (int)$f['supervisor_id'];
            Notify::send(array_values(array_diff($to, [(int)$f['user_id']])), 'warning',
                'تکمیل مشکوک: ' . trim($f['first_name'] . ' ' . $f['last_name']) . ' (' . label('segment_one', $f['segment']) . ') — «' . $f['lesson_title'] . '»',
                'درس ' . $len . $took . ' تکمیل شده: ' . self::REASONS[$f['reason']] . '.',
                url('/admin/reports/suspicious', ['segment' => $f['segment']]), 'cflag-admin-' . $f['id'] . '-' . $round);
        }
        DB::update('lesson_completion_flags', ['notified_at' => now()], 'id = ?', [$flagId]);
    }

    /**
     * Check completions recorded since the last scan (all of them the first time).
     * Learners get their own alert; managers get one summary per scan instead of one message per row.
     * @return array{checked:int, flagged:int}
     */
    public static function scan(int $limit = 50000, bool $notify = true, bool $full = true): array
    {
        if (!self::enabled()) return ['checked' => 0, 'flagged' => 0];
        try { DB::value('SELECT 1 FROM lesson_completion_flags LIMIT 1'); } catch (\Throwable) { return ['checked' => 0, 'flagged' => 0]; }
        @set_time_limit(300);
        // full = every completion ever (lesson lengths learned later make old completions judgeable); the
        // result is the same on every run, and learners are alerted only once per completion (dedupe key)
        try { self::repairResets(); } catch (\Throwable) {}
        $since = $full ? '2000-01-01 00:00:00' : (string)setting('completion_audit_last', '2000-01-01 00:00:00');
        $rows = DB::all("SELECT user_id, lesson_id, completed_at FROM lesson_progress WHERE status = 'completed' AND completed_at > ? ORDER BY completed_at LIMIT " . (int)$limit, [$since]);
        $flagged = []; $last = $since;
        foreach ($rows as $r) {
            $known = DB::value("SELECT status FROM lesson_completion_flags WHERE user_id = ? AND lesson_id = ?", [(int)$r['user_id'], (int)$r['lesson_id']]);
            $id = self::check((int)$r['user_id'], (int)$r['lesson_id'], false);
            if ($id && $known !== 'open') { $flagged[] = $id; if ($notify) self::notify($id, false); }
            $last = (string)$r['completed_at'];
        }
        try { \App\Core\Settings::set(['completion_audit_last' => $last ?: now()]); } catch (\Throwable) {}
        if ($notify && $flagged) {
            $segs = DB::pairs('SELECT u.segment, COUNT(*) FROM lesson_completion_flags f JOIN users u ON u.id = f.user_id WHERE f.id IN (' . DB::in($flagged) . ') GROUP BY u.segment', $flagged);
            $parts = [];
            foreach ($segs as $s => $n) $parts[] = label('segment', $s) . ': ' . fa($n);
            $to = array_map('intval', DB::column("SELECT id FROM users WHERE is_root = 1 AND status = 'active' AND deleted_at IS NULL"));
            Notify::send($to, 'warning', fa(count($flagged)) . ' تکمیل مشکوک درس شناسایی شد', implode(' · ', $parts) . ' — به هر فراگیر هم هشدار داده شد.', url('/admin/reports/suspicious'));
        }
        return ['checked' => count($rows), 'flagged' => count($flagged)];
    }

    /** Manager decision: make the learner watch the lesson again (completion removed) */
    public static function reset(int $flagId): bool
    {
        $f = DB::find('lesson_completion_flags', $flagId);
        if (!$f) return false;
        // already cancelled and not completed again since → nothing to do (no duplicate message)
        if ($f['status'] === 'reset' && DB::value('SELECT status FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [(int)$f['user_id'], (int)$f['lesson_id']]) !== 'completed') return false;
        DB::run("UPDATE lesson_progress SET status = 'in_progress', percent = 0, watched_sec = 0, position_sec = 0, completed_at = NULL WHERE user_id = ? AND lesson_id = ?", [(int)$f['user_id'], (int)$f['lesson_id']]);
        $r = self::reopenCourse((int)$f['user_id'], (int)$f['course_id']);
        DB::update('lesson_completion_flags', ['status' => 'reset', 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'updated_at' => now()], 'id = ?', [$flagId]);
        $title = (string)DB::value('SELECT title FROM lessons WHERE id = ?', [(int)$f['lesson_id']]);
        Notify::send((int)$f['user_id'], 'warning', 'درس «' . $title . '» باید دوباره دیده شود', 'مسئول آموزش تکمیل این درس را لغو کرد چون کامل دیده/شنیده نشده بود. لطفاً درس را تا انتها ببینید تا دوباره تکمیل شود.'
            . ($r['reopened'] ? ' تکمیل دوره هم تا دیدن کامل این درس لغو شد' . ($r['certificate'] ? ' و گواهی آن موقتاً باطل شد؛ پس از تکمیل دوباره، گواهی خودکار صادر می‌شود' : '') . '.' : ''),
            url('/learn/lesson/' . $f['lesson_id']));
        Audit::log('completion.reset', 'user', (int)$f['user_id'], 'success', ['lesson' => (int)$f['lesson_id']]);
        return true;
    }

    /**
     * A finished course stays «completed» forever in Enrollment::recalc. When one of its lessons is cancelled,
     * reopen it: progress is recalculated from the lessons really completed, the certificate earned with it is
     * revoked (re-issued automatically when the course is completed again) and paths go back to that step.
     * @return array{reopened:bool, certificate:bool}
     */
    public static function reopenCourse(int $userId, int $courseId): array
    {
        $out = ['reopened' => false, 'certificate' => false];
        $en = Enrollment::get($userId, $courseId);
        if (!$en) return $out;
        if ($en['status'] === 'completed') {
            DB::update('enrollments', ['status' => 'in_progress', 'completed_at' => null, 'updated_at' => now()], 'id = ?', [(int)$en['id']]);
            $out['reopened'] = true;
            $n = DB::update('certificates', ['revoked_at' => now(), 'revoke_reason' => 'لغو تکمیل درس (تکمیل بدون دیدن کامل) — پس از تکمیل دوباره صادر می‌شود'], 'user_id = ? AND course_id = ? AND revoked_at IS NULL', [$userId, $courseId]);
            $out['certificate'] = $n > 0;
            // paths that counted this course as passed
            DB::run("UPDATE path_enrollments SET status = 'in_progress', completed_at = NULL
                      WHERE user_id = ? AND status = 'completed' AND path_id IN (SELECT path_id FROM path_steps WHERE course_id = ?)", [$userId, $courseId]);
        }
        Enrollment::recalc($userId, $courseId);
        try { PathService::advance($userId); } catch (\Throwable) {}
        Audit::log('completion.reopen_course', 'user', $userId, 'success', ['course' => $courseId] + $out);
        return $out;
    }

    /** One-time repair: lessons cancelled before the course could be reopened (earlier versions) */
    public static function repairResets(): int
    {
        $n = 0;
        $rows = DB::all("SELECT DISTINCT f.user_id, f.course_id FROM lesson_completion_flags f
                           JOIN lesson_progress lp ON lp.user_id = f.user_id AND lp.lesson_id = f.lesson_id AND lp.status <> 'completed'
                           JOIN enrollments e ON e.user_id = f.user_id AND e.course_id = f.course_id AND e.status = 'completed'
                          WHERE f.status = 'reset'");
        foreach ($rows as $r) {
            try { if (self::reopenCourse((int)$r['user_id'], (int)$r['course_id'])['reopened']) $n++; } catch (\Throwable $e) { Logger::warning('repair reset failed: ' . $e->getMessage()); }
        }
        return $n;
    }

    public static function dismiss(int $flagId, string $note = ''): void
    {
        DB::update('lesson_completion_flags', ['status' => 'dismissed', 'note' => mb_substr($note, 0, 255) ?: null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'updated_at' => now()], 'id = ?', [$flagId]);
        Audit::log('completion.dismiss', 'flag', $flagId);
    }

    /** The learner went back and really played the lesson → close the open flag */
    public static function resolveIfWatched(int $userId, int $lessonId, int $watched, int $expected): void
    {
        if ($expected <= 0 || $watched < $expected * self::minWatchedPct() / 100) return;
        try {
            DB::run("UPDATE lesson_completion_flags SET status = 'rewatched', watched_sec = ?, updated_at = NOW() WHERE user_id = ? AND lesson_id = ? AND status IN ('open','reset')", [$watched, $userId, $lessonId]);
        } catch (\Throwable) {}
    }

    public static function openCount(): int
    {
        try {
            return (int)DB::value("SELECT COUNT(*) FROM lesson_completion_flags f JOIN users u ON u.id = f.user_id JOIN lessons l ON l.id = f.lesson_id JOIN courses c ON c.id = f.course_id
                                    WHERE f.status = 'open' AND u.deleted_at IS NULL AND l.deleted_at IS NULL AND c.deleted_at IS NULL");
        } catch (\Throwable) { return 0; }
    }

    public static function minutes(int $sec): string
    {
        if ($sec < 60) return fa($sec) . ' ثانیه';
        $m = intdiv($sec, 60); $s = $sec % 60;
        return fa($m) . ' دقیقه' . ($s >= 10 && $m < 10 ? ' و ' . fa($s) . ' ثانیه' : '');
    }
}
