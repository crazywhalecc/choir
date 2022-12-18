<?php

declare(strict_types=1);

namespace Choir\Http\Client;

use Choir\WebSocket\FrameInterface;
use Psr\Http\Message\UriInterface;

interface UpgradableClientInterface
{
    /**
     * 获取 WebSocket 连接状态
     */
    public function getStatus(): int;

    /**
     * 发送 WebSocket Frame 消息帧
     *
     * 如果发送的是字符串，则自动生成一个文本类型的帧
     * 如果发送的是帧，则直接发送
     * 发送失败时返回 False
     *
     * @param FrameInterface|string $frame 消息帧
     */
    public function send($frame): bool;

    /**
     * 设置接收到对端消息时的回调
     *
     * @param  callable $callback 回调函数
     * @return mixed
     */
    public function onMessage(callable $callback);

    /**
     * 设置连接断开时的回调
     *
     * @param  callable $callback 回调函数
     * @return mixed
     */
    public function onClose(callable $callback);

    /**
     * 发起一个 WebSocket 连接升级的请求
     *
     * Uri 必须包含 Scheme、目标地址，即完整的 URL，例如 http://localhost:8089/
     * headers 参数可为空
     * reconnect 参数为 False 的时候，必须重新声明 Client 对象才可重新链接，传入 True 时会直接复用资源
     *
     * @param UriInterface $uri       请求的链接
     * @param array        $headers   请求的 Headers
     * @param bool         $reconnect 是否重新链接，默认为 False
     */
    public function upgrade(UriInterface $uri, array $headers = [], bool $reconnect = false): bool;
}
