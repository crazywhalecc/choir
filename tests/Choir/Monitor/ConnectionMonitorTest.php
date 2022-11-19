<?php

declare(strict_types=1);

namespace Tests\Choir\Monitor;

use Choir\Monitor\ConnectionMonitor;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class ConnectionMonitorTest extends TestCase
{
    public function testAddTcpFailCount()
    {
        ConnectionMonitor::addTcpFailCount('send', 20);
        $this->assertGreaterThanOrEqual(20, ConnectionMonitor::getTcpFailCount('send'));
        ConnectionMonitor::addTcpFailCount('receive', 30);
        $this->assertGreaterThanOrEqual(30, ConnectionMonitor::getTcpFailCount('receive'));
    }
}
