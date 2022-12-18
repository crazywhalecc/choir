<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\Exception\ProtocolException;
use Choir\Exception\ValidatorException;
use Choir\Http\HttpFactory;
use Choir\Protocol\Context\WebSocketContext;
use Choir\Server;
use Choir\SocketBase;
use Choir\WebSocket\Frame;
use Choir\WebSocket\FrameFactory;
use Choir\WebSocket\Opcode;
use Psr\Http\Message\RequestInterface;

/**
 * WebSocket 协议的实现，基于 HTTP 协议，可同时使用 request 回调
 */
class WebSocketProtocol extends HttpProtocol
{
    /**
     * @var bool 是否自动拼接 Frame，如果为是，则如果接收的 FIN 为 0，就在协议内部先接收完再拼接
     */
    public static bool $auto_splicing_frame = true;

    /**
     * 使用掩码 Key 对数据进行解码或掩码
     *
     * @param string $mask_key 掩码 key（4 字节数据）
     * @param string $data     要被掩码或者解掩码的数据
     */
    public static function maskData(string $mask_key, string $data): string
    {
        $len = strlen($data);
        $masks = $mask_key;
        $masks = \str_repeat($masks, (int) floor($len / 4)) . \substr($masks, 0, $len % 4);
        return $data ^ $masks;
    }

    /**
     * 解析二进制的 Frame 数据 Head，通过引用参数返回
     *
     * @param  null|mixed $fin      返回第一个 bit 的数据，可能是 1 或 0
     * @param  null|mixed $opcode   返回 Opcode 的十进制值
     * @param  null|mixed $masked   返回 Mask 标记位的数据，可能是 1 或 0
     * @param  null|mixed $head_len 返回 Frame Head 部分的长度，十进制值
     * @param  null|mixed $data_len 返回 Frame Data 部分的长度，十进制值
     * @param  null|mixed $mask_key 如果 Mask 为 1，则返回四字节的 Mask Key
     * @return bool       解析失败时返回 false
     */
    public static function parseRawFrame(
        string $raw,
        &$fin = null,
        &$opcode = null,
        &$masked = null,
        &$head_len = null,
        &$data_len = null,
        &$mask_key = null
    ): bool {
        if (!isset($raw[0], $raw[1])) {
            return false;
        }
        $recv_len = strlen($raw);
        // 第一个字节的数据，包含 [FIN，RSV1，RSV2，RSV3，Opcode*4]
        $byte_0 = ord($raw[0]);
        // 第二个字节的数据，第一位为 Mask，如果为1则代表 Masked，这里只用了这个数据
        $byte_1 = ord($raw[1]);
        // 数据 payload 长度，从第二个字节的后7位获取（& 127 位与）
        $data_len = $byte_1 & 127;
        // Masked的定义第二个字节的第一个比特（第九个bit）
        $masked = $byte_1 >> 7;
        // FIN 字节
        $fin = $byte_0 >> 7;
        // Opcode，在第一个字节的后四位代表
        $opcode = $byte_0 & 0xF;
        // 解析包长度，data_len 就是要接收的数据包长度
        $head_len = 6;      // 至少6字节的原因是，masking-key占4字节，头两个字节都是肯定会有的，所以前面少于6字节也显然不能成 package
        if ($data_len === 126) {
            // 数据长度字节为126的话，后续2个字节代表一个16位的无符号整数，该无符号整数的值为数据的长度。
            $head_len = 8;
            if ($head_len > $recv_len) {
                return false;
            }
            // 从后两个字节中获取数据长度十进制值
            $data_len = unpack('nn/ntotal_len', $raw)['total_len'];
        } elseif ($data_len === 127) {
            // 后续8个字节代表一个64位的无符号整数（最高位为0），该无符号整数的值为数据的长度。
            $head_len = 14;
            if ($head_len > $recv_len) {
                return false;
            }
            // 从后8个字节中获取数据的长度
            $arr = \unpack('n/N2c', $raw);
            $data_len = $arr['c1'] * 4294967296 + $arr['c2'];
        }
        // mask-key
        if ($masked) {
            $mask_key = substr($raw, $head_len - 4, 4);
            if (strlen($mask_key) !== 4) {
                return false;
            }
        }
        return true;
    }

