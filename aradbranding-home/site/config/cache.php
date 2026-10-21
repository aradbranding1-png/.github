<?php

use App\Core\Env;

return [
    'driver' => strtolower((string) Env::get('CACHE_DRIVER', 'file')) === 'redis' ? 'redis' : 'file', // file | redis
    'prefix' => Env::get('CACHE_PREFIX', 'sadt'),
    'redis_db' => (int) Env::get('REDIS_CACHE_DB', 1),
];
