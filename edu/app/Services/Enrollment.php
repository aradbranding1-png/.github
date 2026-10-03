<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Activity;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Notify;

/**
 * Enrollment & progress engine.
 * Progress = completed items / total items, where items are: published lessons + required published exams + required exercises.
 * Statuses: not_started, in_progress, completed, needs_retake, failed, locked, expired.
 */
final class Enrollment
{
    public static function get(int $userId, int $courseId): ?array
    {
        return DB::one('SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?', [$userId, $courseId]);
    }

    /**
     * Enroll (or upgrade) a user in a course. Returns enrollment id.
     * opts: source, training_type, due_at, assignment_id, rule_id, path_id, notify(bool)
     */
    public static function enroll(int $userId, int $courseId, array $opts = []): int
    {
        $course = DB::one('SELECT id, title, training_type, status FROM courses WHERE id = ? AND deleted_at IS NULL', [$courseId]);
        if (!$course) throw new \InvalidArgumentException('course not found');
        $type = $opts['training_type'] ?? $course['training_type'];
        $ex = self::get($userId, $courseId);
        if ($ex) {
            $upd = [];
            $rank = ['suggested' => 0, 'optional' => 1, 'supplementary' => 2, 'mandatory' => 3];
            if (($rank[$type] ?? 0) > ($rank[$ex['training_type']] ?? 0)) $upd['training_type'] = $type;
            if (!empty($opts['due_at']) && (empty($ex['due_at']) || $opts['due_at'] < $ex['due_at'])) $upd['due_at'] = $opts['due_at'];
            if (!empty($opts['path_id']) && empty($ex['path_id'])) $upd['path_id'] = (int)$opts['path_id'];
            if ($ex['status'] === 'expired' && !empty($opts['due_at']) && $opts['due_at'] > now()) $upd['status'] = 'in_progress';
            if ($upd) { $upd['updated_at'] = now(); DB::update('enrollments', $upd, 'id = ?', [$ex['id']]); self::recalc($userId, $courseId); }
            return (int)$ex['id'];
        }
        $id = DB::insert('enrollments', [
            'user_id' => $userId, 'course_id' => $courseId, 'source' => $opts['source'] ?? 'self',
            'assignment_id' => $opts['assignment_id'] ?? null, 'rule_id' => $opts['rule_id'] ?? null, 'path_id' => $opts['path_id'] ?? null,
            'training_type' => $type, 'status' => 'not_started', 'due_at' => $opts['due_at'] ?? null,
            'assigned_by' => ($opts['source'] ?? 'self') === 'self' ? null : Auth::id(), 'created_at' => now(),
        ]);
        self::recalc($userId, $courseId);
        if (($opts['notify'] ?? true) && ($opts['source'] ?? 'self') !== 'self') {
            Notify::send($userId, 'assignment', 'آموزش جدید برای شما: ' . $course['title'], 'نوع آموزش: ' . label('training_type', $type) . (!empty($opts['due_at']) ? ' — مهلت: ' . jdate($opts['due_at']) : ''), url('/learn/course/' . $courseId));
        }
        return $id;
    }

    public static function prerequisitesMet(int $userId, int $courseId): bool
    {
        $pre = DB::column('SELECT prerequisite_id FROM course_prerequisites WHERE course_id = ?', [$courseId]);
        if (!$pre) return true;
        $done = (int)DB::value('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND status = \'completed\' AND course_id IN (' . DB::in($pre) . ')', array_merge([$userId], $pre));
        return $done >= count($pre);
    }

    /** @return array{lessons: array, exams: array, exercises: array} */
    public static function items(int $courseId): array
    {
        return [
            'lessons' => DB::all("SELECT id, course_id, title, content_type, sort, section_title, prerequisite_lesson_id, duration_minutes, is_preview FROM lessons WHERE course_id = ? AND deleted_at IS NULL AND status = 'published' ORDER BY sort, id", [$courseId]),
            'exams' => DB::all("SELECT * FROM exams WHERE course_id = ? AND deleted_at IS NULL AND status = 'published' ORDER BY id", [$courseId]),
            'exercises' => DB::all("SELECT * FROM exercises WHERE course_id = ? AND deleted_at IS NULL AND status = 'published' ORDER BY id", [$courseId]),
        ];
    }