    /**
     * @throws \Throwable
     */
    public function checkPackageLength(string $buffer, ConnectionInterface $connection): int
    {
        // no UDP
        if (!$connection instanceof Tcp) {
            return 0;
        }

        $recv_len = strlen($buffer);
        // WebSocket 需要更多字节，这么少，塞牙都不够！
        if ($recv_len < 6) {
            return 0;
        }

        // 哎呀，你这个小伙子好像还没和我握手，先执行握手协议，走 HTTP 协议解析
        if (!$connection->context->ws_handshake) {
            return parent::checkPackageLength($buffer, $connection);
        }

        // 下面的部分都是 WebSocket 握手后，收 Frame 计算包长度的部分

        // 解析 WS Frame
        if ($connection->context->current_frame_length !== 0 && $connection->context->current_frame_length > $recv_len) {
            // 当前 frame 数据长度已知，但当前接收的 buffer 数据没有 frame 数据长度多，需要更多数据拼接 Frame，继续接收
            return 0;
        }

        // 当前 frame 数据长度已知，且当前的 buffer 长度已经大于等于当前 frame 数据长度的值了，就返回当前包的长度
        if ($connection->context->current_frame_length !== 0) {
            return $connection->context->current_frame_length;
        }

        // 下面是 frame 数据长度还未知（0）的情况，需要我们先解析 Frame 的头
        $parsed = static::parseRawFrame(
            $buffer,
            $fin,
            $opcode,
            $masked,
            $head_len,
            $data_len,
            $mask_key
        );

        if (!$parsed) {
            Server::logDebug('Parse frame head failed, close the connection');
            $connection->close();
            return 0;
        }
        // Frame 必须 musk（马斯克：？？？）
        if (!$masked) {
            Server::logDebug('Frame not masked, close the connection.');
            $connection->close();
            return 0;
        }

        // 解析 FIN - opcode，从第一个字节中提取后四位，拿到 opcode
        switch ($opcode) {
            case Opcode::CONTINUATION:  // 延续帧 type
            case Opcode::TEXT:          // blob type（UTF-8）
            case Opcode::BINARY:        // ArrayBuffer type（Binary）
            case Opcode::PING:          // ping package
            case Opcode::PONG:          // pong package
            case Opcode::CLOSE:         // close package
                break;
            default:
                Server::logDebug('Wrong opcode, close the connection.');
                $connection->close();
                return 0;
        }

        // current_frame_length 就是当前接收的这个帧本身头部的长度
        $current_frame_length = $head_len + $data_len;

        // 计算包长度，即：头长度+内容长度
        $total_package_size = intval($current_frame_length); // + strlen($connection->context->ws_data_buffer);

        // 整个 Frame 长度太长了，长过了 Choir 规定的最大 package 大小，断开链接
        if ($total_package_size > $connection->getMaxPackageSize()) {
            Server::logDebug("error package. package_length={$total_package_size}");
            $connection->close();
            return 0;
        }

        // 返回当前 Frame 的长度
        return $total_package_size;
    }

    /**
     * @param  mixed              $server
     * @throws ValidatorException
     * @throws \Throwable
     * @throws ProtocolException
     */
    public function execute($server, string $package, ConnectionInterface $connection): bool
    {
        /** @var Frame[] $frames Frame 缓存 */
        static $frames = [];

        // 不要把 UDP 连接传进来！
        if (!$connection instanceof WsConnection) {
            return false;
        }

        $cache = static::$enable_cache && !isset($package[512]);

        // 还没握手，先当 HTTP 解析，如果解析出来请求是握手请求，再说别的
        if (!$connection->context->ws_handshake) {
            return $this->dealWSHandshake($server, $package, $connection);
        }

        // 下面握手成功，开始处理数据
        if ($cache && isset($frames[$package])) {
            $frame = $frames[$package];
        } else {
            // 下面是已经握手后的解析，这里传入的 package 已经是包含一个半完整的 Frame 了，但有可能是个 FIN=0 的 Frame
            // 下面是 frame 数据长度还未知（0）的情况，需要我们先解析 Frame 的头
            $parsed = static::parseRawFrame(
                $package,
                $fin,
                $opcode,
                $masked,
                $head_len,
                $data_len,
                $mask_key
            );
            if (!$parsed) {
                Server::logDebug('Parse frame head failed, close the connection');
                $connection->close();
                return false;
            }

            // 数据本体（没解码的）
            $data_masked = substr($package, $head_len, $data_len);
            $data_decoded = static::maskData($mask_key, $data_masked);
            // 如果是 FIN=0，并且要求自动合并 Frame，那么我们就等包，然后合并
            if (!$fin && static::$auto_splicing_frame) {
                Server::logDebug('拼接中...[' . $data_decoded . ']');
                // 缓存 opcode
                $connection->context->opcode = $opcode;
                $connection->context->current_frame_length += strlen($data_decoded);
                $connection->context->current_frame_buffer .= $data_decoded;
                return true;
            }
            // 自动拼接开启，且收到了最后一个包，那么就给它拼起来
            if ($fin && static::$auto_splicing_frame && $connection->context->current_frame_length !== 0) {
                $frame = new Frame(
                    $connection->context->current_frame_buffer . $data_decoded,
                    $connection->context->opcode,
                    true,
                    true
                );
                $connection->context->opcode = Opcode::TEXT;
                $connection->context->current_frame_length = 0;
                $connection->context->current_frame_buffer = '';
            } else {
                $frame = new Frame($data_decoded, $opcode, true, (bool) $fin);
            }
            // 缓存一下
            if ($cache) {
                $frames[$package] = $frame;
                if (count($frames) > 512) {
                    unset($frames[key($frames)]);
                }
            }
        }
        switch ($frame->getOpcode()) {
            case Opcode::PING:
                if ($server->hasEventCallback('websocketping')) {
                    $server->emitEventCallback('websocketping', $connection, $frame);
                } else {
                    $connection->send(FrameFactory::createPongFrame()->getRaw());
                }
                break;
            case Opcode::PONG:
                $server->emitEventCallback('websocketpong', $connection, $frame);
                break;
            case Opcode::CONTINUATION:
            case Opcode::TEXT:
            case Opcode::BINARY:
                // 到这里就做好了一个 Frame，我们准备回调事件
                $server->emitEventCallback('message', $connection, $frame);
                break;
            case Opcode::CLOSE:
                $server->emitEventCallback('disconnect', $connection, $frame);
                $connection->close(FrameFactory::createCloseFrame());
                break;
        }
        return true;
    }

