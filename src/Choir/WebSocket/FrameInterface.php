<?php

declare(strict_types=1);

namespace Choir\WebSocket;

interface FrameInterface
{
    public function getData();

    public function getOpcode();

    public function isMasked(): bool;

    public function isFinish(): bool;

    public function getRaw(): string;
}
