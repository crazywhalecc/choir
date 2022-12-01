<?php

declare(strict_types=1);

namespace Tests\Choir\Http;

use Choir\Http\Server;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class ServerTest extends TestCase
{
    public function testConstruct()
    {
        $this->assertEquals('http://0.0.0.0:20005', (new Server('0.0.0.0', 20005))->protocol_name);
    }
}
