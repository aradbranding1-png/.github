<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Converts all errors into logged exceptions. In production no stack trace,
 * server path, DB error or config is ever shown — only a reference code.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler(function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) return false;
            if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) {
                Logger::write('notice', $str, ['file' => self::rel($file), 'line' => $line]);
                return true;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
        register_shutdown_function(function (): void {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($e['message'], 0, $e['type'], $e['file'], $e['line']));
            }
        });
    }

    public static function rel(string $path): string
    {
        return str_replace(BASE_PATH, '', $path);
    }

    public static function handle(\Throwable $e): void
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        $ref = '';
        if ($status >= 500) {
            $ref = Logger::error(get_class($e) . ': ' . $e->getMessage(), [
                'file' => self::rel($e->getFile()), 'line' => $e->getLine(),
                'uri' => $_SERVER['REQUEST_URI'] ?? 'cli', 'user' => $_SESSION['uid'] ?? null,
                'trace' => array_slice(array_map(fn($t) => self::rel(($t['file'] ?? '?')) . ':' . ($t['line'] ?? '?'), $e->getTrace()), 0, 12),
            ]);
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[$status] " . $e->getMessage() . ' @ ' . self::rel($e->getFile()) . ':' . $e->getLine() . PHP_EOL);
            exit(1);
        }
        while (ob_get_level() > 0) @ob_end_clean();
        if (!headers_sent()) http_response_code($status);

        $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_starts_with(Request::path(), '/api/');
        $message = $e instanceof HttpException && $e->getMessage() !== '' ? $e->getMessage() : self::defaultMessage($status);
        if ($wantsJson) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'status' => $status, 'message' => $message, 'ref' => $ref ?: null], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $debug = debug_enabled() && $status >= 500 ? (get_class($e) . ': ' . $e->getMessage() . "\n" . self::rel($e->getFile()) . ':' . $e->getLine()) : '';
        try {
            echo View::render('errors/error', compact('status', 'message', 'ref', 'debug'), 'layouts/bare');
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><div style="font-family:tahoma;direction:rtl;text-align:center;padding:60px">'
                . htmlspecialchars($message) . ($ref ? '<br><small>کد پیگیری: ' . $ref . '</small>' : '') . '</div>';
        }
        exit;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'درخواست نامعتبر است.',
            401 => 'برای دسترسی به این بخش ابتدا وارد شوید.',
            403 => 'شما مجوز دسترسی به این بخش را ندارید.',
            404 => 'صفحه مورد نظر پیدا نشد.',
            405 => 'روش درخواست مجاز نیست.',
            419 => 'نشست شما منقضی شده است. صفحه را دوباره بارگذاری کنید.',
            429 => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.',
            503 => 'سامانه در حال به‌روزرسانی است. چند دقیقه دیگر مراجعه کنید.',
            default => 'خطای غیرمنتظره‌ای رخ داد. این خطا ثبت شد و بررسی می‌شود.',
        };
    }
}
