<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Notify;

/** Learning paths: ordered steps (courses) with configurable pass conditions. */
final class PathService
{
    public static function steps(int $pathId): array
    {
        return DB::all('SELECT s.*, c.title AS course_title, c.summary AS course_summary, c.image_file_id, c.duration_minutes FROM path_steps s JOIN courses c ON c.id = s.course_id WHERE s.path_id = ? ORDER BY s.sort, s.id', [$pathId]);
    }

    public static function enroll(int $userId, int $pathId, array $opts = []): void
    {
        $path = DB::find('learning_paths', $pathId);
        if (!$path) return;
        $ex = DB::one('SELECT id FROM path_enrollments WHERE path_id = ? AND user_id = ?', [$pathId, $userId]);
        if (!$ex) {
            DB::insert('path_enrollments', ['path_id' => $pathId, 'user_id' => $userId, 'current_step' => 1, 'status' => 'in_progress', 'assigned_by' => $opts['assigned_by'] ?? null, 'started_at' => now(), 'created_at' => now()]);
            if (($opts['source'] ?? 'self') !== 'self') Notify::send($userId, 'assignment', 'مسیر آموزشی جدید: ' . $path['title'], 'مرحله به مرحله پیش بروید و به هدف برسید.', url('/learn/path/' . $pathId));
        }
        foreach (self::steps($pathId) as $st) {
            Enrollment::enroll($userId, (int)$st['course_id'], [
                'source' => $opts['source'] ?? 'path', 'path_id' => $pathId, 'training_type' => $opts['training_type'] ?? 'mandatory',
                'due_at' => $opts['due_at'] ?? null, 'assignment_id' => $opts['assignment_id'] ?? null, 'rule_id' => $opts['rule_id'] ?? null, 'notify' => false,
            ]);
        }
        self::advance($userId);
    }

    /** Evaluate a step's pass conditions for a user. */
    public static function stepPassed(int $userId, array $step): bool
    {
        $en = Enrollment::get($userId, (int)$step['course_id']);
        if (!$en) return false;
        if ((float)$en['progress_pct'] < (float)$step['min_progress']) return false;
        if ($step['min_score'] !== null && (float)($en['score'] ?? 0) < (float)$step['min_score']) return false;
        if ((int)$step['require_exercises'] === 1) {
            $ids = DB::column("SELECT id FROM exercises WHERE course_id = ? AND deleted_at IS NULL AND status = 'published'", [(int)$step['course_id']]);
            $st = Enrollment::exerciseStatus($userId, array_map('intval', $ids));
            foreach ($ids as $id) if (($st[(int)$id]['status'] ?? '') !== 'accepted') return false;
        }
        if ((int)$step['require_evaluation'] === 1) {
            if (!DB::value('SELECT 1 FROM practical_evaluations WHERE user_id = ? AND course_id = ? AND passed = 1 LIMIT 1', [$userId, (int)$step['course_id']])) return false;
        }
        return true;
    }

    /** Move users forward on their paths and unlock the next course. */
    public static function advance(int $userId): void
    {
        foreach (DB::all("SELECT * FROM path_enrollments WHERE user_id = ? AND status <> 'completed'", [$userId]) as $pe) {
            $steps = self::steps((int)$pe['path_id']);
            if (!$steps) continue;
            $passed = 0;
            foreach ($steps as $st) {
                if (self::stepPassed($userId, $st)) { $passed++; continue; }
                break;
            }
            $current = min($passed + 1, count($steps));
            $pct = round($passed * 100 / count($steps), 2);
            $upd = ['current_step' => $current, 'progress_pct' => $pct];
            if ($passed >= count($steps)) {
                $upd['status'] = 'completed';
                $upd['completed_at'] = now();
                $p = DB::find('learning_paths', (int)$pe['path_id']);
                Notify::send($userId, 'growth', 'مسیر آموزشی «' . ($p['title'] ?? '') . '» را کامل کردید!', '', url('/learn/path/' . $pe['path_id']));
            }
            DB::update('path_enrollments', $upd, 'id = ?', [$pe['id']]);
            // unlock the next step's course
            if (isset($steps[$passed])) {
                $en = Enrollment::get($userId, (int)$steps[$passed]['course_id']);
                if ($en && $en['status'] === 'locked') {
                    $after = Enrollment::recalc($userId, (int)$steps[$passed]['course_id']);
                    if ($after && $after['status'] !== 'locked' && $passed > 0) {
                        Notify::send($userId, 'assignment', 'مرحله بعدی مسیر باز شد: ' . $steps[$passed]['course_title'], '', url('/learn/course/' . $steps[$passed]['course_id']), 'path-unlock-' . $pe['path_id'] . '-' . $steps[$passed]['id']);
                    }
                }
            }
        }
    }
}
