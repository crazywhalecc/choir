<?php

declare(strict_types=1);

namespace Tests\Choir\Http;

use Choir\Http\HttpFactory;
use Choir\Http\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * @internal
 */
class HttpFactoryTest extends TestCase
{
    public function testCreateStream()
    {
        $this->assertInstanceOf(StreamInterface::class, HttpFactory::createStream());
    }

    public function testCreateUri()
    {
        $this->assertInstanceOf(UriInterface::class, HttpFactory::createUri('/'));
        $uri = new Uri();
        $this->assertSame(HttpFactory::createUri($uri), $uri);
    }

    public function testCreateServerRequest()
    {
        $this->assertInstanceOf(ServerRequestInterface::class, HttpFactory::createServerRequest('GET', '/'));
    }

    public function testCreateRequest()
    {
        $this->assertInstanceOf(RequestInterface::class, HttpFactory::createRequest('GET', '/'));
    }

    public function testCreateResponse()
    {
        $this->assertInstanceOf(ResponseInterface::class, HttpFactory::createResponse());
    }
}
