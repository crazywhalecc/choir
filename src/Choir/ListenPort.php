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

    /**
     * 设置事件回调
     *
     * @throws ChoirException
     */
    public function on(string $event, callable $callback): void
    {
        // 内部全部使用小写，便于存储
        $event = strtolower($event);
        if (!in_array($event, $this->supported_events)) {
            throw new ChoirException('Unsupported event name: ' . $event);
        }
        $this->on_event[$event] = $callback;
    }
}
