<?php

declare(strict_types=1);

namespace Choir\WebSocket;

class FrameFactory
{
    public static function createPingFrame(): Frame
    {
        return new Frame(null, Opcode::PING, true, true);
    }

    public static function createPongFrame(): Frame
    {
        return new Frame(null, Opcode::PONG, true, true);
    }

    public static function createTextFrame(string $payload): Frame
    {
        return new Frame($payload, Opcode::TEXT, true, true);
    }

    public static function createBinaryFrame(string $payload): Frame
    {
        return new Frame($payload, Opcode::BINARY, true, true);
    }

    public static function createCloseFrame(int $code = null, string $reason = null): Frame
    {
        return new CloseFrame($code, $reason);
    }
}
