<?php

declare(strict_types=1);

namespace Choir\Http\Client;

use Choir\WebSocket\FrameInterface;
use Psr\Http\Message\UriInterface;

interface UpgradableClientInterface
{
    public function getStatus(): int;

    /**
     * @param FrameInterface|string $frame 消息帧
     */
    public function send($frame): bool;

    public function onMessage(callable $callback);

    public function onClose(callable $callback);

    public function upgrade(UriInterface $uri, array $headers = [], bool $reconnect = false): bool;
}
