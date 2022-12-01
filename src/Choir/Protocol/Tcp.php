<?php

/** @noinspection PhpMissingFieldTypeInspection */

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\EventLoop\EventHandler;
use Choir\Monitor\ConnectionMonitor;
use Choir\Protocol\Context\DefaultContext;
use Choir\Protocol\Context\WebSocketContext;
use Choir\Server;
use Choir\SocketBase;
use Psr\Http\Message\StreamInterface;

/**
 * TCP 协议连接基类
 */
class Tcp implements ConnectionInterface
{
    /** @var Tcp[] 连接对象数组 */
    public static array $connections = [];

    /** @var DefaultContext|object|WebSocketContext 上下文对象 */
    public $context;

    /** @var resource 套接字 */
    protected $socket;

    /** @var string 客户端地址 */
    protected string $remote_address;

    /** @var SocketBase 监听端口的主体，可以是监听端口对象，也可以是 Server 对象 */
    protected $base;

    /** @var int 连接 ID 自增计数器 */
    private static int $id_counter = 0;

    /** @var int TCP 连接 ID（多个进程可能重复，但单个进程的是唯一的） */
    private int $id;

    /** @var int TCP 连接状态 */
    private int $status = CHOIR_TCP_ESTABLISHED;

    /** @var int 设置该连接下最大接收的 Buffer 大小，如果 Buffer 满了，会触发 bufferFull 回调 */
    private int $max_send_buffer_size;

    /** @var int 设置该连接下最大的 package 大小 */
    private int $max_package_size;

    /** @var bool SSL 握手是否完成 */
    private bool $ssl_handshake_completed = false;

    /** @var string 发送的 Buffer */
    private string $send_buffer = '';

    /** @var string 接收的 Buffer */
    private string $recv_buffer = '';

    /** @var int 当前 package 的大小 */
    private int $current_package_length = 0;

    /** @var bool 是否暂停通信 */
    private bool $is_paused = false;

    /** @var int 写入的 Buffer 字节长度 */
    private int $bytes_written = 0;

    /** @var bool 是否是 client 模式 */
    private bool $client_mode = false;

