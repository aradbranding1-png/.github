<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router with built-in authentication, permission and CSRF enforcement.
 * Every route declares its access requirement; defaults are secure (auth required).
 *   opts: auth(bool, default true) | guest(bool) | perm(string|array any-of) | root(bool) | csrf(bool, default true for POST)
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array|\Closure $handler, array $opts = []): void { $this->add(['GET', 'HEAD'], $path, $handler, $opts); }
    public function post(string $path, array|\Closure $handler, array $opts = []): void { $this->add(['POST'], $path, $handler, $opts); }
    public function any(string $path, array|\Closure $handler, array $opts = []): void { $this->add(['GET', 'HEAD', 'POST'], $path, $handler, $opts); }

    public function add(array $methods, string $path, array|\Closure $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/') . '$#u';
        $this->routes[] = compact('methods', 'path', 'regex', 'handler', 'opts');
    }

    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) continue;
            if (!in_array($method, $r['methods'], true)) { $allowed = true; continue; }
            Request::$params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->guard($r['opts'], $method);
            $h = $r['handler'];
            if ($h instanceof \Closure) { $out = $h(...array_values(Request::$params)); }
            else {
                [$class, $action] = $h;
                $ctrl = new $class();
                $out = $ctrl->$action(...array_map(fn($v) => ctype_digit($v) ? (int)$v : $v, array_values(Request::$params)));
            }
            if (is_string($out)) echo $out;
            return;
        }
        throw new HttpException($allowed ? 405 : 404);
    }

    private function guard(array $o, string $method): void
    {
        $api = !empty($o['api']);
        if ($method === 'POST' && ($o['csrf'] ?? !$api)) Csrf::verify();
        if (!empty($o['guest'])) {
            if (Auth::check()) redirect('/');
            return;
        }
        if (($o['auth'] ?? true) && !$api) {
            if (!Auth::check()) {
                if (Request::isAjax()) throw new HttpException(401);
                $_SESSION['_intended'] = Request::path();
                redirect('/login');
            }
            if (!empty($o['root']) && !Gate::isRoot()) {
                Audit::log('access.denied', 'route', null, 'denied', ['path' => Request::path(), 'need' => 'root']);
                throw new HttpException(403);
            }
            if (!empty($o['perm'])) {
                $perms = (array)$o['perm'];
                $ok = false;
                foreach ($perms as $p) if (Gate::allows($p)) { $ok = true; break; }
                if (!$ok) {
                    Audit::log('access.denied', 'route', null, 'denied', ['path' => Request::path(), 'need' => $perms]);
                    throw new HttpException(403);
                }
            }
        }
    }
}
