<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\Str;
use App\Core\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once BASE_PATH . '/app/Core/helpers.php';
    }

    public function testRulesAndNormalisation(): void
    {
        [$clean, $errors] = Validator::make(
            ['email' => ' Ali@Example.COM ', 'phone' => '۰۹۱۲۳۴۵۶۷۸۹', 'age' => '۴۲', 'bio' => '', 'ok' => 'on'],
            ['email' => ['required', 'email'], 'phone' => ['required', 'digits'], 'age' => ['int'], 'bio' => ['max:10'], 'ok' => ['bool']]
        );
        self::assertSame([], $errors);
        self::assertSame('ali@example.com', $clean['email']);
        self::assertSame('09123456789', $clean['phone']);
        self::assertSame(42, $clean['age']);
        self::assertNull($clean['bio']);
        self::assertTrue($clean['ok']);
    }

    public function testErrors(): void
    {
        [, $errors] = Validator::make(
            ['a' => '', 'b' => 'x', 'c' => 'abc', 'd' => 'z'],
            ['a' => ['required'], 'b' => ['email'], 'c' => ['regex:/^[0-9]+$/'], 'd' => ['in:x,y']]
        );
        self::assertSame(['a', 'b', 'c', 'd'], array_keys($errors));
    }

    public function testSafeNext(): void
    {
        self::assertSame('/pages', Str::safeNext('/pages'));
        self::assertSame('/dashboard', Str::safeNext('//evil.com'));
        self::assertSame('/dashboard', Str::safeNext('https://evil.com'));
        self::assertSame('/dashboard', Str::safeNext('/\\evil.com'));
    }

    public function testPersianNormalisation(): void
    {
        self::assertSame('علی کریمی', Str::normalize('علي  كريمي'));
    }
}