    /** Best attempt per exam for user */
    public static function bestAttempts(int $userId, array $examIds): array
    {
        if (!$examIds) return [];
        $rows = DB::all('SELECT exam_id, MAX(percent) AS best, MAX(passed) AS passed, COUNT(*) AS attempts, SUM(status = \'pending_review\') AS pending FROM exam_attempts WHERE user_id = ? AND status IN (\'submitted\',\'graded\',\'pending_review\') AND exam_id IN (' . DB::in($examIds) . ') GROUP BY exam_id', array_merge([$userId], $examIds));
        $o = [];
        foreach ($rows as $r) $o[(int)$r['exam_id']] = $r;
        return $o;
    }

    public static function exerciseStatus(int $userId, array $exIds): array
    {
        if (!$exIds) return [];
        $o = [];
        foreach (DB::all('SELECT s.* FROM exercise_submissions s JOIN (SELECT exercise_id, MAX(id) mid FROM exercise_submissions WHERE user_id = ? AND exercise_id IN (' . DB::in($exIds) . ') GROUP BY exercise_id) t ON t.mid = s.id', array_merge([$userId], $exIds)) as $s) {
            $o[(int)$s['exercise_id']] = $s;
        }
        return $o;
    }

    public static function pathLocked(array $en): bool
    {
        if (empty($en['path_id'])) return false;
        $step = DB::one('SELECT * FROM path_steps WHERE path_id = ? AND course_id = ? ORDER BY sort LIMIT 1', [(int)$en['path_id'], (int)$en['course_id']]);
        if (!$step) return false;
        $prev = DB::one('SELECT * FROM path_steps WHERE path_id = ? AND sort < ? ORDER BY sort DESC LIMIT 1', [(int)$en['path_id'], (int)$step['sort']]);
        if (!$prev) return false;
        return !PathService::stepPassed((int)$en['user_id'], $prev);
    }

    /** Recalculate progress/status of one enrollment. Returns the updated enrollment. */
    public static function recalc(int $userId, int $courseId): ?array
    {
        $en = self::get($userId, $courseId);
        if (!$en) return null;
        $course = DB::one('SELECT * FROM courses WHERE id = ?', [$courseId]);
        if (!$course) return $en;
        $it = self::items($courseId);
        $lessonIds = array_map(fn($l) => (int)$l['id'], $it['lessons']);
        $doneLessons = $lessonIds ? (int)DB::value("SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND status = 'completed' AND lesson_id IN (" . DB::in($lessonIds) . ')', array_merge([$userId], $lessonIds)) : 0;
        $reqExams = array_values(array_filter($it['exams'], fn($e) => (int)$e['is_required'] === 1));
        $best = self::bestAttempts($userId, array_map(fn($e) => (int)$e['id'], $it['exams']));
        $examsPassed = 0; $failedFinal = false; $retake = false;
        foreach ($reqExams as $ex) {
            $b = $best[(int)$ex['id']] ?? null;
            if ($b && (int)$b['passed'] === 1) { $examsPassed++; continue; }
            if ($b && (int)$b['pending'] === 0 && (int)$b['attempts'] > 0) {
                $retake = true; // attempt limit is per day, so a failed learner can always try again another day
            }
        }
        $reqEx = array_values(array_filter($it['exercises'], fn($e) => (int)$e['is_required'] === 1));
        $exSt = self::exerciseStatus($userId, array_map(fn($e) => (int)$e['id'], $reqEx));
        $exDone = 0;
        foreach ($reqEx as $e) {
            $s = $exSt[(int)$e['id']] ?? null;
            if ($s && $s['status'] === 'accepted') $exDone++;
            elseif ($s && in_array($s['status'], ['needs_revision', 'rejected'], true)) $retake = true;
        }
        $total = count($lessonIds) + count($reqExams) + count($reqEx);
        $done = $doneLessons + $examsPassed + $exDone;
        $pct = $total > 0 ? round($done * 100 / $total, 2) : 0;
        $scores = array_map(fn($b) => (float)$b['best'], array_filter($best, fn($b) => $b['best'] !== null));
        $score = $scores ? round(array_sum($scores) / count($scores), 2) : null;
        // course pass score applies to the average of the course exams (only when the course has graded exams)
        $belowPass = $score !== null && $score < (float)($course['pass_score'] ?? 0);

        $status = $en['status'];
        $wasCompleted = $status === 'completed';
        if ($wasCompleted) {
            $status = 'completed';
        } elseif (!self::prerequisitesMet($userId, $courseId) || self::pathLocked($en)) {
            $status = 'locked';
        } elseif ($total > 0 && $done >= $total && $belowPass) {
            $status = 'needs_retake'; // everything done, but the course average is under the course pass score
        } elseif ($total > 0 && $done >= $total) {
            $status = 'completed';
        } elseif ($failedFinal) {
            $status = 'failed';
        } elseif (($course['expire_at'] && $course['expire_at'] < now()) || ($en['due_at'] && $en['due_at'] < now() && $en['status'] === 'expired')) {
            $status = 'expired';
        } elseif ($retake) {
            $status = 'needs_retake';
        } else {
            $hasActivity = $pct > 0 || DB::value('SELECT 1 FROM lesson_progress WHERE user_id = ? AND course_id = ? LIMIT 1', [$userId, $courseId]);
            $status = $hasActivity ? 'in_progress' : 'not_started';
        }
        $upd = ['progress_pct' => $wasCompleted ? 100 : $pct, 'score' => $score, 'status' => $status, 'updated_at' => now()];
        if ($status === 'in_progress' && !$en['started_at']) $upd['started_at'] = now();
        $justCompleted = $status === 'completed' && !$wasCompleted;
        if ($justCompleted) { $upd['completed_at'] = now(); $upd['progress_pct'] = 100; if (!$en['started_at']) $upd['started_at'] = now(); }
        DB::update('enrollments', $upd, 'id = ?', [$en['id']]);

        if ($justCompleted) self::onCompleted($userId, $course);
        return array_merge($en, $upd);
    }

