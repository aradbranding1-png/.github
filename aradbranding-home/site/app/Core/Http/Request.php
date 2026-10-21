<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    /** @var array<string, mixed> Route params, auth user, CSP nonce, request id... */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers lower-cased names
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $body,
        private array $headers,
        private array $cookies,
        private array $server,
        private array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = '/' . trim(rawurldecode((string) parse_url($uri, PHP_URL_PATH)), '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            if (isset($_SERVER[$key])) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $_SERVER[$key];
            }
        }

        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 1_048_576) ?: '';
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self($method, $path, $_GET, $body, $headers, $_COOKIE, $_SERVER, $_FILES);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body + $this->query;
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /**
     * Files from <input type="file" name="x[]" multiple>, normalised to a list of single-file arrays.
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function fileList(string $key, int $max = 10): array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || !is_array($f['error'])) {
            return [];
        }
        $out = [];
        foreach ($f['error'] as $i => $error) {
            if ($error === UPLOAD_ERR_NO_FILE || count($out) >= $max) {
                continue;
            }
            $out[] = ['name' => (string) $f['name'][$i], 'type' => (string) $f['type'][$i],
                'tmp_name' => (string) $f['tmp_name'][$i], 'error' => (int) $error, 'size' => (int) $f['size'][$i]];
        }
        return $out;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        return $this->fromTrustedProxy() && strtolower($this->header('x-forwarded-proto', '')) === 'https';
    }

    /**
     * Client IP. Forwarded headers are honoured only when REMOTE_ADDR is a trusted proxy
     * (TRUSTED_PROXIES: comma-separated IPs and/or CIDR ranges of the CDN / load balancer).
     * X-Forwarded-For is read right-to-left, skipping trusted hops, because the left-most
     * entries are whatever the client chose to send.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->fromTrustedProxy()) {
            return $remote;
        }
        // Optional: a header the CDN always overwrites (Cloudflare: CF-Connecting-IP, nginx LB: X-Real-IP).
        // Only set it if your proxy really overwrites it, otherwise clients could spoof it.
        $realIpHeader = strtolower(trim((string) \App\Core\Env::get('REAL_IP_HEADER', '')));
        if ($realIpHeader !== '') {
            $v = trim((string) $this->header($realIpHeader, ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
        }
        $forwarded = (string) $this->header('x-forwarded-for', '');
        if ($forwarded !== '') {
            $trusted = self::trustedProxies();
            $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    break;
                }
                if (!\App\Core\Support\IpMatcher::matches($hop, $trusted)) {
                    return $hop;
                }
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent', ''), 0, 512);
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($this->header('accept', ''), 'application/json');
    }

    public function isReadOnly(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->attributes['params'][$key] ?? $default;
    }

    private function fromTrustedProxy(): bool
    {
        $trusted = self::trustedProxies();
        return $trusted !== [] && \App\Core\Support\IpMatcher::matches((string) ($this->server['REMOTE_ADDR'] ?? ''), $trusted);
    }

    /** @return list<string> */
    private static function trustedProxies(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) \App\Core\Env::get('TRUSTED_PROXIES', '')))));
    }
}
