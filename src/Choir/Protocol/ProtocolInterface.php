<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\ListenPort;
use Choir\Server;

/**
 * 协议实现的抽象接口
 */
interface ProtocolInterface
{
    public function __construct(string $host, int $port, string $protocol_name);

    /**
     * 传入数据，获取该协议本次 TCP 流应该读取的长度（原 Workerman 的 input）
     *
     * @param string              $buffer     缓冲区数据
     * @param ConnectionInterface $connection 连接对象
     */
    public function checkPackageLength(string $buffer, ConnectionInterface $connection): int;

    /**
     * 将打包好的数据包交给协议解析
     *
     * @param ListenPort|Server   $server     协议处理对象
     * @param string              $package    包体
     * @param ConnectionInterface $connection 传输层连接对象
     */
    public function execute($server, string $package, ConnectionInterface $connection): bool;

    /**
     * 获取该协议用于 stream 监听的字符串，例如 tcp://0.0.0.0:8080
     */
    public function getSocketAddress(): string;

    /**
     * 返回传输类型，可能为 tcp、udp、unix 或 ssl
     */
    public function getTransport(): string;

    /**
     * 返回 PHP 支持的内建传输类型，可能为 tcp、udp、unix
     */
    public function getBuiltinTransport(): string;

    /**
     * 返回应用层支持的回调
     */
    public function getProtocolEvents(): array;

    /**
     * 返回原始传入的协议名称
     */
    public function getProtocolName(): string;

    /**
     * 构建上下文对象
     */
    public function makeContext(): object;

    /**
     * 返回相应协议扩展的连接 Connection 对象
     */
    public function getConnectionClass(): string;
}
