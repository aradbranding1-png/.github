<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Container;
use App\Core\Errors\ErrorHandler;
use App\Core\Http\Middleware as MiddlewareContract;
use App\Core\Http\Middleware\Authenticate;
use App\Core\Http\Middleware\Authorize;
use App\Core\Http\Middleware\MaintenanceMode;
use App\Core\Http\Middleware\RedirectIfAuthenticated;
use App\Core\Http\Middleware\RateLimit;
use App\Core\Http\Middleware\RequestTiming;
use App\Core\Http\Middleware\SecurityHeaders;
use App\Core\Http\Middleware\StartSession;
use App\Core\Http\Middleware\VerifyCsrf;
use App\Core\Support\Ulid;
use Closure;
use RuntimeException;
use Throwable;

final class Application
{
    /** Applied to every request, including 404s and errors. */
    private const GLOBAL_MIDDLEWARE = ['timing', 'headers', 'maintenance'];

    /** @var array<string, Closure(Container, ?string): MiddlewareContract> */
    private array $aliases;

    public function __construct(private Container $container, private Router $router)
    {
        $this->aliases = [
            'timing' => static fn (Container $c) => new RequestTiming($c),
            'headers' => static fn (Container $c) => new SecurityHeaders($c),
            'maintenance' => static fn (Container $c) => new MaintenanceMode(),
            'session' => static fn (Container $c, ?string $p) => new StartSession($c, $p === 'optional'),
            'csrf' => static fn (Container $c) => new VerifyCsrf(),
            'throttle' => static fn (Container $c, ?string $p) => new RateLimit($c, $p ?? 'default'),
            'auth' => static fn (Container $c) => new Authenticate($c),
            'guest' => static fn (Container $c) => new RedirectIfAuthenticated($c),
            'can' => static fn (Container $c, ?string $p) => new Authorize($c, (string) $p),
        ];
    }

    public function alias(string $name, Closure $factory): void
    {
        $this->aliases[$name] = $factory;
    }

    public function handle(Request $request): Response
    {
        $requestId = Ulid::generate();
        $request->withAttribute('request_id', $requestId);
        \App\Core\Log\Logger::setRequestId($requestId);
        $request->withAttribute('csp_nonce', base64_encode(random_bytes(16)));
        \App\Core\I18n\I18n::boot($request);
        register_shutdown_function([\App\Core\I18n\I18n::class, 'persistMisses']);

        $routed = function (Request $request): Response {
            try {
                $method = $request->method === 'HEAD' ? 'GET' : $request->method;
                $route = $this->router->match($method, $request->path);
                $request->withAttribute('params', $route['params']);
                $handler = $route['handler'];
                $core = fn (Request $r): Response => $this->dispatch($handler, $r);
                return $this->pipeline($route['middleware'], $core)($request);
            } catch (HttpException $e) {
                return ErrorHandler::renderHttp($e, $request);
            } catch (Throwable $e) {
                return ErrorHandler::renderException($e, $request);
            }
        };

        try {
            return $this->pipeline(self::GLOBAL_MIDDLEWARE, $routed)($request);
        } catch (Throwable $e) {
            return ErrorHandler::renderException($e, $request);
        }
    }

    /**
     * @param list<string> $aliases
     * @param callable(Request): Response $core
     * @return callable(Request): Response
     */
    private function pipeline(array $aliases, callable $core): callable
    {
        $next = $core;
        foreach (array_reverse($aliases) as $alias) {
            $middleware = $this->resolve($alias);
            $next = static fn (Request $r): Response => $middleware->handle($r, $next);
        }
        return $next;
    }

    private function resolve(string $alias): MiddlewareContract
    {
        [$name, $param] = array_pad(explode(':', $alias, 2), 2, null);
        if (!isset($this->aliases[$name])) {
            throw new RuntimeException("Unknown middleware alias: {$name}");
        }
        return ($this->aliases[$name])($this->container, $param);
    }

    private function dispatch(mixed $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request, $this->container);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $result = $this->container->make($class)->{$method}($request);
        } else {
            throw new RuntimeException('Invalid route handler');
        }

        return match (true) {
            $result instanceof Response => $result,
            is_string($result) => Response::html($result),
            is_array($result) => Response::json($result),
            default => throw new RuntimeException('Handler must return Response, string or array'),
        };
    }
}
