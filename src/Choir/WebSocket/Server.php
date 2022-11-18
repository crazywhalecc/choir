<?php

declare(strict_types=1);

namespace Choir\WebSocket;

/**
 * Swoole 风格的 Server 包装类，传入的是独立的地址和端口
 */
class Server extends \Choir\Server
{
    public function __construct(string $host, int $port, bool $enable_ssl = false, array $settings = [])
    {
        $protocol_name = ($enable_ssl ? 'wss' : 'ws') . '://' . $host . ':' . $port;
        parent::__construct($protocol_name, $settings);
    }
}
