<?php

declare(strict_types=1);

namespace Choir\EventLoop;

use Choir\Exception\ChoirException;
use Choir\Server;

class EventHandler
{
    /** @var null|EventInterface Choir 使用的 EventLoop 对象 */
    public static ?EventInterface $event = null;

    /** @var string[] 支持的 EventLoop，在 Choir 启动时会从上到下进行尝试 */
    public static array $available_event_loops = [
        Ev::class,
        Revolt::class,
        Event::class,
        Swoole::class,
        Select::class,
    ];

    /**
     * 创建一个新的 EventLoop
     *
     * @throws ChoirException
     */
    public static function createEventLoop(string $name = ''): EventInterface
    {
        if ($name === '') {
            foreach (static::$available_event_loops as $v) {
                /** @var EventInterface|string $v */
                if ($v::isAvailable()) {
                    $name = $v;
                    Server::logDebug('Using ' . $name . ' EventLoop');
                    break;
                }
            }
            if ($name === '') {
                throw new ChoirException('no EventLoop class found for default');
            }
        } else {
            if (!is_a($name, EventInterface::class, true) || !$name::isAvailable()) {
                throw new ChoirException('selected EventLoop class ' . $name . ' is not available');
            }
        }
        return new $name();
    }
}
