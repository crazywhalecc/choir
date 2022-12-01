<?php

declare(strict_types=1);

namespace Choir\WebSocket;

/**
 * psr-7 extended websocket frame
 */
class Frame implements FrameInterface
{
    /** @var string 默认的 Mask 掩码 */
    public static string $mask_key = "\x7a\x6d\x5a\x4d";

    /**
     * @var mixed|string
     */
    protected $data;

    /**
     * @var int The opcode of the frame
     */
    protected int $opcode;

    /**
     * @var bool WebSocket Mask, RFC 6455 Section 10.3
     */
    protected bool $mask;

    /**
     * @var bool FIN
     */
    protected bool $finish;

    private ?string $raw_cache = null;

    public function __construct($data, int $opcode, bool $mask, bool $fin)
    {
        $this->data = $data;
        $this->opcode = $opcode;
        $this->mask = $mask;
        $this->finish = $fin;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getOpcode(): int
    {
        return $this->opcode;
    }

    /**
     * 规定当且仅当由客户端向服务端发送的 frame, 需要使用掩码覆盖
     */
    public function isMasked(): bool
    {
        return $this->mask;
    }

    public function isFinish(): bool
    {
        return $this->finish;
    }

    /**
     * 获取 Frame 的二进制段
     *
     * @param bool $masked 是否返回被掩码的数据，默认为 false
     */
    public function getRaw(bool $masked = false): string
    {
        var_dump($masked);
        if ($this->raw_cache !== null) {
            return $this->raw_cache;
        }
        // FIN
        $byte_0 = ($this->finish ? 1 : 0) << 7;
        // Opcode
        $byte_0 = $byte_0 | $this->opcode;

        $len = strlen($this->data);

        // 掩码状态
        if ($masked) {
            $masks = static::$mask_key;
            $masks = \str_repeat($masks, (int) floor($len / 4)) . \substr($masks, 0, $len % 4);
            $data = $this->data ^ $masks;
        } else {
            $data = $this->data;
        }
        if ($len <= 125) {
            $encode_buffer = chr($byte_0) . chr($masked ? $len | 128 : $len) . $data;
        } elseif ($len <= 65535) {
            $encode_buffer = chr($byte_0) . chr($masked ? 254 : 126) . pack('n', $len) . $data;
        } else {
            $encode_buffer = chr($byte_0) . chr($masked ? 255 : 127) . pack('xxxxN', $len) . $data;
        }

        return $this->raw_cache = $encode_buffer;
    }
}
