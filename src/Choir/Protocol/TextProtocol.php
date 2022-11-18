<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\Protocol\Context\DefaultContext;

/**
 * Text 协议源于 Workerman，通过换行符分割多条数据，使用 textReceive 回调
 */
class TextProtocol implements ProtocolInterface
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
     * @throws \Throwable
     */
    public function checkPackageLength(string $buffer, ConnectionInterface $connection): int
    {
        if (!$connection instanceof Tcp) {
            return 0;
        }
        // Judge whether the package length exceeds the limit.
        if (\strlen($buffer) >= $connection->getMaxPackageSize()) {
            $connection->close();
            return 0;
        }
        //  Find the position of  "\n".
        $pos = \strpos($buffer, "\n");
        // No "\n", packet length is unknown, continue to wait for the data so return 0.
        if ($pos === false) {
            return 0;
        }
        // Return the current package length.
        return $pos + 1;
    }

    /**
     * {@inheritDoc}
     * @throws \Throwable
     */
    public function execute($server, string $package, ConnectionInterface $connection): bool
    {
        $server->emitEventCallback('textreceive', $connection, $package);
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
        return ['textreceive'];
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
