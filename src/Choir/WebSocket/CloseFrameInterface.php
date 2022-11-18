<?php

declare(strict_types=1);

namespace Choir\WebSocket;

interface CloseFrameInterface extends FrameInterface
{
    public function getCode(): int;
}