    private static function onCompleted(int $userId, array $course): void
    {
        Activity::track($userId, 'course_complete', 'course', (int)$course['id']);
        Notify::send($userId, 'course', 'تبریک! دوره «' . $course['title'] . '» را با موفقیت تکمیل کردید', '', url('/learn/course/' . $course['id']));
        if ((int)$course['has_certificate'] === 1) self::issueCertificate($userId, (int)$course['id']);
        // unlock dependent courses
        foreach (DB::column("SELECT e.course_id FROM enrollments e JOIN course_prerequisites p ON p.course_id = e.course_id WHERE e.user_id = ? AND p.prerequisite_id = ? AND e.status = 'locked'", [$userId, (int)$course['id']]) as $cid) {
            self::recalc($userId, (int)$cid);
        }
        PathService::advance($userId);
        Growth::evaluate($userId);
    }

    public static function recalcUser(int $userId): void
    {
        foreach (DB::column('SELECT course_id FROM enrollments WHERE user_id = ?', [$userId]) as $cid) self::recalc($userId, (int)$cid);
    }

    /** Recalculate all learners of a course. Large courses are deferred to cron to keep the admin UI fast. */
    public static function recalcCourse(int $courseId, bool $force = false): void
    {
        $uids = DB::column("SELECT user_id FROM enrollments WHERE course_id = ? AND status <> 'completed'", [$courseId]);
        if (!$force && count($uids) > 300 && PHP_SAPI !== 'cli') {
            $pending = array_filter(explode(',', (string)setting('recalc_queue', '')));
            $pending[] = (string)$courseId;
            \App\Core\Settings::set(['recalc_queue' => implode(',', array_unique($pending))]);
            return;
        }
        foreach ($uids as $uid) self::recalc((int)$uid, $courseId);
    }

    /** Cron: process deferred course recalculations */
    public static function processQueue(): int
    {
        $pending = array_filter(explode(',', (string)setting('recalc_queue', '')));
        if (!$pending) return 0;
        \App\Core\Settings::set(['recalc_queue' => '']);
        foreach ($pending as $cid) self::recalcCourse((int)$cid, true);
        return count($pending);
    }

