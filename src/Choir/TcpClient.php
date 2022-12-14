<?php

declare(strict_types=1);

namespace Choir;

use Choir\Coroutine\Runtime;
use Choir\EventLoop\EventHandler;
use Choir\EventLoop\Select;
use Choir\Exception\ValidatorException;
use Choir\Protocol\HttpProtocol;
use Choir\Protocol\HttpsProtocol;
use Choir\Protocol\RawTcpProtocol;
use Choir\Protocol\Tcp;
use Choir\Protocol\TextProtocol;
use Choir\Protocol\WebSocketProtocol;

class TcpClient extends SocketBase
{
    /** @var array|string[] 支持的协议，默认支持以下协议，可修改该变量插入或去掉支持的协议解析 */
    public static array $supported_protocol = [
        'ws' => WebSocketProtocol::class,
        'tcp' => RawTcpProtocol::class,
        'text' => TextProtocol::class,
        'http' => HttpProtocol::class,
        'https' => HttpsProtocol::class,
    ];

    protected bool $async;

    protected int $suspend_cid = -1;

    protected int $timeout_timer = -1;

    protected ?Tcp $tcp_connection = null;

    /**
     * @throws ValidatorException
     */
    public function __construct(string $protocol_name = '', array $settings = [], bool $async = true)
    {
        $this->protocol_name = $protocol_name;
        $this->settings = $settings;
        $this->async = $async;
        if ($this->protocol_name !== '') {
            $this->initProtocol();
        }
    }

    /**
     * @throws ValidatorException
     */
    public function setProtocolName(string $protocol_name) {
        $this->protocol_name = $protocol_name;
        $this->initProtocol();
    }

    public function connect(): bool
    {
        if (!empty($this->settings['context-option-params'] ?? [])) {
            $context_options = stream_context_create($this->settings['context-option'] ?? [], $this->settings['context-option-params']);
        } else {
            $context_options = stream_context_create($this->settings['context-option'] ?? []);
        }
        $socket = stream_socket_client($this->protocol->getSocketAddress(), $error_code, $error_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context_options);
        $this->tcp_connection = new ($this->protocol->getConnectionClass())($socket, $this->protocol->getSocketAddress(), $this);
        $this->tcp_connection->asClient(true);
        $this->tcp_connection->setStatus(CHOIR_TCP_CONNECTING);

        // 检查 socket
        if (!$socket || !is_resource($socket)) {
            return false;
        }

        EventHandler::$event->onWritable($socket, [$this, 'checkConnection']);
        if (PHP_OS_FAMILY === 'Windows' && EventHandler::$event instanceof Select) {
            EventHandler::$event->onExcept($socket, [$this, 'checkConnection']);
        }
        if (Runtime::getImpl() !== null) {
            Server::logDebug('使用协程返回真正的连接成功或失败');
            $this->suspend_cid = $cid = Runtime::getCid();
            $this->timeout_timer = Timer::add(5, function () use ($cid) {
                if (Runtime::getImpl()->exists($cid)) {
                    Runtime::getImpl()->resume($cid, false);
                }
            }, [], false);
            // echo 'I suspendeed in !' . Runtime::getCid() . "\n";
            return (bool) Runtime::getImpl()->suspend();
        }
        return true;
    }

    /**
     * @noinspection PhpComposerExtensionStubsInspection
     * @param  mixed      $socket
     * @throws \Throwable
     */
    public function checkConnection($socket)
    {
        if (PHP_OS_FAMILY === 'Windows' && method_exists(EventHandler::$event, 'offExcept')) {
            EventHandler::$event->offExcept($socket);
        }

        EventHandler::$event->offWritable($socket);

        if ($this->tcp_connection->getStatus() !== CHOIR_TCP_CONNECTING) {
            return;
        }

        // 检查 Socket 状态
        if ($address = stream_socket_get_name($socket, true)) {
            // 异步。TODO：今后考虑同步
            stream_set_blocking($socket, false);
            // 兼容 HHVM
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
            // 让连接 Keep Alive
            if (\function_exists('socket_import_stream') && $this->protocol->getTransport() === 'tcp') {
                $raw_socket = \socket_import_stream($socket);
                \socket_set_option($raw_socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($raw_socket, \SOL_TCP, \TCP_NODELAY, 1);
            }

            // SSL 握手
            if ($this->protocol->getTransport() === 'ssl') {
                $complete = $this->tcp_connection->handshakeSsl($socket, true);
                if ($complete === false) {
                    return;
                }
            } else {
                if (!$this->tcp_connection->isSendBufferEmpty()) {
                    EventHandler::$event->onWritable($socket, [$this->tcp_connection, 'onWriteConnection']);
                }
            }

            EventHandler::$event->onReadable($socket, [$this->tcp_connection, 'onReadConnection']);

            $this->tcp_connection->setStatus(CHOIR_TCP_ESTABLISHED);
            $this->tcp_connection->setRemoteAddress($address);

            // 调用回调
            $this->emitEventCallback('connect', $this->tcp_connection);
            if ($this->suspend_cid !== -1) {
                Runtime::getImpl()->resume($this->suspend_cid, true);
                $this->suspend_cid = -1;
            }
            return;
        }

        if ($this->suspend_cid !== -1) {
            // echo '['.gettype($address) . ']Resuming ' . $this->suspend_cid . PHP_EOL;
            Runtime::getImpl()->resume($this->suspend_cid, false);
            if ($this->timeout_timer !== -1) {
                EventHandler::$event->deleteTimer($this->timeout_timer);
            }
        } else {
            $this->tcp_connection->close();
            // 连接失败，调用失败
            $this->emitEventCallback('connecterror', $this);
        }
    }

    protected function initProtocol()
    {
        // 验证协议字符串
        $parse = parse_url($this->protocol_name);
        // 验证是否存在 scheme，host，port 三个参数
        if (!isset($parse['scheme'], $parse['host'])) throw new ValidatorException('protocol string is invalid');
        // 验证协议是否支持
        if (!isset(static::$supported_protocol[strtolower($parse['scheme'])])) throw new ValidatorException("protocol '{$parse['scheme']}' is not supported yet");

        // 通过协议声明
        $this->protocol = new (static::$supported_protocol[$parse['scheme']])($parse['host'], $parse['port'] ?? null, $this->protocol_name);
    }

    protected function getSupportedEvents(): array
    {
        return ['connect', 'receive', 'close', 'tcperror', 'bufferfull', 'bufferdrain'];
    }
}
