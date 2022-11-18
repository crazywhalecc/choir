<?php

declare(strict_types=1);

namespace Choir\WebSocket;

/**
 * WebSocket 的关闭帧
 */
class CloseFrame extends Frame implements CloseFrameInterface
{
    protected int $code;

    /**
     * @var string (UTF-8)
     */
    protected string $reason;

    public function __construct(int $code, string $reason = '', bool $mask = false)
    {
        $data = hex2bin(str_pad(dechex($code), 4, '0', STR_PAD_LEFT)) . $reason;
        parent::__construct($data, Opcode::CLOSE, $mask, true);

        $this->code = $code;
        $this->reason = $reason;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