    final public function __construct($socket, string $remote_address, $base)
    {
        $this->base = $base;
        $this->remote_address = $remote_address;
        $this->socket = $socket;
        $this->id = self::$id_counter++;
        // 超出最大数，则归零
        if (self::$id_counter === PHP_INT_MAX) {
            self::$id_counter = 0;
        }

        // 设置非阻塞
        stream_set_blocking($this->socket, false);

        // 兼容 HHVM（废弃？）
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->socket, 0);
        }

        // 加入全球化的 EventLoop 异步读取
        Server::logDebug('添加异步读取 on ' . get_class($base));
        EventHandler::$event->onReadable($this->socket, [$this, 'onReadConnection']);

        // 设置缓存区和包大小，默认 1MB 和 10MB
        $this->max_send_buffer_size = intval($this->base->settings['max-send-buffer-size'] ?? 1048576);
        $this->max_package_size = intval($this->base->settings['max-package-size'] ?? 10485760);

        // 构建新的上下文对象
        $this->context = $this->base->protocol->makeContext();
    }

    public function asClient(bool $client_mode)
    {
        $this->client_mode = $client_mode;
    }

    /**
     * [WORKER, LOOP] 从建立的 TCP 连接中读取内容
     *
     * @param  resource   $socket    可读的资源
     * @param  bool       $check_eof 是否检查 EOF，默认为 True
     * @throws \Throwable
     */
    public function onReadConnection($socket, bool $check_eof = true): void
    {
        // SSL 握手
        if ($this->base->protocol->getTransport() === 'ssl' && $this->ssl_handshake_completed !== true) {
            if ($this->handshakeSsl($socket, $this->client_mode)) {
                $this->ssl_handshake_completed = true;
                if ($this->send_buffer) {
                    EventHandler::$event->onWritable($socket, [$this, 'onWriteConnection']);
                }
            } else {
                return;
            }
        }

        // 开读
        $buffer = '';
        try {
            $buffer = @fread($socket, defined('CHOIR_TCP_READ_BUFFER_SIZE') ? CHOIR_TCP_READ_BUFFER_SIZE : 87380);
        } catch (\Throwable $e) {
        }

        // 读取失败，关闭连接
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroyConnection();
                return;
            }
        } else {
            $this->base->emitEventCallback('receive', $this, $buffer);
            // 读取成功？
            // $this->bytes_read += strlen($buffer);
            $this->recv_buffer .= $buffer;
        }

        // 通过应用层，寻找包长度
        while ($this->recv_buffer !== '' && !$this->is_paused) {
            // 首先验证下包是否拿到了所需的长度，不对的话需要继续读

            if (($len = strlen($this->recv_buffer)) < $this->current_package_length) {
                break;
            }

            // 包长度需要传入协议来获取，如果0的话，说明还不知道当前package的长度，需要获取下
            if ($this->current_package_length === 0) {
                try {
                    $this->current_package_length = $this->base->protocol->checkPackageLength($this->recv_buffer, $this);
                } catch (\Throwable $e) {
                }
                // 协议也没能解析出来，那么这个包不管它，继续等待传入，并拼接
                if ($this->current_package_length === 0) {
                    break;
                }
                // 包出错了，这种情况一般可能是协议返回需要的包长度有问题，比如返回了负数或者过大的一个包（超过了 Choir 支持的最大 Package 尺寸）
                if ($this->current_package_length < 0 || $this->current_package_length > $this->max_package_size) {
                    Server::logDebug('Wrong package, length=' . $this->current_package_length);
                    $this->destroyConnection();
                    break;
                }
                // 包还没接满，继续接收
                if ($len < $this->current_package_length) {
                    break;
                }
            }

            // 当前 package 长度如果已知，那么就直接进入传入协议解包环节

            // 到这里表明协议可以解包了

            // 当前包长度等于 buffer 长度，直接解
            if ($len === $this->current_package_length) {
                $once_buffer = $this->recv_buffer;
                $this->recv_buffer = '';
            } else {
                // buffer 长度不相等，那么就根据协议要的包长读取
                $once_buffer = substr($this->recv_buffer, 0, $this->current_package_length);
                // 剩下的 Buffer 继续留在 Buffer 里
                $this->recv_buffer = substr($this->recv_buffer, $this->current_package_length);
            }

            // 重置 Package 长度，等待下一个 Package 接收
            $this->current_package_length = 0;
            try {
                // 解析一个 package，调用协议解析函数，传入内容，回调由此处协议进行处理，TCP 层不做处理
                $this->base->protocol->execute($this->base, $once_buffer, $this);
            } catch (\Throwable $e) {
                Server::logError(choir_exception_as_string($e));
            }
        }
    }

    /**
     * @param  mixed      $socket
     * @throws \Throwable
     */
    public function onWriteConnection($socket)
    {
        set_error_handler(function () {
        });
        if ($this->base->protocol->getTransport() === 'ssl') {
            $len = @\fwrite($socket, $this->send_buffer, 8192);
        } else {
            $len = @\fwrite($socket, $this->send_buffer);
        }
        restore_error_handler();

        if ($len === strlen($this->send_buffer)) {
            $this->bytes_written += $len;
            EventHandler::$event->offWritable($socket);
            $this->send_buffer = '';
            // Try to emit onBufferDrain callback when the send buffer becomes empty.
            Server::getInstance()->emitEventCallback('bufferdrain', $this);
            if ($this->status === CHOIR_TCP_CLOSING) {
                $this->destroyConnection();
            }
            return;
        }
        if ($len > 0) {
            $this->bytes_written += $len;
            $this->send_buffer = \substr($this->send_buffer, $len);
        } else {
            ConnectionMonitor::addTcpFailCount('send');
            $this->destroyConnection();
        }
    }

    /**
     * @param  null|mixed $data
     * @throws \Throwable
     */
    public function close($data = null)
    {
        // 正在连接的话，直接销毁连接
        if ($this->status === CHOIR_TCP_CONNECTING) {
            $this->destroyConnection();
            return;
        }

        // 关闭中或已关闭，则忽略再次关闭
        if ($this->status === CHOIR_TCP_CLOSING || $this->status === CHOIR_TCP_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data);
        }

        $this->status = CHOIR_TCP_CLOSING;

        if ($this->send_buffer === '') {
            $this->destroyConnection();
        } else {
            EventHandler::$event->offReadable($this->socket);
            $this->is_paused = true;
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return object|\stdClass
     */
    public function getContext()
    {
        return $this->context;
    }

    public function getRemoteAddress(): string
    {
        return $this->remote_address;
    }

    /**
     * 向 TCP 客户端发送数据
     *
     * @param  StreamInterface|string $buffer 内容
     * @throws \Throwable
     */
    public function send($buffer): ?bool
    {
        // 状态不对，对不起，发不了
        if ($this->status === CHOIR_TCP_CLOSING || $this->status === CHOIR_TCP_CLOSED) {
            return false;
        }
        if ($buffer instanceof StreamInterface) {
            $buffer->rewind();
            $buffer = $buffer->getContents();
        }

        // 不是建立连接的状态，或者是 ssl 模式但没完成握手，就加buffer
        if ($this->status !== CHOIR_TCP_ESTABLISHED || $this->base->protocol->getTransport() === 'ssl' && !$this->ssl_handshake_completed) {
            if ($this->send_buffer && $this->isBufferFull()) {
                // 增加失败计数
                ConnectionMonitor::addTcpFailCount('send');
                return false;
            }

            // 写入 buffer，并验证 Buffer 是否满了
            $this->send_buffer .= $buffer;
            $this->checkBufferFull();
            return null;
        }

        // 尝试直接发送数据
        if ($this->send_buffer === '') {
            // ssl 走 EventLoop
            if ($this->base->protocol->getTransport() === 'ssl') {
                EventHandler::$event->onWritable($this->socket, [$this, 'onWriteConnection']);
                $this->send_buffer = $buffer;
                $this->checkBufferFull();
                return null;
            }
            $len = 0;
            try {
                $len = @\fwrite($this->socket, $buffer);
            } catch (\Throwable $e) {
                Server::logError(choir_exception_as_string($e));
            }
            // 相等，就是发送成功
            if ($len === strlen($buffer)) {
                $this->bytes_written += $len;
                return true;
            }
            // 不相等，说明只发送了一部分
            if ($len > 0) {
                $this->send_buffer = substr($buffer, $len);
                $this->bytes_written += $len;
            } else {
                // 判断是不是连接断了
                if (!is_resource($this->socket) || feof($this->socket)) {
                    // 连接断了就计数
                    ConnectionMonitor::addTcpFailCount('send');
                    Server::getInstance()->emitEventCallback('tcperror', CHOIR_TCP_SEND_FAILED, 'client closed');
                    $this->destroyConnection();
                    return false;
                }
                $this->send_buffer = $buffer;
            }

            // EventLoop 异步读写
            EventHandler::$event->onWritable($this->socket, [$this, 'onWriteConnection']);
            // 检查 Buffer
            $this->checkBufferFull();
        }

        // buffer 满了，就 fail
        if ($this->isBufferFull()) {
            ConnectionMonitor::addTcpFailCount('send');
            return false;
        }

        $this->send_buffer .= $buffer;
        $this->checkBufferFull();
        return null;
    }

    public function getMaxPackageSize(): int
    {
        return $this->max_package_size;
    }

    /**
     * SSL 握手
     *
     * @param  resource   $socket socket 资源
     * @param  bool       $client 是否为客户端，默认为服务端握手
     * @throws \Throwable
     * @return bool|int
     */
    public function handshakeSsl($socket, bool $client = false)
    {
        // 连接到尽头了~~
        if (feof($socket)) {
            $this->destroyConnection();
            return false;
        }

        $type = $client ? (STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT) : (STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER);

        // 隐藏报错
        set_error_handler(function ($errno, $errstr) {
            if (!(Server::getInstance()->settings['daemon'] ?? false)) {
                Server::logError("SSL handshake error: {$errstr}");
            }
        });
        $ret = stream_socket_enable_crypto($socket, true, $type);
        restore_error_handler();

        // 认证失败
        if ($ret === false) {
            $this->destroyConnection();
            return false;
        }
        // 没有提供足够的信息，需要重新握手
        if ($ret === 0) {
            return 0;
        }
        // 调用握手回调
        Server::getInstance()->emitEventCallback('sslhandshake', $this);

        return true;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function isSendBufferEmpty(): bool
    {
        return $this->send_buffer === '';
    }

    /**
     * 销毁 TCP 连接
     *
     * @throws \Throwable
     */
    public function destroyConnection(): void
    {
        Server::logDebug('destroying connection #' . $this->id);
        // 已关闭就不管了
        if ($this->status === CHOIR_TCP_CLOSED) {
            return;
        }

        // 从 EventLoop 移除
        EventHandler::$event->offReadable($this->socket);
        EventHandler::$event->offWritable($this->socket);

        // 关闭 socket
        try {
            @fclose($this->socket);
        } catch (\Throwable $e) {
        }

        $this->status = CHOIR_TCP_CLOSED;

        // 调用回调
        $this->base->emitEventCallback('close', $this);

        // 重置
        $this->send_buffer = $this->recv_buffer = '';
        $this->current_package_length = 0;
        $this->is_paused = $this->ssl_handshake_completed = false;

        // 确保回调没有把状态改了
        if ($this->status === CHOIR_TCP_CLOSED) {
            // 防止内存泄漏
            // TODO: Workerman 记录了，但 Choir 不需要，所以是不是就不写了？
            unset(static::$connections[$this->getId()]);
        }
    }

    public function setRemoteAddress(string $address)
    {
        $this->remote_address = $address;
    }

    public function isClientMode(): bool
    {
        return $this->client_mode;
    }

    /**
     * 判断 Buffer 是否已满
     * 调用这个的前提是，buffer 满了，我还想写入，然后就触发问题
     *
     * @throws \Throwable
     */
    private function isBufferFull(): bool
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->max_send_buffer_size <= strlen($this->send_buffer)) {
            Server::getInstance()->emitEventCallback('tcperror', $this, CHOIR_TCP_SEND_FAILED, 'Send Buffer is full');
            return true;
        }
        return false;
    }

    /**
     * 检查和调用 BufferFull 回调
     * 在将内容塞入 Buffer 后调用，如果塞入后发现满了，就调用，此时不一定是有错误
     *
     * @throws \Throwable
     */
    private function checkBufferFull(): void
    {
        if ($this->max_send_buffer_size <= strlen($this->send_buffer)) {
            Server::getInstance()->emitEventCallback('bufferfull', $this);
        }
    }
}
