<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\HttpException;
use App\Core\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $r;

    protected function setUp(): void
    {
        $this->r = new Router();
        $this->r->get('/', 'home');
        $this->r->get('/p/{handle}', 'page', ['session:optional']);
        $this->r->get('/p/{handle}/{lang:[a-z]{2}}', 'page-lang');
        $this->r->group('/api/v1', ['throttle:api'], function (Router $r): void {
            $r->post('/letters', 'send', ['csrf']);
        });
    }

    public function testStaticRoute(): void
    {
        self::assertSame('home', $this->r->match('GET', '/')['handler']);
    }

    public function testParamsAndConstraints(): void
    {
        $m = $this->r->match('GET', '/p/arad/fa');
        self::assertSame('page-lang', $m['handler']);
        self::assertSame(['handle' => 'arad', 'lang' => 'fa'], $m['params']);
        self::assertSame('page', $this->r->match('GET', '/p/arad')['handler']);
    }

    public function testGroupPrefixAndMiddlewareOrder(): void
    {
        $m = $this->r->match('POST', '/api/v1/letters');
        self::assertSame(['throttle:api', 'csrf'], $m['middleware']);
    }

    public function testMethodNotAllowed(): void
    {
        try {
            $this->r->match('GET', '/api/v1/letters');
            self::fail('Expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('POST', $e->headers['Allow']);
        }
    }

    public function testNotFound(): void
    {
        $this->expectException(HttpException::class);
        $this->r->match('GET', '/p/arad/english');
    }
}
