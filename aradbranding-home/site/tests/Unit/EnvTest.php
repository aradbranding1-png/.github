<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testParsesAndWrites(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# c\nT_A=hello\nT_B=\"quoted value\"\nT_C=true\nT_D=x # comment\n");
        Env::load($file);
        self::assertSame('hello', Env::get('T_A'));
        self::assertSame('quoted value', Env::get('T_B'));
        self::assertTrue(Env::get('T_C'));
        self::assertSame('x', Env::get('T_D'));

        Env::write($file, ['T_A' => 'new', 'T_E' => 'with space']);
        Env::load($file);
        self::assertSame('new', Env::get('T_A'));
        self::assertSame('with space', Env::get('T_E'));
        unlink($file);
    }
}
