<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Cache\Cache;
use App\Core\Cache\FileStore;
use PHPUnit\Framework\TestCase;

final class FileStoreTest extends TestCase
{
    private string $dir;
    private FileStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sadt-cache-' . bin2hex(random_bytes(4));
        $this->store = new FileStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->store->flush();
        @rmdir($this->dir);
    }

    public function testPutGetForget(): void
    {
        $this->store->put('a', ['x' => 1], 60);
        self::assertSame(['x' => 1], $this->store->get('a'));
        $this->store->forget('a');
        self::assertNull($this->store->get('a'));
    }

    public function testFalseAndZeroAreStored(): void
    {
        $this->store->put('f', false, 60);
        $this->store->put('z', 0, 60);
        self::assertFalse($this->store->get('f'));
        self::assertSame(0, $this->store->get('z'));
    }

    public function testIncrement(): void
    {
        self::assertSame(1, $this->store->increment('n', 1, 60));
        self::assertSame(3, $this->store->increment('n', 2, 60));
    }

    public function testLockIsExclusive(): void
    {
        $lock = $this->store->lock('job', 10);
        self::assertNotNull($lock);
        // flock is per file handle, so a second handle in the same process is refused too.
        self::assertNull($this->store->lock('job', 10));
        $lock->release();
        self::assertNotNull($this->store->lock('job', 10));
    }

    public function testRememberAndVersioning(): void
    {
        $cache = new Cache($this->store, 'test');
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;
            return 'v';
        };
        $v = $cache->version('page:1');
        self::assertSame('v', $cache->remember("page:1:v{$v}", 60, $fn));
        self::assertSame('v', $cache->remember("page:1:v{$v}", 60, $fn));
        self::assertSame(1, $calls);

        $cache->bump('page:1');
        $v2 = $cache->version('page:1');
        self::assertSame($v + 1, $v2);
        $cache->remember("page:1:v{$v2}", 60, $fn);
        self::assertSame(2, $calls);
    }
}
