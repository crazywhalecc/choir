<?php

declare(strict_types=1);

namespace Choir;

use Choir\EventLoop\EventHandler;
use Choir\EventLoop\EventInterface;
use Choir\Exception\ChoirException;
use Choir\Exception\NetworkException;
use Choir\Exception\ValidatorException;
use Choir\Protocol\ProtocolInterface;
use Choir\Protocol\Tcp;

/**
 * 监听端口 Server 或独立对象共有的成员和方法们
 */
trait SocketListenTrait
{
    /** @var ProtocolInterface 协议实例对象 */
    public ProtocolInterface $protocol;

    /** @var null|resource （内部）用于存储主要监听服务端口的资源 */
    protected $main_socket;

    /** @var string 运行时使用的用户名 */
    protected string $runtime_user = '';

    /** @var string 运行时使用的用户组 */
    protected string $runtime_group = '';

    /** @var int|resource Stream 的上下文 */
    protected $context;

    /** @var callable[] server 事件回调 */
    protected array $on_event = [];

    /** @var bool 是否暂停接收新的连接接入 */
    protected bool $pause_accept = true;

    /** @var array 支持的 on 回调事件列表 */
    private array $supported_events = [];

    /**
     * 设置事件回调
     *
     * @throws ChoirException
     */
    public function on(string $event, callable $callback): void
    {
        // 内部全部使用小写，便于存储
        $event = strtolower($event);
        if (!in_array($event, $this->supported_events)) {
            throw new ChoirException('Unsupported event name: ' . $event);
        }
        $this->{$event} = $callback;
    }

    /**
     * 返回是否设置了该回调
     *
     * @param string $event 事件名称（不区分大小写）
     */
    public function hasEventCallback(string $event): bool
    {
        return isset($this->{strtolower($event)});
    }

    /**
     * 调用回调（协程加持）
     *
     * @param  string     $name    回调事件名
     * @param  mixed      ...$args 传入的参数
     * @throws \Throwable
     */
    public function emitEventCallback(string $name, ...$args): void
    {
        static $fiber_stack = [];
        if (PHP_VERSION_ID >= 80100 && ($this->settings['enable-fiber'] ?? false)) {
            $fiber = new \Fiber(function ($name, ...$args) {
                isset($this->{$name}) && ($this->{$name})(...$args);
            });
            $fiber->start($name, ...$args);
            if (!$fiber->isTerminated()) {
                Server::logDebug('Fiber 可能挂起了');
                $fiber_stack[spl_object_id($fiber)] = $fiber;
            }
        } else {
            isset($this->{$name}) && ($this->{$name})(...$args);
        }
    }

    /**
     * [MASTER, WORKER] 监听端口
     *
     * @internal
     * @throws NetworkException
     */
    public function startListen(): void
    {
        if (!$this->main_socket) {
            $local_listen = $this->protocol->getSocketAddress();

            // flag 确定
            $flags = $this->protocol->getTransport() === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $err_no = 0;
            $err_msg = '';

            // 检查是否开启了 reuse port
            if ($this->settings['reuse-port'] ?? false) {
                stream_context_set_option($this->context, 'socket', 'so_reuseport', 1);
            }

            // 创建 socket
            $this->main_socket = stream_socket_server($local_listen, $err_no, $err_msg, $flags, $this->context);
            if (!$this->main_socket) {
                throw new NetworkException($err_msg);
            }

            if ($this->protocol->getTransport() === 'ssl') {
                // 开启 ssl
                stream_socket_enable_crypto($this->main_socket, false);
            } elseif ($this->protocol->getTransport() === 'unix') {
                // 使用 unix socket 进行通信
                $socket_file = substr($local_listen, 7);
                if ($this->runtime_user) {
                    chown($socket_file, $this->runtime_user);
                }
                if ($this->runtime_group) {
                    chgrp($socket_file, $this->runtime_group);
                }
            }

            // 持久 TCP 链接，关闭 Nagle 算法
            if (\extension_loaded('sockets') && $this->protocol->getBuiltinTransport() === 'tcp') {
                \set_error_handler(function () {
                });
                $socket = \socket_import_stream($this->main_socket);
                \socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($socket, \SOL_TCP, \TCP_NODELAY, 1);
                \restore_error_handler();
            }

            // 非阻塞模式
            \stream_set_blocking($this->main_socket, false);
        }

        $this->resumeAccept();
    }

