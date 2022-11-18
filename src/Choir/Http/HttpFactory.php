<?php

declare(strict_types=1);

namespace Choir\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR Http 工厂函数，用于快速构造相关对象
 */
class HttpFactory
{
    /**
     * 创建一个符合 PSR-7 的 Request 对象
     *
     * @param string                               $method          HTTP 请求方法
     * @param string|UriInterface                  $uri             传入的 URI，可传入字符串或 Uri 对象
     * @param array<string, array|string>          $headers         请求头列表
     * @param null|resource|StreamInterface|string $body            HTTP 包体，可传入 Stream、resource、字符串等
     * @param string                               $protocolVersion HTTP 协议版本
     */
    public static function createRequest(string $method, $uri, array $headers = [], $body = null, string $protocolVersion = '1.1'): RequestInterface
    {
        return new Request($method, $uri, $headers, $body, $protocolVersion);
    }

    /**
     * 创建一个符合 PSR-7 的 ServerRequest 对象
     *
     * @param string                               $method       HTTP 请求方法
     * @param string|UriInterface                  $uri          传入的 URI，可传入字符串或 Uri 对象
     * @param array<string, array|string>          $headers      请求头列表
     * @param null|resource|StreamInterface|string $body         HTTP 包体，可传入 Stream、resource、字符串等
     * @param string                               $version      HTTP 协议版本
     * @param array                                $serverParams 服务器请求需要的额外参数
     */
    public static function createServerRequest(string $method, $uri, array $headers = [], $body = null, string $version = '1.1', array $serverParams = [], array $queryParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri, $headers, $body, $version, $serverParams, $queryParams);
    }

    /**
     * 创建一个符合 PSR-7 的 Response 对象
     *
     * @param int|string                           $statusCode      HTTP 的响应状态码
     * @param mixed|string                         $reasonPhrase    HTTP 响应简短语句
     * @param array<string, array|string>          $headers         HTTP 响应头列表
     * @param null|resource|StreamInterface|string $body            HTTP 包体
     * @param string                               $protocolVersion HTTP 协议版本
     */
    public static function createResponse($statusCode = 200, $reasonPhrase = null, array $headers = [], $body = null, string $protocolVersion = '1.1'): ResponseInterface
    {
        return new Response((int) $statusCode, $headers, $body, $protocolVersion, $reasonPhrase);
    }

    /**
     * 创建一个符合 PSR-7 的 Stream 对象
     *
     * @param  null|resource|StreamInterface|string $body 传入的数据
     * @throws \InvalidArgumentException            如果传入的类型非法，则会抛出此异常
     * @throws \RuntimeException                    如果创建 Stream 对象失败，则会抛出此异常
     */
    public static function createStream($body = null): StreamInterface
    {
        return Stream::create($body ?? '');
    }

    /**
     * 创建一个符合 PSR-7 的 Uri 对象
     *
     * @param  string|UriInterface       $uri URI 对象或字符串
     * @throws \InvalidArgumentException 如果解析 URI 失败，则抛出此异常
     */
    public static function createUri($uri): UriInterface
    {
        if ($uri instanceof UriInterface) {
            return $uri;
        }
        return new Uri($uri);
    }
}
