<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\Http\HttpFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 协议的连接对象
 */
class HttpConnection extends Tcp
{
    /**
     * @var array<string, ResponseInterface> 重复的小回包缓存
     */
    private static array $resp_cache = [];

    /**
     * 响应 HTTP 请求
     *
     * @param  ResponseInterface|string $response 响应对象或内容
     * @throws \Throwable
     */
    public function end($response): bool
    {
        // 先确定是不是 WebSocket，并且已经建立了连接，如果建立了链接那么此 Socket 就不能收发 HTTP 消息了
        /*if (isset($this->context->ws_handshake) && $this->context->ws_handshake === true) {
            return false;
        }*/
        if (is_string($response)) {
            // 从缓存中获取到的话就获取缓存的，获取不到就创建个新的并缓存
            $response_obj = !isset($response[512]) && isset(self::$resp_cache[$response]) ? (self::$resp_cache[$response]) : HttpFactory::createResponse(200, null, [], $response);
            if (!isset($response[512])) {
                self::$resp_cache[$response] = $response_obj;
            }
            if (count(self::$resp_cache) > 1024) {
                unset(self::$resp_cache[key(self::$resp_cache)]);
            }
            /* @phpstan-ignore-next-line */
            return $this->send((string) $response_obj) !== false;
        }
        // 如果是对象，就要先转换成 字符串
        if ($response instanceof ResponseInterface && method_exists($response, '__toString')) {
            return $this->send((string) $response) !== false;
        }
        return false;
    }
}
