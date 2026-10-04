<?php

declare(strict_types=1);

namespace App\Core\Errors;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Log\Logger;
use App\Core\Support\Ulid;
use ErrorException;
use Throwable;

/** Production: generic page + tracking code. Debug: full details. Everything is logged. */
final class ErrorHandler
{
    private static ?Logger $logger = null;
    private static bool $debug = false;

    public static function register(Logger $logger, bool $debug): void
    {
        self::$logger = $logger;
        self::$debug = $debug;
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            if (PHP_SAPI === 'cli') {
                $code = self::report($e, null);
                fwrite(STDERR, "[{$code}] " . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            self::renderException($e, null)->send();
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $e = new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']);
                $code = self::report($e, null);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                    echo 'Internal error. Tracking code: ' . $code;
                }
            }
        });
    }

    public static function renderHttp(HttpException $e, Request $request): Response
    {
        $messages = [
            400 => 'درخواست نامعتبر است.',
            401 => 'لطفاً وارد حساب خود شوید.',
            403 => 'اجازه دسترسی ندارید.',
            404 => 'صفحه مورد نظر پیدا نشد.',
            405 => 'این روش درخواست مجاز نیست.',
            419 => 'نشست شما منقضی شده است. صفحه را دوباره بارگذاری کنید.',
            422 => 'اطلاعات واردشده معتبر نیست.',
            429 => 'تعداد درخواست‌ها زیاد است. کمی بعد دوباره تلاش کنید.',
        ];
        $message = t($messages[$e->status] ?? ($e->getMessage() !== '' ? $e->getMessage() : 'خطا'));

        $response = $request->wantsJson()
            ? Response::json([], $message, $e->status)
            : Response::html(self::page((string) $e->status, $message, (string) $request->attribute('csp_nonce')), $e->status);
        foreach ($e->headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        return $response;
    }

    public static function renderException(Throwable $e, ?Request $request): Response
    {
        if ($e instanceof HttpException && $request !== null) {
            return self::renderHttp($e, $request);
        }
        $code = self::report($e, $request);
        $message = t('خطایی در سامانه رخ داد. کد پیگیری: :code', ['code' => $code]);
        $detail = self::$debug
            ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
            : '';

        if ($request?->wantsJson()) {
            return Response::json(self::$debug ? ['debug' => $detail, 'code' => $code] : ['code' => $code], $message, 500)
                ->withHeader('Cache-Control', 'no-store');
        }
        return Response::html(
            self::page('500', $message, (string) $request?->attribute('csp_nonce'), $detail),
            500
        )->withHeader('Cache-Control', 'no-store');
    }

    private static function report(Throwable $e, ?Request $request): string
    {
        $code = substr(Ulid::generate(), -10);
        self::$logger?->error($e->getMessage(), [
            'tracking_code' => $code,
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'route' => $request ? $request->method . ' ' . $request->path : (PHP_SAPI === 'cli' ? 'cli' : null),
            'user_id' => $request?->attribute('user_id'),
            'ip' => $request?->ip(),
            'trace' => substr($e->getTraceAsString(), 0, 8000),
        ]);
        return $code;
    }

    private static function page(string $status, string $message, string $nonce, string $detail = ''): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pre = $detail !== '' ? '<pre dir="ltr">' . $esc($detail) . '</pre>' : '';
        return '<!doctype html><html ' . \App\Core\I18n\I18n::langAttrs() . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $esc($status) . '</title>'
            . '<style nonce="' . $esc($nonce) . '">body{font-family:system-ui,sans-serif;background:#0b1530;color:#eef1f7;'
            . 'margin:0;min-height:100vh;display:grid;place-items:center;text-align:center;padding:24px}'
            . 'h1{color:#c9a45c;font-size:56px;margin:0}a{color:#e0b3a3}pre{text-align:left;white-space:pre-wrap;'
            . 'background:#060d1f;padding:16px;border-radius:8px;max-width:960px;overflow:auto;font-size:12px}</style></head>'
            . '<body><main><h1>' . $esc($status) . '</h1><p>' . $esc($message) . '</p><p><a href="/">' . $esc(t('بازگشت به صفحه اصلی')) . '</a></p>'
            . $pre . '</main></body></html>';
    }
}
