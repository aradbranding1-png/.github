<?php

declare(strict_types=1);

/*
 * Bootstrap: autoloader, environment, config, service container.
 * No Composer dependency is required at runtime; vendor/ is loaded only if present.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Core/helpers.php';

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

use App\Core\Auth\Auth;
use App\Core\Auth\Gate;
use App\Core\Cache\Cache;
use App\Core\Cache\FileStore;
use App\Core\Config;
use App\Core\Container;
use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Errors\ErrorHandler;
use App\Core\Events\Outbox;
use App\Core\Log\Logger;
use App\Core\Queue\DatabaseQueue;
use App\Core\Security\Audit;
use App\Core\Security\RateLimiter;
use App\Core\Storage\ImageUploader;
use App\Core\View\View;

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

Env::load(BASE_PATH . '/.env');
$config = Config::load(BASE_PATH . '/config');

$container = new Container();
Container::setInstance($container);
$container->set(Config::class, $config);

$container->singleton(Logger::class, static fn () => new Logger(
    BASE_PATH . '/storage/logs',
    (string) $config->get('app.log_level', 'info')
));

ErrorHandler::register(
    $container->get(Logger::class),
    (bool) $config->get('app.debug', false)
);

$container->singleton(Connection::class, static fn (Container $c) => new Connection(
    $config->get('database'),
    $c->get(Logger::class)
));

$container->singleton(Cache::class, static fn () => new Cache(
    new FileStore(BASE_PATH . '/storage/cache'),
    (string) $config->get('cache.prefix', 'sadt')
));

$container->singleton(RateLimiter::class, static fn (Container $c) => new RateLimiter(
    $c->get(Cache::class)->store(),
    $config->get('security.rate_limits', [])
));

$container->singleton(DatabaseQueue::class, static fn (Container $c) => new DatabaseQueue(
    $c->get(Connection::class)
));

$container->singleton(Outbox::class, static fn (Container $c) => new Outbox(
    $c->get(Connection::class)
));

$container->singleton(Auth::class, static fn (Container $c) => new Auth($c->get(Connection::class)));
$container->singleton(Gate::class, static fn (Container $c) => new Gate($c->get(Connection::class)));
$container->singleton(Audit::class, static fn (Container $c) => new Audit($c->get(Connection::class)));
$container->singleton(View::class, static fn () => new View(BASE_PATH . '/app/Views'));
$container->singleton(ImageUploader::class, static fn () => new ImageUploader(
    BASE_PATH . '/public_html/media',
    upload_max_kb() * 1024
));

(require __DIR__ . '/services.php')($container);

return $container;
