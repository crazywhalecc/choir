<?php

declare(strict_types=1);

namespace Choir\Protocol;

/**
 * 基于 SSL 的 WebSocket 连接对象（wss）
 */
class WssProtocol extends WebSocketProtocol
{
    public function getTransport(): string
    {
        return 'ssl';
    }
}