    public function getSocketAddress(): string
    {
        return 'tcp://' . $this->host . ':' . $this->port;
    }

    public function getTransport(): string
    {
        return 'tcp';
    }

    public function getBuiltinTransport(): string
    {
        return 'tcp';
    }

    public function getProtocolEvents(): array
    {
        return ['open', 'message', 'websocketping', 'websocketpong', 'disconnect', ...parent::getProtocolEvents()];
    }

    public function getProtocolName(): string
    {
        return $this->protocol_name;
    }

    public function makeContext(): object
    {
        return new WebSocketContext();
    }

    public function getConnectionClass(): string
    {
        return WsConnection::class;
    }

    /**
     * @param  SocketBase         $base       Socket 基类
     * @param  string             $package    客户端发来的数据
     * @param  Tcp                $connection 当前连接
     * @throws ProtocolException
     * @throws ValidatorException
     * @throws \Throwable
     * @return bool               是否握手成功
     */
    public function dealWSHandshake(SocketBase $base, string $package, Tcp $connection): bool
    {
        // 本地静态变量做缓存
        /** @var RequestInterface[] $requests */
        static $requests = [];

        // 先检查缓存
        $cache = static::$enable_cache && !isset($package[512]);
        if ($cache && isset($requests[$package])) {
            $request = $requests[$package];

            // 执行 HTTP Request 回调
            $base->emitEventCallback('request', $connection, $request);
            return true;
        }

        // 不是缓存，现在生成或辨别
        $request = static::parseRawRequest($package);
        // HTTP Header 部分 + 换行符的长度
        $header_length = strpos($package, "\r\n\r\n") + 4;

        // 如果是 WebSocket 握手请求，那么就执行握手
        if (
            $request->getMethod() === 'GET'
            && $request->getHeaderLine('Upgrade') !== ''
            && $request->getHeaderLine('Connection') !== ''
            && $request->getHeaderLine('Sec-WebSocket-Key') !== ''
            && $request->getHeaderLine('Sec-WebSocket-Version') !== ''
        ) {
            // Calculation websocket key.
            $new_key = \base64_encode(\sha1($request->getHeaderLine('Sec-WebSocket-Key') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

            // 制作一个特殊的 WS 握手回包
            $response = HttpFactory::createResponse(101, 'Switching Protocols', [
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Version' => '13',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $new_key,
            ]);

            // 初始化上下文依赖的东西
            $connection->context->current_frame_length = 0;
            $connection->context->current_frame_buffer = '';
            $connection->context->opcode = Opcode::TEXT;

            // 执行 onOpen 回调
            $base->emitEventCallback('open', $connection, $request);

            // 发送握手成功回包，并上下文标记握手成功
            /* @phpstan-ignore-next-line */
            $connection->send((string) $response);
            $connection->context->ws_handshake = true;

            // 发送没发送的临时数据,TODO
            if (!empty($connection->context->tmp_ws_data)) {
                $connection->send($connection->context->tmp_ws_data);
                $connection->context->tmp_ws_data = '';
            }

            // Buffer 比 Header 长，说明给粘一块了，把剩下的部分当作一次输入来解析
            if (strlen($package) > $header_length) {
                // TODO: 目前的架构写这部分好像比较麻烦，因为默认 GET 包不应该有内容传入
                // 如果发生粘包的情况，就先丢弃吧
            }
            return true;
        }

        // 其他普通 HTTP 请求，继续执行 onRequest
        $base->emitEventCallback('request', $connection, $request);
        // 缓存一下
        if ($cache) {
            $requests[$package] = $request;
            if (count($requests) > 512) {
                unset($requests[key($requests)]);
            }
        }
        return true;
    }
}
