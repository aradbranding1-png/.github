<?php
/**
 * کشِ مشترک و کارهای پس از پاسخ — برای سرعتِ صفحه‌ها با هزاران کارشناس و میلیون‌ها مشتری.
 *
 *   app_cache_remember($key, $ttl, $fn)
 *     نتیجه‌ی شمارش‌های سنگین (داشبورد، تعدادِ فهرست‌ها) برای همه‌ی کاربرانِ هم‌دسترسی یک‌بار حساب می‌شود
 *     و در storage/cache نگه داشته می‌شود. بعد از انقضا، مقدارِ قبلی فوراً نشان داده می‌شود و مقدارِ تازه
 *     «بعد از فرستادنِ صفحه» حساب می‌شود (هیچ کاربری منتظرِ شمارش نمی‌ماند؛ فقط بارِ اول).
 *
 *   app_defer($fn)
 *     کاری که لازم نیست کاربر منتظرش بماند (به‌روزرسانیِ کش، کارهای دوره‌ای) بعد از رسیدنِ صفحه به کاربر اجرا می‌شود.
 */

function app_cache_dir(): string
{
    $dir = __DIR__ . '/../storage/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** اجرای کار بعد از فرستادنِ کاملِ پاسخ به کاربر */
function app_defer(callable $fn): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = new ArrayObject();
        $q = $queue;
        register_shutdown_function(static function () use ($q) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            ignore_user_abort(true);
            @set_time_limit(300);
            // قفلِ سشن آزاد شود تا درخواستِ بعدیِ همین کاربر منتظر نماند
            if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            foreach ($q as $job) {
                try {
                    $job();
                } catch (Throwable $e) {
                    error_log('app_defer: ' . $e->getMessage());
                }
            }
        });
    }
    $queue->append($fn);
}

function app_cache_file(string $key): string
{
    return app_cache_dir() . '/' . preg_replace('/[^a-z0-9_]/i', '_', substr($key, 0, 40)) . '_' . md5($key) . '.json';
}

/** @return array{0: mixed, 1: int}|null  [مقدار, زمانِ محاسبه] */
function app_cache_read(string $file): ?array
{
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $d = json_decode($raw, true);
    if (!is_array($d) || !array_key_exists('v', $d)) return null;
    return [$d['v'], (int) ($d['t'] ?? 0)];
}

function app_cache_write(string $file, $value): void
{
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode(['t' => time(), 'v' => $value], JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/**
 * مقدارِ کش‌شده (فقط داده‌ی JSON‌پذیر: عدد، رشته، آرایه).
 * $ttl ثانیه تازه است؛ تا $staleMax ثانیه مقدارِ کهنه نشان داده می‌شود و پشتِ صحنه تازه می‌شود.
 */
function app_cache_remember(string $key, int $ttl, callable $fn, int $staleMax = 3600)
{
    $file = app_cache_file($key);
    $hit = app_cache_read($file);
    $age = $hit ? time() - $hit[1] : PHP_INT_MAX;
    if ($hit && $age < $ttl) return $hit[0];

    $refresh = static function () use ($file, $fn) {
        $lock = @fopen($file . '.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
        try {
            $v = $fn();
            app_cache_write($file, $v);
            return $v;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    };

    if ($hit && $age < $staleMax) {
        // مقدارِ قبلی همین حالا؛ مقدارِ تازه بعد از رسیدنِ صفحه به کاربر (فقط یک درخواست حساب می‌کند)
        app_defer($refresh);
        return $hit[0];
    }

    // بارِ اول: یک نفر حساب می‌کند و بقیه منتظرِ همان نتیجه می‌مانند (نه این‌که همه با هم حساب کنند)
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $again = app_cache_read($file);
        if ($again && time() - $again[1] < $ttl) return $again[0];
        $v = $fn();
        app_cache_write($file, $v);
        return $v;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** پاک‌کردنِ کشِ یک پیشوند (مثلاً بعد از تغییرِ گروهیِ مشتری‌ها) */
function app_cache_forget_prefix(string $prefix): void
{
    $p = preg_replace('/[^a-z0-9_]/i', '_', $prefix);
    foreach (glob(app_cache_dir() . '/' . $p . '*.json') ?: [] as $f) @unlink($f);
}
