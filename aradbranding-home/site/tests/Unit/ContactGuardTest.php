<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\ContactGuard;
use PHPUnit\Framework\TestCase;

final class ContactGuardTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function blocked(): array
    {
        return [
            ['تماس: ۰۹۱۲ ۱۹۶ ۰۲۴۲'], ['+98 912-196-0242'], ['info@arad.com'], ['info (at) arad dot com'],
            ['www.aradbranding.ir'], ['aradbranding.app'], ['تلگرام: arad_trade'], ['@aradtrade'], ['wa.me/98912'],
        ];
    }

    /** @return list<array{0: string}> */
    public static function allowed(): array
    {
        return [
            ['آراد برندینگ یک مجموعه تخصصی در حوزه تجارت است'], ['صادرات ۲۰۰۰ تن پسته در سال ۱۴۰۲'],
            ['ظرفیت 10,000 تن'], ['ISO 9001 و 14001'], ['**برندینگ، مشتری‌یابی**'], ['سال 1395 تا 1400'], ['Mr. Smith'],
        ];
    }

    /** @dataProvider blocked */
    public function testBlocksContactDetails(string $text): void
    {
        self::assertTrue(ContactGuard::contains($text), $text);
        self::assertStringContainsString('•••', ContactGuard::mask($text));
    }

    /** @dataProvider allowed */
    public function testAllowsOrdinaryBusinessText(string $text): void
    {
        self::assertFalse(ContactGuard::contains($text), $text);
        self::assertSame($text, ContactGuard::mask($text));
    }
}
