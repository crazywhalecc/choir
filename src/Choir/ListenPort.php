<?php

declare(strict_types=1);

namespace Choir;

use Choir\Exception\ChoirException;

/**
 * 独立监听端口的类
 */
class ListenPort
{
    use SocketListenTrait;

    public string $protocol_name;

    public array $settings = [];

    public Server $server;

    /**
     * @throws ChoirException
     * @throws Exception\ValidatorException
     */
    public function __construct(string $protocol_name, Server $server, array $settings = [])
    {
        $this->server = $server;
        $this->settings = $settings;
        $this->protocol_name = $protocol_name;
        $this->initSocketListen();
    }
}
