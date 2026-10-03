<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Notify;

/**
 * Educational growth system. Each group has ordered stages with pass conditions:
 * required courses completed, min average exam score, min average progress, accepted exercises, practical evaluation.
 */
final class Growth
{
    public static function metrics(int $userId): array
    {
        $completed = array_map('intval', DB::column("SELECT course_id FROM enrollments WHERE user_id = ? AND status = 'completed'", [$userId]));
        $avgProgress = (float)(DB::value('SELECT AVG(progress_pct) FROM enrollments WHERE user_id = ?', [$userId]) ?? 0);
        $avgScore = DB::value("SELECT AVG(best) FROM (SELECT MAX(percent) best FROM exam_attempts WHERE user_id = ? AND status IN ('submitted','graded') GROUP BY exam_id) t", [$userId]);
        $exercises = (int)DB::value("SELECT COUNT(DISTINCT exercise_id) FROM exercise_submissions WHERE user_id = ? AND status = 'accepted'", [$userId]);
        $evals = DB::all('SELECT stage_id, course_id FROM practical_evaluations WHERE user_id = ? AND passed = 1', [$userId]);
        return ['completed' => $completed, 'avg_progress' => $avgProgress, 'avg_score' => $avgScore === null ? null : (float)$avgScore, 'exercises' => $exercises, 'evals' => $evals];
    }

    /** @return array<string,array{ok:bool,label:string}> requirement checklist for a stage */
    public static function check(array $stage, array $m): array
    {
        $out = [];
        $req = array_filter(array_map('intval', explode(',', (string)$stage['req_course_ids'])));
        if ($req) {
            $titles = DB::pairs('SELECT id, title FROM courses WHERE id IN (' . DB::in($req) . ')', array_values($req));
            foreach ($req as $cid) $out['c' . $cid] = ['ok' => in_array($cid, $m['completed'], true), 'label' => 'تکمیل دوره «' . ($titles[$cid] ?? ('#' . $cid)) . '»'];
        }
        if ($stage['req_min_progress'] !== null && $stage['req_min_progress'] !== '') $out['p'] = ['ok' => $m['avg_progress'] >= (float)$stage['req_min_progress'], 'label' => 'میانگین پیشرفت آموزشی حداقل ' . fa((int)$stage['req_min_progress']) . '٪'];
        if ($stage['req_min_avg_score'] !== null && $stage['req_min_avg_score'] !== '') $out['s'] = ['ok' => $m['avg_score'] !== null && $m['avg_score'] >= (float)$stage['req_min_avg_score'], 'label' => 'میانگین نمره آزمون‌ها حداقل ' . fa((float)$stage['req_min_avg_score'])];
        if (!empty($stage['req_exercises'])) $out['e'] = ['ok' => $m['exercises'] >= (int)$stage['req_exercises'], 'label' => fa((int)$stage['req_exercises']) . ' تمرین تأییدشده'];
        if ((int)$stage['req_evaluation'] === 1) {
            $ok = false;
            foreach ($m['evals'] as $ev) if ((int)$ev['stage_id'] === (int)$stage['id'] || $ev['stage_id'] === null) { $ok = true; break; }
            $out['v'] = ['ok' => $ok, 'label' => 'قبولی در ارزیابی عملی'];
        }
        return $out;
    }

    public static function stagesFor(int $groupId): array
    {
        return DB::all('SELECT * FROM growth_stages WHERE group_id = ? ORDER BY sort, id', [$groupId]);
    }

    /** Groups (with stages) that the user belongs to */
    public static function userGroups(int $userId): array
    {
        return DB::all('SELECT DISTINCT g.* FROM `groups` g JOIN group_members gm ON gm.group_id = g.id WHERE gm.user_id = ? AND EXISTS (SELECT 1 FROM growth_stages s WHERE s.group_id = g.id) ORDER BY g.sort', [$userId]);
    }

    /** Learning activity changed: recalculate the trader growth system (the old group stages are archived) */
    public static function evaluate(int $userId): void
    {
        TraderGrowth::refresh($userId);
    }

    /** Previous group-based auto promotion (kept for reference; no longer called) */
    public static function evaluateLegacy(int $userId): void
    {
        $m = null;
        foreach (self::userGroups($userId) as $g) {
            $stages = self::stagesFor((int)$g['id']);
            if (!$stages) continue;
            $m ??= self::metrics($userId);
            $cur = DB::one('SELECT ug.*, s.sort FROM user_growth ug JOIN growth_stages s ON s.id = ug.stage_id WHERE ug.user_id = ? AND ug.group_id = ?', [$userId, (int)$g['id']]);
            $reached = null;
            foreach ($stages as $st) {
                $checks = self::check($st, $m);
                $ok = !in_array(false, array_column($checks, 'ok'), true);
                if (!$ok || (int)$st['auto_promote'] !== 1) break;
                $reached = $st;
            }
            if (!$reached) continue;
            if ($cur && (int)$cur['sort'] >= (int)$reached['sort']) continue; // never demote automatically
            self::setStage($userId, (int)$g['id'], (int)$reached['id'], null, 'ارتقای خودکار');
            if ($cur || (int)$reached['sort'] > 1) {
                Notify::send($userId, 'growth', 'تبریک! به مرحله «' . $reached['name'] . '» ارتقا یافتید', 'در نظام رشد ' . $g['name'], url('/learn/growth'));
            }
        }
    }

    public static function setStage(int $userId, int $groupId, int $stageId, ?int $by, string $note = ''): void
    {
        DB::upsert('user_growth', ['user_id' => $userId, 'group_id' => $groupId, 'stage_id' => $stageId, 'achieved_at' => now(), 'promoted_by' => $by], ['stage_id', 'achieved_at', 'promoted_by']);
        DB::insert('growth_history', ['user_id' => $userId, 'group_id' => $groupId, 'stage_id' => $stageId, 'promoted_by' => $by, 'note' => mb_substr($note, 0, 250), 'created_at' => now()]);
    }

    public static function current(int $userId, int $groupId): ?array
    {
        return DB::one('SELECT s.* FROM user_growth ug JOIN growth_stages s ON s.id = ug.stage_id WHERE ug.user_id = ? AND ug.group_id = ?', [$userId, $groupId]);
    }
}
