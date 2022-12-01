<?php

declare(strict_types=1);

namespace Choir\Http\Client;

use Choir\Http\Client\Exception\ClientException;
use Choir\Http\Client\Exception\NetworkException;
use Choir\Http\HttpFactory;
use Choir\WebSocket\FrameFactory;
use Choir\WebSocket\FrameInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

/**
 * Swoole HTTP Client based on PSR-18.
 */
class SwooleClient extends ClientBase implements ClientInterface, AsyncClientInterface, UpgradableClientInterface
{
    protected ?Client $client = null;

    protected int $status = CHOIR_TCP_INITIAL;

    protected $on_message;

    protected $on_close;

    private array $set = [];

    /**
     * @throws ClientException
     */
    public function __construct(array $set = [])
    {
        if (Coroutine::getCid() === -1) {
            throw new ClientException('API must be called in the coroutine');
        }
        $this->withSwooleSet($set);
    }

    public function withSwooleSet(array $set = []): SwooleClient
    {
        if (!empty($set)) {
            $this->set = $set;
        }
        return $this;
    }

    public function setTimeout(int $timeout)
    {
        $this->set['timeout'] = $timeout / 1000;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->client = $client = $this->buildBaseClient($request);
        if ($client->errCode !== 0) {
            throw new NetworkException($request, $client->errMsg, $client->errCode);
        }
        return HttpFactory::createResponse($client->statusCode, null, $client->getHeaders(), $client->getBody());
    }

    public function sendRequestAsync(RequestInterface $request, callable $success_callback, callable $error_callback)
    {
        go(function () use ($request, $success_callback, $error_callback) {
            $this->client = $client = $this->buildBaseClient($request);
            if ($client->errCode !== 0) {
                call_user_func($error_callback, $request);
            } else {
                $response = HttpFactory::createResponse($client->statusCode, null, $client->getHeaders(), $client->getBody());
                call_user_func($success_callback, $response);
            }
        });
    }

    public function buildBaseClient(RequestInterface $request): Client
    {
        $uri = $request->getUri();
        $client = new Client($uri->getHost(), $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80), $uri->getScheme() === 'https');
        // 设置 Swoole 专有的 set 参数
        $client->set($this->set);
        // 设置 HTTP Method （POST、GET 等）
        $client->setMethod($request->getMethod());
        // 设置 HTTP Headers
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $client->setHeaders($headers);
        // 如果是 POST 带 body，则设置 body
        if (($data = $request->getBody()->getContents()) !== '') {
            $client->setData($data);
        }
        $uri = $request->getUri()->getPath();
        if ($uri === '') {
            $uri = '/';
        }
        if (($query = $request->getUri()->getQuery()) !== '') {
            $uri .= '?' . $query;
        }
        if (($fragment = $request->getUri()->getFragment()) !== '') {
            $uri .= '?' . $fragment;
        }
        $client->execute($uri);
        return $client;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function send($frame): bool
    {
        $swoole_frame = new Frame();
        if ($frame instanceof FrameInterface) {
            $swoole_frame->data = $frame->getData();
            $swoole_frame->opcode = $frame->getOpcode();
        } else {
            $swoole_frame->data = $frame;
        }
        return (bool) $this->client->push($swoole_frame);
    }

    public function onMessage(callable $callback)
    {
        $this->on_message = $callback;
    }

    public function onClose(callable $callback)
    {
        $this->on_close = $callback;
    }

    public function upgrade(UriInterface $uri, array $headers = [], bool $reconnect = false): bool
    {
        if (!$reconnect && $this->status !== CHOIR_TCP_INITIAL) {
            return false;
        }
        $this->status = CHOIR_TCP_CONNECTING;
        $this->client = new Client($uri->getHost(), $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80), $uri->getScheme() === 'https');
        // 设置 Swoole 参数
        $this->set['websocket_mask'] = true;
        $this->client->set($this->set);
        // 设置请求方法为 GET
        $this->client->setMethod('GET');
        // 设置 Headers
        $headers_total = [];
        foreach ($headers as $h_name => $header) {
            if (is_array($header)) {
                $headers_total[$h_name] = implode(', ', $header);
            } else {
                $headers_total[$h_name] = $header;
            }
        }
        $this->client->setHeaders($headers_total);
        // 设置请求的 URI
        $uri_total = $uri->getPath();
        if ($uri_total === '') {
            $uri_total = '/';
        }
        if (($query = $uri->getQuery()) !== '') {
            $uri_total .= '?' . $query;
        }
        if (($fragment = $uri->getFragment()) !== '') {
            $uri_total .= '?' . $fragment;
        }
        $code = $this->client->upgrade($uri_total);
        if ($this->client->errCode !== 0) {
            return false;
        }
        if ($code) {
            go(function () {
                while (true) {
                    $result = $this->client->recv(60);
                    if ($result === false) {
                        if ($this->client->connected === false) {
                            $this->status = CHOIR_TCP_CLOSED;
                            go(function () {
                                $frame = FrameFactory::createCloseFrame($this->client->statusCode, '');
                                call_user_func($this->on_close, $frame, $this);
                            });
                            break;
                        }
                    } elseif ($result instanceof Frame) {
                        go(function () use ($result) {
                            $frame = new \Choir\WebSocket\Frame($result->data, $result->opcode, true, true);
                            call_user_func($this->on_message, $frame, $this);
                        });
                    }
                }
            });
        }
        $this->status = $code ? CHOIR_TCP_ESTABLISHED : CHOIR_TCP_CLOSED;
        return $code;
    }
}
