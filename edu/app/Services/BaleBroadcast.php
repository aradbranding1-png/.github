<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

/**
 * Announces new webinars / meetings and new merchant courses to the users connected to the Bale bot
 * of my.aradbranding.me. The member list stays in «my»: we only call its API
 * (POST {base}/api/edu/bale/broadcast with a Bearer token) and it sends through its own bot.
 *
 * Safety: an item is sent only `bale_delay_min` minutes (default 60) after it was published, and only if it
 * still exists and is still published — so something published by mistake and removed is never sent.
 * Every item is sent once (ref = event-ID / course-ID, also deduplicated on the «my» side).
 */
final class BaleBroadcast
{
    public const EVENT_TYPES = ['webinar', 'meeting'];
    public const STATUSES = ['sent' => ['ارسال شد', 'success'], 'sending' => ['در حال ارسال', 'info'], 'failed' => ['ناموفق', 'danger'], 'skipped' => ['ارسال نشد (لغو)', 'gray']];

    public static function configured(): bool
    {
        return self::base() !== '' && self::token() !== '';
    }

    public static function enabled(): bool
    {
        return setting('bale_enabled', '0') === '1' && self::configured();
    }

    public static function base(): string { return rtrim(trim((string)setting('bale_api_url', '')), '/'); }
    private static function token(): string { return trim((string)setting('bale_api_token', '')); }
    public static function delayMin(): int { return max(0, min(1440, (int)setting('bale_delay_min', 60))); }

    /** A course is announced when it is for everyone (no audience set) or its audience includes the merchants group (or one of its sub-groups) */
    public static function isMerchantCourse(int $courseId): bool
    {
        $groups = CourseAudience::groupIds($courseId);
        if (!$groups) return true;
        foreach ($groups as $gid) {
            $ids = array_merge([$gid], CourseAudience::ancestors($gid));
            if (DB::value("SELECT 1 FROM `groups` WHERE id IN (" . DB::in($ids) . ") AND segment = 'merchant' AND is_system = 1 AND parent_id IS NULL", $ids)) return true;
        }
        return false;
    }

