<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Activity;
use App\Core\DB;
use App\Core\Notify;

/** Exam engine: attempt creation (random/shuffled), auto-grading, manual essay grading. */
final class ExamService
{
    public static function questionPool(array $exam): array
    {
        $fixed = DB::all('SELECT q.*, eq.score AS eq_score, eq.sort AS eq_sort FROM exam_questions eq JOIN questions q ON q.id = eq.question_id WHERE eq.exam_id = ? AND q.deleted_at IS NULL AND q.is_active = 1 ORDER BY eq.sort, q.id', [(int)$exam['id']]);
        if ($exam['random_count'] && $exam['random_category_id']) {
            $extra = DB::all('SELECT q.*, NULL AS eq_score, 0 AS eq_sort FROM questions q WHERE q.category_id = ? AND q.deleted_at IS NULL AND q.is_active = 1', [(int)$exam['random_category_id']]);
            $ids = array_column($fixed, 'id');
            $fixed = array_merge($fixed, array_values(array_filter($extra, fn($q) => !in_array($q['id'], $ids, true))));
        }
        return $fixed;
    }

    /**
     * Max score of each question. Questions without a manual score share what is left of 100
     * equally (5 questions → 20 each), so an exam always totals 100 unless scores were typed in.
     * @param int[] $qids  @param array $overrides [qid => score|null]
     */
    public static function scoreMap(array $qids, array $overrides): array
    {
        $fixed = 0.0; $free = 0;
        foreach ($qids as $q) {
            $o = $overrides[$q] ?? null;
            if ($o !== null && $o !== '') $fixed += (float)$o; else $free++;
        }
        $each = $free ? max(0.0, (100 - $fixed) / $free) : 0.0;
        $map = []; $seen = 0; $r = round($each, 2);
        foreach ($qids as $q) {
            $o = $overrides[$q] ?? null;
            if ($o !== null && $o !== '') { $map[$q] = (float)$o; continue; }
            $seen++;
            // the last auto-scored question takes the rounding remainder so the total is exactly 100
            $map[$q] = $seen === $free ? max(0.0, round(100 - $fixed - $r * ($free - 1), 2)) : $r;
        }
        return $map;
    }

    public static function attemptsUsed(int $examId, int $userId): int
    {
        return (int)DB::value("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status <> 'in_progress'", [$examId, $userId]);
    }

    /** Start of "today" in the system timezone — attempt limits are per day */
    public static function dayStart(): string
    {
        return date('Y-m-d 00:00:00');
    }

    /** Attempts started today (including an unfinished one) — the per-day limit counts these */
    public static function attemptsToday(int $examId, int $userId): int
    {
        return (int)DB::value('SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND started_at >= ?', [$examId, $userId, self::dayStart()]);
    }

    /** Remaining attempts today, or null when unlimited */
    public static function remainingToday(array $exam, int $userId): ?int
    {
        $max = (int)$exam['max_attempts'];
        return $max > 0 ? max(0, $max - self::attemptsToday((int)$exam['id'], $userId)) : null;
    }

