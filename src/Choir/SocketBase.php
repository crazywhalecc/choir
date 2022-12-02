<?php

declare(strict_types=1);

namespace Choir;

use Choir\Coroutine\Runtime;
use Choir\Exception\ChoirException;
use Choir\Protocol\ProtocolInterface;

/**
 * Socket 连接的抽象类，提供了底层的事件回调函数及配置成员变量
 */
abstract class SocketBase
{
    /** @var array 传入的配置参数 */
    public array $settings = [];

    /** @var ProtocolInterface 协议操作对象本身 */
    public ProtocolInterface $protocol;

    /** @var string 协议字符串，需调用构建函数时初始化 */
    public string $protocol_name;

    /**
     * 设置事件回调
     *
     * @throws ChoirException
     */
    public function on(string $event, callable $callback): void
    {
        // 内部全部使用小写，便于存储
        $event = strtolower($event);
        if (!in_array($event, $this->getSupportedEvents())) {
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
        if (($impl = Runtime::getImpl()) !== null) {
            $impl->create(function ($name, ...$args) {
                isset($this->{$name}) && ($this->{$name})(...$args);
            }, $name, ...$args);
        } else {
            isset($this->{$name}) && call_user_func($this->{$name}, ...$args);
        }
    }

    /**
     * 获取当前异步 Socket 对象支持的 Event 类型列表
     */
    protected function getSupportedEvents(): array
    {
        return [];
    }
}
