<?php

declare(strict_types=1);

use App\Core\Http\Router;

return static function (Router $r): void {
    $r->group('/api/v1', ['throttle:api'], static function (Router $r): void {
        $r->get('/ping', static fn () => ['pong' => true, 'time' => gmdate('c')]);

        // Partner API (Arad Contact) — bearer key from Admin → «اتصال API». docs/API-arad-contact.md
        $r->post('/wallet/charge', [\App\Modules\Integrations\ApiController::class, 'charge']);
        $r->get('/users/lookup', [\App\Modules\Integrations\ApiController::class, 'lookup']);
        $r->post('/users/credentials', [\App\Modules\Integrations\ApiController::class, 'credentials']);
    });
};
