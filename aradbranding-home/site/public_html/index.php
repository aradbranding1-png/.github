<?php

declare(strict_types=1);

use App\Core\Http\Application;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;

$container = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::fromGlobals();

if (!is_file(BASE_PATH . '/storage/installed.lock')
    && $request->path !== '/install'
    && $request->path !== '/health/live') {
    Response::redirect('/install')->send();
    exit;
}

$router = new Router();
(require BASE_PATH . '/routes/web.php')($router);
(require BASE_PATH . '/routes/api.php')($router);

$response = (new Application($container, $router))->handle($request);
$response->send($request->method !== 'HEAD');

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
