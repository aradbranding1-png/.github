<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\IpMatcher;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    public function testSingleAndCidr(): void
    {
        $list = '10.0.0.5, 173.245.48.0/20, 2400:cb00::/32';
        $this->assertTrue(IpMatcher::matches('10.0.0.5', $list));
        $this->assertFalse(IpMatcher::matches('10.0.0.6', $list));
        $this->assertTrue(IpMatcher::matches('173.245.63.255', $list));
        $this->assertFalse(IpMatcher::matches('173.245.64.0', $list));
        $this->assertTrue(IpMatcher::matches('2400:cb00:1::1', $list));
        $this->assertFalse(IpMatcher::matches('2400:cb01::1', $list));
    }

    public function testInvalidInputNeverMatches(): void
    {
        $this->assertFalse(IpMatcher::matches('not-an-ip', '0.0.0.0/0'));
        $this->assertFalse(IpMatcher::matches('1.2.3.4', ''));
        $this->assertFalse(IpMatcher::matches('1.2.3.4', '1.2.3.0/99'));
        $this->assertFalse(IpMatcher::matches('::1', '127.0.0.0/8'));
        $this->assertTrue(IpMatcher::matches('1.2.3.4', ['5.6.7.8', '1.2.3.0/24']));
    }
}
