<?php

declare(strict_types=1);

namespace Tests\Choir\Protocol;

use Choir\Protocol\HttpProtocol;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class HttpProtocolTest extends TestCase
{
    protected static HttpProtocol $protocol;

    public static function setUpBeforeClass(): void
    {
        self::$protocol = new HttpProtocol('0.0.0.0', 12345, 'http://0.0.0.0:12345');
    }

    public function testCalculateChunkLength()
    {
        $buffer = "1\r\n3\r\n0\r\n\r\n";
        $this->assertEquals(1, self::$protocol->calculateChunkLength($buffer));
    }

    public function testMergeChunkedBody()
    {
        $buffer = "1\r\n3\r\n0\r\n\r\n";
        $this->assertEquals(1, self::$protocol->calculateChunkLength($buffer, $content));
        $this->assertEquals('3', $content);
    }
}
