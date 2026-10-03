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

        // Official API with personal keys (Bearer ark_…) — «API و کلید دسترسی». docs/API-v1.md
        $api = \App\Modules\Integrations\PublicApiController::class;
        $r->get('/me', [$api, 'me']);
        $r->get('/wallet', [$api, 'wallet']);
        $r->get('/letters', [$api, 'letters']);
        $r->post('/letters', [$api, 'send']);
        $r->get('/letters/{uid:[0-9a-zA-Z]{26}}', [$api, 'thread']);
        $r->post('/letters/{uid:[0-9a-zA-Z]{26}}/reply', [$api, 'reply']);
        $r->get('/proposals/mine', [$api, 'myProposals']);
        $r->get('/proposals/feed', [$api, 'feed']);
        $r->get('/notifications', [$api, 'notifications']);
        $r->get('/traders', [$api, 'traders']);
        $r->get('/traders/{handle:[a-z0-9-]{3,32}}', [$api, 'trader']);
    });

    // Internal API for «آراد کانتکت» (partner key from Admin → «اتصال API»). docs/API-arad-contact-internal.md
    $r->group('/api/integrations/arad-contact', ['throttle:api'], static function (Router $r): void {
        $ac = \App\Modules\Integrations\AradContactController::class;
        $r->post('/users/lookup', [$ac, 'lookup']);
        $r->post('/users', [$ac, 'createUser']);
        $r->get('/stars/rate', [$ac, 'rate']);
        $r->post('/stars/credit', [$ac, 'credit']);
    });
};
