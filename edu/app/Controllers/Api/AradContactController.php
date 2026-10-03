<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Services\AradContact;

/**
 * Arad Contact → edu integration endpoints. Responses: {"success":true,...} or {"success":false,"message":"..."}.
 * Every call (including rejected ones) is written to arad_contact_logs.
 */
final class AradContactController
{
    public function services(): never
    {
        $this->handle('services', ['GET', 'HEAD'], fn(?array $body): array => [AradContact::servicesResponse(), []]);
    }

    public function provision(): never
    {
        $this->handle('provision', ['POST'], function (?array $body): array {
            if ($body === null) throw new HttpException(422, 'بدنه درخواست باید JSON معتبر باشد (Content-Type: application/json).');
            return AradContact::provision($body);
        });
    }

    /** @param callable(?array): array{0: array, 1: array} $fn */
    private function handle(string $endpoint, array $methods, callable $fn): never
    {
        $t0 = microtime(true);
        $raw = (string)file_get_contents('php://input');
        $body = null;
        if (trim($raw) !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) $body = $j;
        }
        $meta = [];
        try {
            if (!in_array(Request::method(), $methods, true)) throw new HttpException(405, 'روش درخواست مجاز نیست؛ از ' . implode('/', array_diff($methods, ['HEAD'])) . ' استفاده کنید.');
            AradContact::authenticate();
            [$resp, $meta] = $fn($body);
            $status = 200;
        } catch (HttpException $e) {
            $status = $e->status;
            $resp = ['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : ErrorHandler::defaultMessage($status)];
        } catch (\Throwable $e) {
            $status = 500;
            $ref = Logger::error('arad-contact ' . $endpoint . ': ' . get_class($e) . ': ' . $e->getMessage(), ['file' => ErrorHandler::rel($e->getFile()), 'line' => $e->getLine()]);
            $resp = ['success' => false, 'message' => 'خطای داخلی در سامانه آموزش رخ داد و هیچ خدمتی شارژ نشد. کد پیگیری: ' . $ref];
        }
        AradContact::log($endpoint, $status, $body, $raw, $resp, $meta, (int)round((microtime(true) - $t0) * 1000));
        header('Cache-Control: no-store');
        json_out($resp, $status);
    }
}