    /**
     * [WORKER] 恢复或开始接收新的连接, TODO UDP
     *
     * @internal
     */
    public function resumeAccept(): void
    {
        // Register a listener to be notified when server socket is ready to read.
        if (EventHandler::$event instanceof EventInterface && $this->pause_accept && $this->main_socket) {
            switch ($this->protocol->getTransport()) {
                case 'tcp':
                case 'unix':
                case 'ssl':
                    EventHandler::$event->onReadable($this->main_socket, [$this, 'acceptTcpConnection']);
                    break;
                case 'udp':
                    EventHandler::$event->onReadable($this->main_socket, 'udp');
                    break;
            }
            $this->pause_accept = false;
        }
    }

    /**
     * [WORKER] 取消监听 Socket
     *
     * @internal
     */
    public function stopListen(): void
    {
        $this->pauseAccept();
        if ($this->main_socket) {
            set_error_handler(function () {
            });
            fclose($this->main_socket);
            restore_error_handler();
            $this->main_socket = null;
        }
    }

    /**
     * 暂停接收新的连接进入
     *
     * @internal
     */
    public function pauseAccept(): void
    {
        if (EventHandler::$event instanceof EventInterface && !$this->pause_accept && $this->main_socket) {
            EventHandler::$event->offReadable($this->main_socket);
            $this->pause_accept = true;
        }
    }

    /**
     * [WORKER, LOOP] 接入新的 TCP 连接
     *
     * @param  resource                   $socket 连接 Socket 资源
     * @throws Exception\MonitorException
     */
    public function acceptTcpConnection($socket): void
    {
        // 接受一个连接
        set_error_handler(function () {});
        $new_socket = stream_socket_accept($socket, 0, $remote_address);
        restore_error_handler();
        if (!$new_socket) {
            return;
        }

        $proto_conn = $this->protocol->getConnectionClass();

        // 声明连接对象
        $tcp = new $proto_conn($new_socket, $remote_address, $this);
        Tcp::$connections[$tcp->getId()] = $tcp;

        // 调用 onConnect 回调
        try {
            $this->emitEventCallback('connect', $this, $tcp);
        } catch (\Throwable $e) {
            Server::logError(choir_exception_as_string($e));
            $tcp->close();
        }
    }

    /**
     * @throws ChoirException
     * @throws Exception\ValidatorException
     */
    protected function initSocketListen(): void
    {
        // 验证协议字符串
        $parse = parse_url($this->protocol_name);
        // 验证是否存在 scheme，host，port 三个参数
        if (!isset($parse['scheme'], $parse['host'], $parse['port'])) throw new ValidatorException('protocol string is invalid');
        // 验证协议是否支持
        if (!isset(Server::$supported_protocol[strtolower($parse['scheme'])])) throw new ValidatorException("protocol '{$parse['scheme']}' is not supported yet");

        // 通过协议声明初始协议操作对象
        $this->protocol = new (Server::$supported_protocol[$parse['scheme']])($parse['host'], $parse['port'], $this->protocol_name);
        // 协议声明后，获取协议支持的回调列表
        $this->supported_events = $this->getSupportedEvents();

        // 配置最大连接等待数量，默认102400
        if (!is_int($this->settings['context-option']['socket']['backlog'] ?? null)) {
            $this->settings['context-option']['socket']['backlog'] = 102400;
        }

        // 声明 context
        $this->context = stream_context_create($this->settings['context-option']);
    }

    /**
     * 获取支持的 on 事件回调名称列表
     *
     * @throws ChoirException
     */
    private function getSupportedEvents(): array
    {
        $base = ['workerstart', 'dhutdown', 'workerstop'];
        // 传输层（Layer 4）协议支持的列表
        $builtin = $this->protocol->getBuiltinTransport();
        switch ($builtin) {
            case 'tcp':
            case 'unix':
                $base = [...$base, 'connect', 'receive', 'close', 'tcperror', 'bufferfull', 'bufferdrain'];
                break;
            case 'udp':
                $base = [...$base, 'packet'];
                break;
            default:
                throw new ChoirException('Unsupported transport layer type');
        }

        // 和应用层支持的事件列表合并返回
        return [...$base, ...$this->protocol->getProtocolEvents()];
    }
}
