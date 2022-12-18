<?php

declare(strict_types=1);

namespace Choir\Http\Client;

use Choir\Coroutine\Runtime;
use Choir\EventLoop\EventHandler;
use Choir\EventLoop\EventInterface;
use Choir\Exception\ChoirException;
use Choir\Exception\ValidatorException;
use Choir\Http\Client\Exception\NetworkException;
use Choir\Http\Client\Exception\RequestException;
use Choir\Http\HttpFactory;
use Choir\TcpClient;
use Choir\Timer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class AsyncStreamClient extends TcpClient implements TimeoutInterface, UpgradableClientInterface, AsyncClientInterface
{
    /** @var null|callable WS 回调函数 */
    protected $on_message;

    /** @var null|callable 错误的回调函数 */
    protected $on_close;

    /**
     * Constructor.
     * @throws ValidatorException
     */
    public function __construct(array $settings = [])
    {
        parent::__construct('', $settings);
        // 设置超时
        $this->settings['timeout'] = $settings['timeout'] ?? 1000;
        // 设置事件循环
        if (($this->settings['event-loop'] ?? null) === null || !($this->settings['event-loop'] instanceof EventInterface)) {
            $this->settings['event-loop'] = EventHandler::$event;
        }
        // 设置协程
        if (Runtime::getImpl() !== null) {
            $this->settings['coroutine'] = Runtime::getCid() !== -1;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setTimeout(int $timeout)
    {
        $this->settings['timeout'] = $timeout;
    }

    /**
     * @throws ChoirException
     * @throws \Throwable
     */
    public function sendRequestAsync(RequestInterface $request, callable $success_callback, callable $error_callback): bool
    {
        if (!$request->hasHeader('Connection')) {
            $request = $request->withHeader('Connection', 'close');
        }
        $have_port = $request->getUri()->getPort();
        $this->setProtocolName($request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($have_port !== null ? (':' . $have_port) : ''));
        $this->on('response', $success_callback);

        // 设置超时的计时器
        if ($this->settings['timeout']) {
            /* @var EventInterface $loop */
            Timer::add($this->settings['timeout'] / 1000, function () use ($error_callback) {
                if (isset($this->tcp_connection)) {
                    $this->tcp_connection->setStatus(CHOIR_TCP_CLOSED);
                    $error_callback($this->tcp_connection);
                    $this->tcp_connection->destroyConnection();
                    $this->tcp_connection = null;
                }
            }, [], false);
        }
        try {
            // 连接失败，调用 error 回调
            if (!$this->connect()) {
                call_user_func($error_callback, $this->tcp_connection);
                $this->tcp_connection->destroyConnection();
                $this->tcp_connection = null;
                return false;
            }
            // 连接上后异步发送请求
            $this->on('connect', function () use ($request) {
                $this->tcp_connection->send($this->transformRequestHeadersToString($request));
                $this->tcp_connection->send($request->getBody());
            });
            // 发送失败或连接失败时也回调失败处理
            $this->on('connecterror', function () use ($error_callback) {
                call_user_func($error_callback, $this->tcp_connection);
                $this->tcp_connection->destroyConnection();
                $this->tcp_connection = null;
            });
            return true;
        } catch (\Throwable $e) {
            // 抛出异常后也回调失败处理
            call_user_func($error_callback, $this->tcp_connection);
            $this->tcp_connection->destroyConnection();
            $this->tcp_connection = null;
            return false;
        }
    }

    public function getStatus(): int
    {
        return $this->tcp_connection->getStatus();
    }

    public function send($frame): bool
    {
        return false;
    }

    public function onMessage(callable $callback)
    {
        $this->on('message', $callback);
    }

    public function onClose(callable $callback)
    {
        $this->on('close', $callback);
    }

    /**
     * 发起一个 HTTP 请求，将 HTTP 请求升级为 WebSocket
     *
     * @param  UriInterface $uri       URI 地址对象
     * @param  array        $headers   请求带的头
     * @param  bool         $reconnect 连接失败是否自动重新连接
     * @throws \Throwable
     * @return bool         返回连接成功与否
     */
    public function upgrade(UriInterface $uri, array $headers = [], bool $reconnect = false): bool
    {
        // 通过 Uri 对象构建 RequestInterface 对象
        $request = HttpFactory::createRequest('GET', $uri, $headers);
        // TODO：异步连接还是同步连接呢

        return true;
    }

    /**
     * Return remote socket from the request.
     *
     * @throws RequestException
     */
    public function determineRemoteFromRequest(RequestInterface $request): string
    {
        if (!$request->hasHeader('Host') && $request->getUri()->getHost() === '') {
            throw new RequestException($request, 'Remote is not defined and we cannot determine a connection endpoint for this request (no Host header)');
        }

        $endpoint = '';

        $host = $request->getUri()->getHost();
        if (!empty($host)) {
            $endpoint .= $host;
            if ($request->getUri()->getPort() !== null) {
                $endpoint .= ':' . $request->getUri()->getPort();
            } elseif ($request->getUri()->getScheme() === 'https') {
                $endpoint .= ':443';
            } else {
                $endpoint .= ':80';
            }
        }

        // If use the host header if present for the endpoint
        if (empty($host) && $request->hasHeader('Host')) {
            $endpoint = $request->getHeaderLine('Host');
        }

        return sprintf('tcp://%s', $endpoint);
    }

    /**
     * 此 Client 实例的 onResponse 回调用于处理异步收到 Response 后的处理
     * onConnectError 负责处理 TCP 层面连接失败时候的处理
     */
    protected function getSupportedEvents(): array
    {
        return ['response', 'connecterror', ...parent::getSupportedEvents()];
    }

    /**
     * 转换 Header 为字符串
     * Produce the header of request as a string based on a PSR Request.
     */
    protected function transformRequestHeadersToString(RequestInterface $request): string
    {
        $message = vsprintf('%s %s HTTP/%s', [
            strtoupper($request->getMethod()),
            $request->getRequestTarget(),
            $request->getProtocolVersion(),
        ]) . "\r\n";

        foreach ($request->getHeaders() as $name => $values) {
            $message .= $name . ': ' . implode(', ', $values) . "\r\n";
        }

        $message .= "\r\n";

        return $message;
    }

    /**
     * Write Body of the request.
     *
     * @param  resource         $socket
     * @throws NetworkException
     */
    protected function writeBody($socket, RequestInterface $request, int $bufferSize = 8192): void
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $buffer = $body->read($bufferSize);

            if ($this->fwrite($socket, $buffer) === false) {
                throw new NetworkException($request, 'An error occur when writing request to client (BROKEN EPIPE)');
            }
        }
    }

    /**
     * Replace fwrite behavior as api is broken in PHP.
     *
     * @see https://secure.phabricator.com/rPHU69490c53c9c2ef2002bc2dd4cecfe9a4b080b497
     *
     * @param resource $stream The stream resource
     *
     * @return bool|int false if pipe is broken, number of bytes written otherwise
     */
    private function fwrite($stream, string $bytes)
    {
        if (empty($bytes)) {
            return 0;
        }
        $result = @fwrite($stream, $bytes);
        if ($result !== 0) {
            // In cases where some bytes are witten (`$result > 0`) or
            // an error occurs (`$result === false`), the behavior of fwrite() is
            // correct. We can return the value as-is.
            return $result;
        }
        // If we make it here, we performed a 0-length write. Try to distinguish
        // between EAGAIN and EPIPE. To do this, we're going to `stream_select()`
        // the stream, write to it again if PHP claims that it's writable, and
        // consider the pipe broken if the write fails.
        $read = [];
        $write = [$stream];
        $except = [];
        $ss = @stream_select($read, $write, $except, 0);
        // 这里做了个修改，原来下面是 !$write，但静态分析出来它是永久的false，所以改成了 !$ss
        if (!$ss) {
            // The stream isn't writable, so we conclude that it probably really is
            // blocked and the underlying error was EAGAIN. Return 0 to indicate that
            // no data could be written yet.
            return 0;
        }
        // If we make it here, PHP **just** claimed that this stream is writable, so
        // perform a write. If the write also fails, conclude that these failures are
        // EPIPE or some other permanent failure.
        $result = @fwrite($stream, $bytes);
        if ($result !== 0) {
            // The write worked or failed explicitly. This value is fine to return.
            return $result;
        }
        // We performed a 0-length write, were told that the stream was writable, and
        // then immediately performed another 0-length write. Conclude that the pipe
        // is broken and return `false`.
        return false;
    }
}
