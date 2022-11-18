<?php

declare(strict_types=1);

namespace Choir;

/**
 * TODO：实现多 Server 容器启动
 */
class MultiServer
{
    /** @var Server[] 服务器们 */
    private array $servers = [];

    /**
     * 添加一个 Server
     *
     * @param  Server $server Server 对象
     * @return $this
     */
    public function addServer(Server $server): MultiServer
    {
        $this->servers[$server->id] = $server;
        return $this;
    }

    /**
     * 启动所有 Server
     */
    public function startAll(): void
    {
        foreach ($this->servers as $server) {
            $server->start();
        }
    }
}
