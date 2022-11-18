<?php

declare(strict_types=1);

namespace Choir\Protocol;

/**
 * HTTPS 协议的实现（基于 HTTP，仅改下传输方式）
 */
class HttpsProtocol extends HttpProtocol
{
    public function getTransport(): string
    {
        return 'ssl';
    }
}