    public static function start(array $exam, int $userId): int
    {
        $open = DB::one("SELECT * FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1", [(int)$exam['id'], $userId]);
        if ($open) return (int)$open['id'];
        $pool = self::questionPool($exam);
        if (!$pool) throw new \App\Core\HttpException(422, 'این آزمون هنوز سؤالی ندارد.');
        if ((int)$exam['shuffle_questions'] === 1 || $exam['random_count']) shuffle($pool);
        if ($exam['random_count'] && (int)$exam['random_count'] < count($pool)) $pool = array_slice($pool, 0, (int)$exam['random_count']);
        $qids = array_map(fn($q) => (int)$q['id'], $pool);
        $optOrder = [];
        foreach ($qids as $qid) {
            $opts = array_map('intval', DB::column('SELECT id FROM question_options WHERE question_id = ? ORDER BY sort, id', [$qid]));
            if ((int)$exam['shuffle_options'] === 1) shuffle($opts);
            $optOrder[$qid] = $opts;
        }
        $deadline = $exam['time_limit_minutes'] ? date('Y-m-d H:i:s', time() + (int)$exam['time_limit_minutes'] * 60) : null;
        return DB::insert('exam_attempts', [
            'exam_id' => (int)$exam['id'], 'user_id' => $userId, 'attempt_no' => self::attemptsUsed((int)$exam['id'], $userId) + 1,
            'question_ids' => json_encode($qids), 'option_order' => json_encode($optOrder), 'status' => 'in_progress',
            'started_at' => now(), 'deadline_at' => $deadline,
        ]);
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(normalize_input($s));
        $s = str_replace(["\u{200c}", '‌', 'ـ'], ' ', $s);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    /** Grade and finalize an attempt. $answers: [qid => mixed]. */
    public static function submit(array $attempt, array $answers): array
    {
        $exam = DB::find('exams', (int)$attempt['exam_id']);
        $qids = json_decode((string)$attempt['question_ids'], true) ?: [];
        $scores = self::scoreMap(array_map('intval', $qids), DB::pairs('SELECT question_id, score FROM exam_questions WHERE exam_id = ?', [(int)$exam['id']]));
        $total = 0.0; $got = 0.0; $pending = false;
        DB::transaction(function () use ($attempt, $answers, $qids, $scores, &$total, &$got, &$pending) {
            foreach ($qids as $qid) {
                $q = DB::find('questions', (int)$qid);
                if (!$q) continue;
                $max = (float)($scores[(int)$qid] ?? 0);
                $total += $max;
                $ans = $answers[$qid] ?? null;
                $correctIds = array_map('intval', DB::column('SELECT id FROM question_options WHERE question_id = ? AND is_correct = 1', [(int)$qid]));
                $isCorrect = null; $sc = null; $stored = null;
                switch ($q['type']) {
                    case 'single':
                    case 'truefalse':
                        $sel = (int)(is_array($ans) ? ($ans[0] ?? 0) : $ans);
                        $stored = $sel ? (string)$sel : null;
                        $isCorrect = $sel && in_array($sel, $correctIds, true) ? 1 : 0;
                        $sc = $isCorrect ? $max : 0;
                        break;
                    case 'multiple':
                        $sel = array_values(array_unique(array_map('intval', (array)($ans ?? []))));
                        sort($sel); $cc = $correctIds; sort($cc);
                        $stored = json_encode($sel);
                        $isCorrect = $sel === $cc ? 1 : 0;
                        $sc = $isCorrect ? $max : 0;
                        break;
                    case 'short':
                        $txt = self::norm((string)(is_array($ans) ? '' : $ans));
                        $stored = mb_substr((string)$ans, 0, 1000);
                        $accepted = array_map(fn($t) => self::norm((string)$t), DB::column('SELECT text FROM question_options WHERE question_id = ?', [(int)$qid]));
                        $isCorrect = $txt !== '' && in_array($txt, $accepted, true) ? 1 : 0;
                        $sc = $isCorrect ? $max : 0;
                        break;
                    case 'essay':
                        $stored = mb_substr((string)(is_array($ans) ? '' : $ans), 0, 20000);
                        $pending = $pending || trim((string)$stored) !== '';
                        if (trim((string)$stored) === '') { $isCorrect = 0; $sc = 0; }
                        break;
                }
                if ($sc !== null) $got += $sc;
                DB::upsert('attempt_answers', ['attempt_id' => (int)$attempt['id'], 'question_id' => (int)$qid, 'answer' => $stored, 'is_correct' => $isCorrect, 'score' => $sc, 'max_score' => $max], ['answer', 'is_correct', 'score', 'max_score']);
            }
        });
        $pct = $total > 0 ? round($got * 100 / $total, 2) : 0;
        $upd = ['submitted_at' => now(), 'max_score' => $total, 'score' => $got, 'percent' => $pct];
        if ($pending) { $upd['status'] = 'pending_review'; $upd['passed'] = null; }
        else { $upd['status'] = 'graded'; $upd['passed'] = $pct >= (float)$exam['pass_score'] ? 1 : 0; $upd['graded_at'] = now(); }
        DB::update('exam_attempts', $upd, 'id = ?', [(int)$attempt['id']]);
        Activity::track((int)$attempt['user_id'], 'exam_submit', 'exam', (int)$exam['id'], ['percent' => $pct]);
        if ($exam['course_id']) {
            DB::run('UPDATE enrollments SET last_activity_at = NOW() WHERE user_id = ? AND course_id = ?', [(int)$attempt['user_id'], (int)$exam['course_id']]);
            Enrollment::recalc((int)$attempt['user_id'], (int)$exam['course_id']);
        }
        if ($pending) {
            Notify::send(array_slice(Notify::usersWithPermission('reviews.approve'), 0, 50), 'exam', 'پاسخ تشریحی جدید برای تصحیح', $exam['title'], url('/admin/reviews'));
        } else {
            Notify::send((int)$attempt['user_id'], 'exam_result', 'نتیجه آزمون «' . $exam['title'] . '»: ' . ($upd['passed'] ? 'قبول' : 'مردود'), 'نمره شما: ' . fa($pct) . '٪', url('/learn/attempt/' . $attempt['id'] . '/result'));
            Growth::evaluate((int)$attempt['user_id']);
        }
        return DB::find('exam_attempts', (int)$attempt['id']);
    }

    /** Finalize manual grading of essay answers. $grades: [answer_id => [score, feedback]] */
    public static function finalizeReview(array $attempt, array $grades, int $reviewerId): void
    {
        $exam = DB::find('exams', (int)$attempt['exam_id']);
        foreach ($grades as $aid => $g) {
            $a = DB::one('SELECT * FROM attempt_answers WHERE id = ? AND attempt_id = ?', [(int)$aid, (int)$attempt['id']]);
            if (!$a) continue;
            $sc = max(0, min((float)$a['max_score'], (float)($g['score'] ?? 0)));
            DB::update('attempt_answers', ['score' => $sc, 'is_correct' => $sc >= (float)$a['max_score'] * 0.5 ? 1 : 0, 'feedback' => mb_substr((string)($g['feedback'] ?? ''), 0, 2000), 'reviewed_by' => $reviewerId, 'reviewed_at' => now()], 'id = ?', [(int)$aid]);
        }
        $sum = DB::one('SELECT SUM(score) s, SUM(max_score) m, SUM(score IS NULL) pending FROM attempt_answers WHERE attempt_id = ?', [(int)$attempt['id']]);
        if ((int)$sum['pending'] > 0) return;
        $pct = (float)$sum['m'] > 0 ? round((float)$sum['s'] * 100 / (float)$sum['m'], 2) : 0;
        $passed = $pct >= (float)$exam['pass_score'] ? 1 : 0;
        DB::update('exam_attempts', ['score' => (float)$sum['s'], 'percent' => $pct, 'passed' => $passed, 'status' => 'graded', 'graded_at' => now(), 'graded_by' => $reviewerId], 'id = ?', [(int)$attempt['id']]);
        if ($exam['course_id']) Enrollment::recalc((int)$attempt['user_id'], (int)$exam['course_id']);
        Growth::evaluate((int)$attempt['user_id']);
        Notify::send((int)$attempt['user_id'], 'exam_result', 'نتیجه آزمون «' . $exam['title'] . '» اعلام شد: ' . ($passed ? 'قبول' : 'مردود'), 'نمره شما: ' . fa($pct) . '٪', url('/learn/attempt/' . $attempt['id'] . '/result'));
    }

    /** Auto-submit expired in-progress attempts (cron). */
    public static function closeExpired(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM exam_attempts WHERE status = 'in_progress' AND deadline_at IS NOT NULL AND deadline_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)") as $a) {
            self::submit($a, []);
            $n++;
        }
        return $n;
    }
}
