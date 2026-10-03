<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Notify;
use App\Core\RateLimiter;
use App\Core\Settings;

/** Scheduled jobs. Run every 15 minutes from DirectAdmin cron: php cron.php */
final class Cron
{
    public static function run(bool $verbose = false): array
    {
        $lockFile = STORAGE_PATH . '/cache/cron.lock';
        $lock = fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => 'another cron is running'];
        $out = [];
        $step = function (string $name, callable $fn) use (&$out, $verbose) {
            $t = microtime(true);
            try { $r = $fn(); $out[$name] = $r; }
            catch (\Throwable $e) { $out[$name] = 'ERROR'; Logger::error('cron ' . $name . ': ' . $e->getMessage()); }
            if ($verbose) echo str_pad($name, 26) . ' ' . json_encode($out[$name], JSON_UNESCAPED_UNICODE) . ' (' . round((microtime(true) - $t) * 1000) . "ms)\n";
        };
        $step('expire_overdue', fn() => \App\Services\Enrollment::expireOverdue());
        $step('close_exam_attempts', fn() => ExamService::closeExpired());
        $step('recalc_queue', fn() => \App\Services\Enrollment::processQueue());
        $step('deadline_reminders', fn() => self::deadlineReminders());
        $step('growth_recalc', fn() => TraderGrowth::processDirty(3000, 30.0));
        $step('growth_services_sync', fn() => ServiceSync::continueRuns(45.0));

        $today = date('Y-m-d');
        if (setting('cron_daily_date') !== $today && (int)date('G') >= 1) {
            Settings::set(['cron_daily_date' => $today]);
            $step('rule_engine', fn() => Targeting::runAll());
            $step('growth_evaluate', fn() => self::evaluateGrowth());
            $step('inactivity_reminders', fn() => self::inactivityReminders());
            $step('nightly_backup', fn() => setting('auto_backup', '1') === '1' ? Backup::create('db', 'پشتیبان خودکار شبانه')['filename'] : 'off');
            $step('prune_backups', fn() => Backup::prune(10));
            $step('cleanup', fn() => self::cleanup());
        }
        Settings::set(['cron_last_run' => now()]);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $out;
    }

    private static function deadlineReminders(): int
    {
        $days = (int)setting('deadline_warning_days', 3);
        if ($days <= 0) return 0;
        $rows = DB::all("SELECT e.id, e.user_id, e.course_id, e.due_at, c.title FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN users u ON u.id = e.user_id
                          WHERE e.status IN ('not_started','in_progress','needs_retake') AND e.due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY) AND u.status = 'active' AND u.deleted_at IS NULL", [$days]);
        $n = 0;
        foreach ($rows as $r) {
            $n += Notify::send((int)$r['user_id'], 'deadline', 'یادآوری مهلت: ' . $r['title'], 'مهلت تکمیل این آموزش ' . jdate($r['due_at']) . ' است.', url('/learn/course/' . $r['course_id']), 'due-' . $r['id']);
        }
        return $n;
    }

    private static function inactivityReminders(): int
    {
        $days = (int)setting('inactivity_days', 14);
        $ids = array_map('intval', DB::column("SELECT u.id FROM users u WHERE u.status = 'active' AND u.deleted_at IS NULL AND u.last_login_at IS NOT NULL AND u.last_login_at < ? AND EXISTS (SELECT 1 FROM enrollments e WHERE e.user_id = u.id AND e.status NOT IN ('completed'))", [date('Y-m-d H:i:s', strtotime("-$days days"))]));
        return Notify::send($ids, 'inactivity', 'آموزش‌های شما منتظرتان هستند', 'مدتی است وارد سامانه آموزش نشده‌اید. همین امروز ادامه دهید!', url('/learn'), 'inactive-' . date('o-W'));
    }

    /** Daily: recalculate every trader (new events of a type, schedule-based items, …) */
    private static function evaluateGrowth(): int
    {
        TraderGrowth::markAllDirty(false);
        return TraderGrowth::processDirty(3000, 40.0);
    }

    private static function cleanup(): array
    {
        RateLimiter::cleanup();
        $tmp = 0;
        foreach (glob(STORAGE_PATH . '/tmp/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 86400) { @unlink($f); $tmp++; }
        $logs = 0;
        foreach (glob(STORAGE_PATH . '/logs/app-*.log') ?: [] as $f) if (filemtime($f) < time() - 90 * 86400) { @unlink($f); $logs++; }
        $sess = 0;
        foreach (glob(STORAGE_PATH . '/sessions/sess_*') ?: [] as $f) if (filemtime($f) < time() - 7 * 86400) { @unlink($f); $sess++; }
        DB::run('DELETE FROM activity_logs WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-3 years'))]);
        return ['tmp' => $tmp, 'logs' => $logs, 'sessions' => $sess];
    }
}
