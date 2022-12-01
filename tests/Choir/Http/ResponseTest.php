<?php

declare(strict_types=1);

namespace Tests\Choir\Http;

use Choir\Http\HttpFactory;
use Choir\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class ResponseTest extends TestCase
{
    private static Response $response;

    public static function setUpBeforeClass(): void
    {
        /* @phpstan-ignore-next-line */
        self::$response = HttpFactory::createResponse(
            200,
            'OK',
            [
                'X-Key' => '123fff',
            ],
            'hahaha'
        );
    }

    public function testCoverage()
    {
        $this->assertIsString((new Response('200', [], 'test', '1.1', 'OKK'))->__toString());
    }

    public function testGetReasonPhrase()
    {
        $this->assertSame('OK', self::$response->getReasonPhrase());
    }

    /**
     * @dataProvider providerWithStatus
     * @param mixed $code               状态码
     * @param mixed $expected_exception 期望抛出的异常
     */
    public function testWithStatus($code, $expected_exception)
    {
        $this->assertNotSame(self::$response->withStatus(200), self::$response);
        try {
            self::$response->withStatus($code);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($expected_exception, $e);
        }
    }

    public function providerWithStatus(): array
    {
        return [
            'not valid code exception' => [[], \InvalidArgumentException::class],
            'invalid code number exception' => [600, \InvalidArgumentException::class],
        ];
    }

    public function testToString()
    {
        $this->assertTrue(method_exists(self::$response, '__toString'));
        $this->assertIsString((string) self::$response);
        $lines = explode("\r\n", (string) self::$response);
        $this->assertEquals('HTTP/1.1 200 OK', $lines[0]);
        $this->assertContains('X-Key: 123fff', $lines);
        $this->assertStringEndsWith("\r\n\r\nhahaha", (string) self::$response);
    }

    public function testGetStatusCode()
    {
        $this->assertSame(200, self::$response->getStatusCode());
    }
}
