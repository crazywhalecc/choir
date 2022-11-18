<?php

declare(strict_types=1);

namespace Choir\Protocol\Context;

use Choir\WebSocket\Opcode;

class WebSocketContext
{
    /** @var bool 是否已握手 */
    public bool $ws_handshake = false;

    /** @var string WebSocket 数据 Buffer */
    public string $ws_data_buffer = '';

    /** @var int 当前帧大小 */
    public int $current_frame_length = 0;

    /** @var string 当前帧的 Buffer */
    public string $current_frame_buffer = '';

    /** @var int WS BINARY 类型 */
    public int $opcode = Opcode::TEXT;

    /** @var string 临时缓存的 WebSocket 数据 */
    public string $tmp_ws_data = '';
}
