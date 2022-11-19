<?php

declare(strict_types=1);

namespace Tests\Choir\Http;

use Choir\Http\HttpFactory;
use Choir\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
class MessageTraitTest extends TestCase
{
    private static RequestInterface $trait_class;

    public static function setUpBeforeClass(): void
    {
        self::$trait_class = HttpFactory::createRequest(
            'POST',
            '/test',
            [
                'A' => 'B',
                'C' => [
                    '123',
                    '456',
                ],
            ],
            'hello'
        );
    }

    public function testGetHeaders()
    {
        $this->assertIsArray(self::$trait_class->getHeaders());
    }

    public function testGetHeader()
    {
        $this->assertIsArray(self::$trait_class->getHeader('A'));
        $this->assertIsArray(self::$trait_class->getHeader('a'));
        $this->assertEquals('B', self::$trait_class->getHeader('a')[0]);
    }

    public function testGetBody()
    {
        $this->assertInstanceOf(StreamInterface::class, self::$trait_class->getBody());
        $this->assertEquals('', HttpFactory::createRequest('GET', '/')->getBody()->getContents());
        $this->assertEquals('hello', self::$trait_class->getBody()->getContents());
    }

    public function testWithProtocolVersion()
    {
        $this->assertNotSame(self::$trait_class->withProtocolVersion('1.1'), self::$trait_class);
        $this->assertEquals('2.0', self::$trait_class->withProtocolVersion('2.0')->getProtocolVersion());
    }

    public function testHasHeader()
    {
        $this->assertTrue(self::$trait_class->hasHeader('a'));
        $this->assertTrue(self::$trait_class->hasHeader('A'));
        $this->assertFalse(self::$trait_class->hasHeader('User-Agent'));
    }

    public function testGetProtocolVersion()
    {
        $this->assertEquals('1.1', self::$trait_class->getProtocolVersion());
    }

    public function testWithHeader()
    {
        $this->assertNotSame(self::$trait_class->withHeader('User-Agent', 'HEICORE'), self::$trait_class);
        $this->assertEquals('HEICORE', self::$trait_class->withHeader('C', 'HEICORE')->getHeaderLine('C'));
    }

    public function testGetHeaderLine()
    {
        $this->assertEquals('B', self::$trait_class->getHeaderLine('A'));
        $this->assertEquals('B', self::$trait_class->getHeaderLine('a'));
        $this->assertEquals('', self::$trait_class->getHeaderLine('not-exist-header'));
        $this->assertEquals('123, 456', self::$trait_class->getHeaderLine('C'));
    }

    public function testWithAddedHeader()
    {
        $this->assertNotSame(self::$trait_class->withAddedHeader('c', ['789']), self::$trait_class);
        $this->assertEquals('123, 456, 789', self::$trait_class->withAddedHeader('c', ['789'])->getHeaderLine('c'));
        $this->assertEquals('new', self::$trait_class->withAddedHeader('D', 'new')->getHeaderLine('D'));
        // Test int header
        $req = new Request('GET', '/', [132 => '123']);
        $this->assertEquals('123', $req->getHeaderLine('132'));

        // Test exception
        $this->expectException(\InvalidArgumentException::class);
        self::$trait_class->withAddedHeader(['test-array' => 'ok'], 'are you');
    }

    public function testWithoutHeader()
    {
        $this->assertNotSame(self::$trait_class->withoutHeader('Cmm'), self::$trait_class);
        $this->assertEquals('', self::$trait_class->withoutHeader('c')->getHeaderLine('c'));
    }

    public function testWithBody()
    {
        $this->assertNotSame(self::$trait_class->withBody(HttpFactory::createStream('test')), self::$trait_class);
        $this->assertEquals('test', self::$trait_class->withBody(HttpFactory::createStream('test'))->getBody()->getContents());
    }

    /**
     * @dataProvider providerValidateAndTrimHeaderExceptions
     * @param mixed $header
     * @param mixed $value
     */
    public function testValidateAndTrimHeaderExceptions($header, $value)
    {
        $no_throwable = false;
        try {
            self::$trait_class->withHeader($header, $value);
            $no_throwable = true;
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        }
        $this->assertFalse($no_throwable);
    }

    public function providerValidateAndTrimHeaderExceptions(): array
    {
        return [
            'header not string' => [[], []],
            'value not valid' => ['www', true],
            'value array empty' => ['www', []],
            'value array not valid' => ['www', [true]],
        ];
    }
}
