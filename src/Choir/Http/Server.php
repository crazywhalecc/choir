<?php

declare(strict_types=1);

namespace Choir\Http;

use Choir\Exception\ChoirException;

/**
 * Swoole 风格的 Server 包装类，传入的是独立的地址和端口
 */
class Server extends \Choir\Server
{
    /**
     * @param  string         $host       监听地址
     * @param  int            $port       监听端口
     * @param  bool           $enable_ssl 是否启用 HTTPS（默认为否）
     * @param  array          $settings   传入的配置参数
     * @throws ChoirException 如果无法创建 Server，则抛出异常
     */
    public function __construct(string $host, int $port, bool $enable_ssl = false, array $settings = [])
    {
        $protocol_name = ($enable_ssl ? 'https' : 'http') . '://' . $host . ':' . $port;
        parent::__construct($protocol_name, $settings);
    }
}