    /** Items that are due or will be due soon and have no message yet: [['ref','type','id','title','due_at','row'], …] */
    public static function pending(bool $onlyDue = true, int $limit = 50): array
    {
        $since = (string)setting('bale_since', '');
        if ($since === '') return [];
        $cut = date('Y-m-d H:i:s', time() - self::delayMin() * 60);
        $out = [];
        if (setting('bale_events', '1') === '1') {
            $types = "'" . implode("','", self::EVENT_TYPES) . "'";
            foreach (DB::all("SELECT e.* FROM events e WHERE e.type IN ($types) AND " . EventService::liveSql() . " AND e.created_at >= ?" . ($onlyDue ? ' AND e.created_at <= ?' : '') . "
                                AND NOT EXISTS (SELECT 1 FROM bale_messages b WHERE b.ref = CONCAT('event-', e.id)) ORDER BY e.created_at LIMIT $limit", $onlyDue ? [$since, $cut] : [$since]) as $e)
                $out[] = ['ref' => 'event-' . $e['id'], 'type' => 'event', 'id' => (int)$e['id'], 'title' => EventService::TYPES[$e['type']]['label'] . ': ' . $e['title'], 'due_at' => date('Y-m-d H:i:s', strtotime($e['created_at']) + self::delayMin() * 60), 'row' => $e];
        }
        if (setting('bale_courses', '1') === '1') {
            foreach (DB::all("SELECT c.* FROM courses c WHERE c.deleted_at IS NULL AND c.status = 'published' AND COALESCE(c.published_at, c.created_at) >= ?" . ($onlyDue ? ' AND COALESCE(c.published_at, c.created_at) <= ?' : '') . "
                                AND NOT EXISTS (SELECT 1 FROM bale_messages b WHERE b.ref = CONCAT('course-', c.id)) ORDER BY COALESCE(c.published_at, c.created_at) LIMIT $limit", $onlyDue ? [$since, $cut] : [$since]) as $c) {
                if (!self::isMerchantCourse((int)$c['id'])) continue;
                $out[] = ['ref' => 'course-' . $c['id'], 'type' => 'course', 'id' => (int)$c['id'], 'title' => 'دوره: ' . $c['title'], 'due_at' => date('Y-m-d H:i:s', strtotime((string)($c['published_at'] ?: $c['created_at'])) + self::delayMin() * 60), 'row' => $c];
            }
        }
        return $out;
    }

    /** Cron step: send what is due, retry failed ones (max 3 attempts) */
    public static function process(): array
    {
        if (!self::enabled()) return ['off' => true];
        $sent = 0; $failed = 0;
        foreach (self::pending(true, 20) as $it) {
            $text = $it['type'] === 'event' ? self::eventText($it['row']) : self::courseText($it['row']);
            $link = url($it['type'] === 'event' ? '/learn/event/' . $it['id'] : '/learn/course/' . $it['id']);
            $id = DB::insert('bale_messages', ['ref' => $it['ref'], 'ref_type' => $it['type'], 'ref_id' => $it['id'], 'title' => mb_substr($it['title'], 0, 250), 'segment' => 'merchant', 'text' => $text, 'status' => 'sending', 'created_at' => now()]);
            self::deliver($id, $link) ? $sent++ : $failed++;
        }
        foreach (DB::all("SELECT * FROM bale_messages WHERE status = 'failed' AND attempts < 3 ORDER BY id LIMIT 10") as $m) {
            self::deliver((int)$m['id'], url($m['ref_type'] === 'event' ? '/learn/event/' . $m['ref_id'] : '/learn/course/' . $m['ref_id'])) ? $sent++ : $failed++;
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Send one stored message through the «my» API */
    public static function deliver(int $id, ?string $link = null): bool
    {
        $m = DB::one('SELECT * FROM bale_messages WHERE id = ?', [$id]);
        if (!$m) return false;
        $body = ['ref' => $m['ref'], 'segment' => $m['segment'], 'text' => $m['text']];
        if ($link) $body['button'] = ['text' => $m['ref_type'] === 'course' ? 'مشاهده دوره' : 'مشاهده و ثبت‌نام', 'url' => $link];
        $r = self::request('POST', '/api/edu/bale/broadcast', $body);
        $ok = !empty($r['json']['ok']);
        DB::update('bale_messages', [
            'status' => $ok ? 'sent' : 'failed', 'attempts' => (int)$m['attempts'] + 1,
            'recipients' => $ok ? (int)($r['json']['recipients'] ?? 0) : $m['recipients'],
            'error' => $ok ? null : mb_substr(self::errorText($r, '/api/edu/bale/broadcast'), 0, 500),
            'sent_at' => $ok ? now() : $m['sent_at'],
        ], 'id = ?', [$id]);
        if (!$ok) Logger::warning('bale broadcast ' . $m['ref'] . ' failed: ' . ($r['error'] ?: $r['status']));
        return $ok;
    }

    /** Connection test: number of connected merchants */
    public static function recipients(string $segment = 'merchant'): array
    {
        $r = self::request('GET', '/api/edu/bale/recipients?segment=' . urlencode($segment));
        return !empty($r['json']['ok']) ? ['ok' => true, 'count' => (int)($r['json']['count'] ?? 0)] : ['ok' => false, 'error' => self::errorText($r, '/api/edu/bale/recipients')];
    }

    /** Human-readable reason a call to «my» failed (see docs/BALE-API.md) */
    private static function errorText(array $r, string $path): string
    {
        if ($r['error'] !== '') return $r['error'];
        $msg = isset($r['json']['error']) ? ' — ' . $r['json']['error'] : '';
        return match (true) {
            $r['status'] === 404 => 'HTTP 404: مسیر ' . $path . ' روی ' . self::base() . ' پیدا نشد. این سرویس باید در سامانه my پیاده‌سازی شود (مشخصات: docs/BALE-API.md) یا آدرس پایه API اشتباه است.' . $msg,
            in_array($r['status'], [401, 403], true) => 'HTTP ' . $r['status'] . ': توکن API توسط my پذیرفته نشد.' . $msg,
            $r['status'] >= 200 && $r['status'] < 300 && $r['json'] === null => 'HTTP ' . $r['status'] . ': پاسخ my در قالب JSON نیست.',
            default => 'HTTP ' . $r['status'] . $msg,
        };
    }

    /** @return array{status:int,json:?array,error:string} */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        if (!self::configured()) return ['status' => 0, 'json' => null, 'error' => 'آدرس یا توکن API تنظیم نشده است'];
        $ch = curl_init(self::base() . $path);
        $headers = ['Authorization: Bearer ' . self::token(), 'Accept: application/json'];
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
        $raw = curl_exec($ch);
        $err = $raw === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'error' => $err];
    }

    public static function eventText(array $e): string
    {
        $ts = strtotime((string)$e['starts_at']);
        $t = '📢 ' . EventService::TYPES[$e['type']]['label'] . ' جدید: ' . $e['title'] . "\n\n"
           . '🗓 ' . jdate($e['starts_at'], 'l j F') . ' — ساعت ' . fa(date('H:i', $ts)) . "\n"
           . ($e['host_name'] ? '🎤 ارائه: ' . $e['host_name'] . "\n" : '')
           . ($e['summary'] ? "\n" . trim((string)$e['summary']) . "\n" : '');
        return $t . "\n🔗 جزئیات و ثبت‌نام:\n" . url('/learn/event/' . $e['id']);
    }

    public static function courseText(array $c): string
    {
        return '🎓 دوره آموزشی جدید: ' . $c['title'] . "\n"
           . ($c['summary'] ? "\n" . trim((string)$c['summary']) . "\n" : '')
           . "\n🔗 مشاهده و شروع دوره:\n" . url('/learn/course/' . $c['id']);
    }
}
