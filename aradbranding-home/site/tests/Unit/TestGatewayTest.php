<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payments\Gateways\TestGateway;
use PHPUnit\Framework\TestCase;

final class TestGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PAYMENT_TEST_GATEWAY');
        putenv('APP_ENV');
    }

    public function testNeverEnabledInProduction(): void
    {
        putenv('PAYMENT_TEST_GATEWAY=true');
        putenv('APP_ENV=production');
        $this->assertFalse(TestGateway::enabled());
        $this->assertFalse((new TestGateway())->available());
        $this->assertTrue(TestGateway::misconfigured());
    }

    public function testOnlyWithExplicitFlagOutsideProduction(): void
    {
        putenv('APP_ENV=staging');
        putenv('PAYMENT_TEST_GATEWAY=false');
        $this->assertFalse(TestGateway::enabled());
        putenv('PAYMENT_TEST_GATEWAY=1');
        $this->assertFalse(TestGateway::enabled(), 'only the literal "true" enables it');
        putenv('PAYMENT_TEST_GATEWAY=true');
        $this->assertTrue(TestGateway::enabled());
        $this->assertFalse(TestGateway::misconfigured());
    }
}
