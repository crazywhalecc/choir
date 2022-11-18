<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\WebSocket\Frame;
use Choir\WebSocket\FrameFactory;
use Choir\WebSocket\Opcode;

/**
 * WebSocket 协议的连接对象
 */
class WsConnection extends HttpConnection
{
    /**
     * 向对端发送 WebSocket 信息
     *
     * @param  Frame|string $frame  二进制文本或数据帧
     * @param  null|int     $opcode 如果传入的是UTF-8文本，则此处可传入 TEXT 或 BINARY 两种类型的 Opcode
     * @throws \Throwable
     */
    public function push($frame, ?int $opcode = null): bool
    {
        if (is_string($frame)) {
            $frame = new Frame($frame, $opcode ?? Opcode::TEXT, false, true);
        }
        $status = $this->send($frame->getRaw());
        return $status !== false;
    }

    /**
     * 标准地断开 WebSocket 连接，向对端发送关闭帧
     *
     * @param  int        $code   RFC6455 关闭编码
     * @param  string     $reason 原因，字节长度不超过125
     * @throws \Throwable
     */
    public function disconnect(int $code = CHOIR_WS_CLOSE_NORMAL, string $reason = ''): bool
    {
        return $this->send(FrameFactory::createCloseFrame($code, $reason)->getRaw()) !== false;
    }
}
