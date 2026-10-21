<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Cache\Cache;
use App\Core\Cache\RedisStore;
use App\Core\Session\RedisSessionHandler;
use PHPUnit\Framework\TestCase;

/** Runs only when a Redis server is reachable (REDIS_TEST_HOST / REDIS_TEST_PORT, default 127.0.0.1:6379). */
final class RedisStoreTest extends TestCase
{
    private \Redis $redis;
    private string $prefix;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis not installed');
        }
        $this->redis = new \Redis();
        try {
            $ok = @$this->redis->connect(getenv('REDIS_TEST_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_TEST_PORT') ?: 6379), 0.5);
        } catch (\Throwable) {
            $ok = false;
        }
        if (!$ok) {
            $this->markTestSkipped('no Redis server');
        }
        $this->redis->select(15);
        $this->prefix = 'test' . bin2hex(random_bytes(3)) . ':';
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            (new RedisStore($this->redis, $this->prefix))->flush();
        }
    }

    public function testPutGetForgetIncrementLockFlush(): void
    {
        $s = new RedisStore($this->redis, $this->prefix);
        $s->put('a', ['x' => 1], 60);
        $this->assertSame(['x' => 1], $s->get('a'));
        $s->put('f', false, 60);
        $this->assertFalse($s->get('f'));
        $s->forget('a');
        $this->assertNull($s->get('a'));

        $this->assertSame(1, $s->increment('n', 1, 60));
        $this->assertSame(3, $s->increment('n', 2, 60));
        $this->assertSame(3, $s->get('n'));
        $this->assertGreaterThan(0, $this->redis->ttl($this->prefix . 'n'));

        $lock = $s->lock('job', 5);
        $this->assertNotNull($lock);
        $this->assertNull($s->lock('job', 5));
        $lock->release();
        $this->assertNotNull($s->lock('job', 5));

        $this->redis->set('other:keep', '1');
        $s->flush();
        $this->assertNull($s->get('n'));
        $this->assertSame('1', $this->redis->get('other:keep'));
        $this->redis->del('other:keep');

        $cache = new Cache($s, 'sadt');
        $this->assertSame(42, $cache->remember('k', 60, static fn () => 42));
        $this->assertSame(42, $cache->get('k'));
    }

    public function testSessionsAndRevoke(): void
    {
        $h = new RedisSessionHandler($this->redis, 3600, $this->prefix);
        $this->assertFalse($h->validateId('abc'));
        $this->assertSame('', $h->read('abc'));
        $_SESSION = ['_uid' => 7];
        $h->write('abc', 'payload-1');
        $h->write('def', 'payload-2');
        $_SESSION = [];
        $h->write('ghi', 'anon');
        $this->assertTrue($h->validateId('abc'));
        $this->assertSame('payload-1', $h->read('abc'));
        $h->revokeUser(7);
        $this->assertSame('', $h->read('abc'));
        $this->assertSame('', $h->read('def'));
        $this->assertSame('anon', $h->read('ghi'));
        $h->destroy('ghi');
        $this->assertSame('', $h->read('ghi'));
    }
}
