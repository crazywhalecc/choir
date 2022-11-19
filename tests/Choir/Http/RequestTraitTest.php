<?php

declare(strict_types=1);

namespace Tests\Choir\Http;

use Choir\Http\HttpFactory;
use Choir\Http\Request;
use Choir\Http\ServerRequest;
use Choir\Http\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * @internal
 */
class RequestTraitTest extends TestCase
{
    private static RequestInterface $request;

    public static function setUpBeforeClass(): void
    {
        self::$request = HttpFactory::createRequest(
            'GET',
            '/test?pwq=123',
        );
    }

    public function testGetUri()
    {
        $this->assertInstanceOf(UriInterface::class, self::$request->getUri());
        $this->assertEquals('/test', self::$request->getUri()->getPath());
    }

    public function testWithUri()
    {
        $this->assertNotSame(self::$request->withUri(self::$request->getUri()), self::$request);
    }

    public function testWithRequestTarget()
    {
        $this->assertNotSame(self::$request->withRequestTarget(self::$request->getRequestTarget()), self::$request);
    }

    public function testWithMethod()
    {
        $this->assertNotSame(self::$request->withMethod(self::$request->getMethod()), self::$request);
    }

    public function testGetMethod()
    {
        $this->assertEquals('GET', self::$request->getMethod());
    }

    public function testGetRequestTarget()
    {
        // fulfill requestTarget is not null
        $req = new Request('GET', '');
        $this->assertEquals('/', $req->getRequestTarget());
        $req = $req->withRequestTarget('/ppp?help=123');
        $this->assertEquals('/ppp?help=123', $req->getRequestTarget());
        // Original uri is request target
        $this->assertEquals('/test?pwq=123', self::$request->getRequestTarget());
    }

    public function testUpdateHostFromUri()
    {
        $req1 = (new ServerRequest('GET', '/p'))->withHeader('Host', 'baidu.com');
        $req2 = self::$request->withUri(new Uri('https://www.evil.com'));
        $uri = new Uri('http://10.0.0.1:8090/test233?param=value');
        $uri2 = new Uri('/test2?p2=v2');
        $req3 = $req1->withUri($uri);
        $req4 = $req2->withUri($uri2);
        $this->assertEquals('/test233', $req3->getUri()->getPath());
        $this->assertEquals('/test2', $req4->getUri()->getPath());
    }
}
