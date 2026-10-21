<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $bin = Ulid::generateBinary();
        $str = Ulid::toString($bin);
        self::assertSame(26, strlen($str));
        self::assertTrue(Ulid::isValid($str));
        self::assertSame($bin, Ulid::toBinary($str));
    }

    public function testMonotonicWithinSameMillisecond(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = Ulid::generate();
        }
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids);
        self::assertCount(1000, array_unique($ids));
    }

    public function testTimestamp(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $ms = Ulid::timestampMs(Ulid::generate());
        self::assertGreaterThanOrEqual($before, $ms);
        self::assertLessThan($before + 1000, $ms);
    }

    public function testRejectsInvalid(): void
    {
        self::assertFalse(Ulid::isValid('8ZZZZZZZZZZZZZZZZZZZZZZZZZ')); // overflow first char
        self::assertFalse(Ulid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAI')); // I is not Crockford
    }
}
