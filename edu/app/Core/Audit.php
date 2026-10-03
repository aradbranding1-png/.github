<?php
declare(strict_types=1);

namespace App\Core;

/** Audit log for administrative actions: who, what, when, IP, target, result. */
final class Audit
{
    public static function log(string $action, ?string $targetType = null, ?int $targetId = null, string $result = 'success', array $details = []): void
    {
        try {
            $uid = $_SESSION['uid'] ?? null;
            $details = self::scrub($details);
            DB::insert('audit_logs', [
                'user_id' => $uid ? (int)$uid : null,
                'impersonator_id' => !empty($_SESSION['imp_root']) ? (int)$_SESSION['imp_root'] : null,
                'action' => mb_substr($action, 0, 100),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'result' => $result,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : Request::ip(),
                'user_agent' => PHP_SAPI === 'cli' ? 'cli' : Request::userAgent(),
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('audit write failed: ' . $e->getMessage());
        }
    }

    private static function scrub(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/pass|secret|token|key/i', $k)) $a[$k] = '[REDACTED]';
            elseif (is_array($v)) $a[$k] = self::scrub($v);
        }
        return $a;
    }
}
