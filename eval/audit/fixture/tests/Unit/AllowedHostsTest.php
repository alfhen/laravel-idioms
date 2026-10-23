<?php

namespace Tests\Unit;

use App\Support\AllowedHosts;
use PHPUnit\Framework\TestCase;

class AllowedHostsTest extends TestCase
{
    public function test_matches_hosts_case_insensitively(): void
    {
        $hosts = new AllowedHosts([' Shop.Example.com ']);

        $this->assertTrue($hosts->allows('shop.example.COM'));
        $this->assertFalse($hosts->allows('evil.example.com'));
        $this->assertFalse($hosts->isEmpty());
    }
}