    public static function issueCertificate(int $userId, int $courseId, bool $manual = false): ?int
    {
        $existing = DB::value('SELECT id FROM certificates WHERE user_id = ? AND course_id = ? AND revoked_at IS NULL', [$userId, $courseId]);
        if ($existing) return (int)$existing;
        $u = DB::find('users', $userId);
        $c = DB::one('SELECT c.*, CONCAT(i.first_name, \' \', i.last_name) AS instructor_name FROM courses c LEFT JOIN users i ON i.id = c.instructor_id WHERE c.id = ?', [$courseId]);
        if (!$u || !$c) return null;
        $en = self::get($userId, $courseId);
        do { $code = 'AB-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)); } while (DB::value('SELECT 1 FROM certificates WHERE code = ?', [$code]));
        $id = DB::insert('certificates', [
            'code' => $code, 'user_id' => $userId, 'course_id' => $courseId, 'user_name' => full_name($u), 'course_title' => $c['title'],
            'instructor_name' => trim((string)$c['instructor_name']) ?: null, 'score' => $en['score'] ?? null, 'issued_at' => now(),
            'expires_at' => $c['certificate_validity_months'] ? date('Y-m-d H:i:s', strtotime('+' . (int)$c['certificate_validity_months'] . ' months')) : null,
            'issued_by' => $manual ? Auth::id() : null,
        ]);
        Notify::send($userId, 'certificate', 'گواهی دوره «' . $c['title'] . '» برای شما صادر شد', 'کد گواهی: ' . $code, url('/learn/certificate/' . $code));
        if ($manual) Audit::log('certificates.issue', 'certificate', $id, 'success', ['user' => $userId, 'course' => $courseId]);
        return $id;
    }

    /** Can this lesson be opened by the user? (sequential course / lesson prerequisite) */
    public static function lessonUnlocked(int $userId, array $lesson, array $course, array $allLessons, array $completedIds): bool
    {
        if (!empty($lesson['prerequisite_lesson_id']) && !in_array((int)$lesson['prerequisite_lesson_id'], $completedIds, true)) return false;
        if ((int)$course['is_sequential'] === 1) {
            foreach ($allLessons as $l) {
                if ((int)$l['id'] === (int)$lesson['id']) return true;
                if (!in_array((int)$l['id'], $completedIds, true)) return false;
            }
        }
        return true;
    }

    /** Human message explaining why a lesson is locked (names the lesson to finish first) */
    public static function lockReason(array $lesson, array $course, array $allLessons, array $completedIds, ?array $enrollment): string
    {
        if (!$enrollment) return 'برای دیدن این درس ابتدا در دوره ثبت‌نام کنید.';
        if ($enrollment['status'] === 'locked') return 'این دوره هنوز قفل است؛ ابتدا پیش‌نیازهای دوره را تکمیل کنید.';
        $block = null;
        $pre = (int)($lesson['prerequisite_lesson_id'] ?? 0);
        if ($pre && !in_array($pre, $completedIds, true)) foreach ($allLessons as $l) if ((int)$l['id'] === $pre) $block = $l;
        if (!$block && (int)$course['is_sequential'] === 1) {
            foreach ($allLessons as $l) {
                if ((int)$l['id'] === (int)$lesson['id']) break;
                if (!in_array((int)$l['id'], $completedIds, true)) { $block = $l; break; }
            }
        }
        return $block ? 'برای ورود به این درس ابتدا باید درس‌های قبلی را تکمیل کنید. درس بعدی شما: «' . $block['title'] . '»' : 'برای ورود به این درس ابتدا باید درس‌های قبلی را تکمیل کنید.';
    }

    public static function completedLessonIds(int $userId, int $courseId): array
    {
        return array_map('intval', DB::column("SELECT lesson_id FROM lesson_progress WHERE user_id = ? AND course_id = ? AND status = 'completed'", [$userId, $courseId]));
    }

    /** Daily maintenance: expire overdue enrollments and courses. */
    public static function expireOverdue(): int
    {
        $n = DB::run("UPDATE enrollments SET status = 'expired', updated_at = NOW() WHERE status IN ('not_started','in_progress','needs_retake') AND due_at IS NOT NULL AND due_at < NOW()")->rowCount();
        $n += DB::run("UPDATE enrollments e JOIN courses c ON c.id = e.course_id SET e.status = 'expired', e.updated_at = NOW() WHERE e.status IN ('not_started','in_progress','needs_retake','locked') AND c.expire_at IS NOT NULL AND c.expire_at < NOW()")->rowCount();
        return $n;
    }
}
