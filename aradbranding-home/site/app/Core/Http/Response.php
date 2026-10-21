<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Standard API envelope: {"ok": bool, "data": {}, "message": ""} */
    public static function json(mixed $data = [], string $message = '', int $status = 200, ?bool $ok = null): self
    {
        $payload = [
            'ok' => $ok ?? ($status < 400),
            'data' => $data === [] ? new \stdClass() : $data,
            'message' => $message,
        ];
        return new self(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Weak ETag + 304 short-circuit for cacheable GET responses. */
    public function withEtag(Request $request, ?string $etag = null): self
    {
        if (!$request->isReadOnly() || $this->status !== 200) {
            return $this;
        }
        $etag ??= 'W/"' . substr(hash('xxh128', $this->body), 0, 20) . '"';
        $this->headers['ETag'] = $etag;
        $ifNoneMatch = $request->header('if-none-match');
        if ($ifNoneMatch !== null && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
            $this->status = 304;
            $this->body = '';
        }
        return $this;
    }

    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($withBody && $this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
