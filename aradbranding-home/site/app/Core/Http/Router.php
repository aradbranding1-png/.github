<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Router with O(1) lookup for static paths and one combined regex per method
 * for dynamic paths. Handlers: [ControllerClass::class, 'method'] or Closure.
 * Middleware entries are alias strings, e.g. 'session', 'csrf', 'throttle:login'.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: mixed, middleware: list<string>}>> */
    private array $static = [];
    /** @var array<string, list<array{regex: string, names: list<string>, handler: mixed, middleware: list<string>}>> */
    private array $dynamic = [];
    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groupStack = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add(['GET', 'HEAD'], $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add(['POST'], $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add(['PUT'], $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add(['PATCH'], $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add(['DELETE'], $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $routes($this);
        array_pop($this->groupStack);
    }

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     */
    public function add(array $methods, string $path, mixed $handler, array $middleware = []): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $groupMiddleware = [...$groupMiddleware, ...$group['middleware']];
        }
        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $route = ['handler' => $handler, 'middleware' => [...$groupMiddleware, ...$middleware]];

        foreach ($methods as $method) {
            if (!str_contains($full, '{')) {
                $this->static[$method][$full] = $route;
                continue;
            }
            $names = [];
            $regex = preg_replace_callback(
                '/\{([a-z_][a-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/i',
                static function (array $m) use (&$names): string {
                    $names[] = $m[1];
                    return '(' . ($m[2] ?? '[^/]+') . ')';
                },
                $full
            );
            $this->dynamic[$method][] = ['regex' => '#^' . $regex . '$#u', 'names' => $names] + $route;
        }
    }

    /**
     * @return array{handler: mixed, middleware: list<string>, params: array<string, string>}
     * @throws HttpException 404 or 405
     */
    public function match(string $method, string $path): array
    {
        if (isset($this->static[$method][$path])) {
            return $this->static[$method][$path] + ['params' => []];
        }
        foreach ($this->dynamic[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                $params = [];
                foreach ($route['names'] as $i => $name) {
                    $params[$name] = $m[$i + 1];
                }
                return ['handler' => $route['handler'], 'middleware' => $route['middleware'], 'params' => $params];
            }
        }

        $allowed = [];
        foreach ($this->static as $m => $routes) {
            if (isset($routes[$path])) {
                $allowed[] = $m;
            }
        }
        foreach ($this->dynamic as $m => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path)) {
                    $allowed[] = $m;
                    break;
                }
            }
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Not Found');
    }
}
