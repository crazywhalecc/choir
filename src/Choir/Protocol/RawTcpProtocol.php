<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\Protocol\Context\DefaultContext;

/**
 * 裸 TCP 包的解析协议，不包含任何其他回调
 */
class RawTcpProtocol implements ProtocolInterface
{
    protected string $host;

    protected int $port;

    protected string $protocol_name;

    public function __construct(string $host, int $port, string $protocol_name)
    {
        $this->protocol_name = $protocol_name;
        $this->port = $port;
        $this->host = $host;
    }

    /**
     * {@inheritDoc}
     */
    public function checkPackageLength(string $buffer, ConnectionInterface $connection): int
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function execute($server, string $package, ConnectionInterface $connection): bool
    {
        return false;
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
        return [];
    }

    public function getProtocolName(): string
    {
        return $this->protocol_name;
    }

    public function makeContext(): object
    {
        return new DefaultContext();
    }

    public function getConnectionClass(): string
    {
        return Tcp::class;
    }
}
