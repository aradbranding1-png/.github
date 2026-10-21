<?php

use App\Core\Env;

return [
    'name' => 'سامانه توسعه تجارت',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'https://aradbranding.app'),
    'asset_url' => Env::get('ASSET_URL', ''),
    'media_url' => Env::get('MEDIA_URL', ''),
    'default_locale' => 'fa',
    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'server_timing' => (bool) Env::get('SERVER_TIMING', true),
    // Max queries per request before a warning (and a hard error in development).
    'query_budget' => (int) Env::get('QUERY_BUDGET', 8),
];
