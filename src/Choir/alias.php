<?php

declare(strict_types=1);

use Choir\Coroutine\Runtime;
use Choir\Timer;
use Choir\WebSocket\Server;

class_alias(\Choir\Server::class, 'choir_server');
class_alias(Timer::class, 'choir_timer');
class_alias(\Choir\Http\Server::class, 'choir_http_server');
class_alias(Server::class, 'choir_websocket_server');

/**
 * 创建协程的快捷方法
 *
 * @param  callable                          $callback 回调
 * @param  array|mixed                       ...$args  参数们
 * @throws \Choir\Exception\RuntimeException
 */
function choir_go(callable $callback, ...$args): int
{
    if (Runtime::getImpl() !== null) {
        return Runtime::getImpl()->create($callback, ...$args);
    }
    throw new \Choir\Exception\RuntimeException('Event Loop is not initialized yet');
}

/**
 * 休眠，如果不是协程环境，则使用 PHP 自带的 sleep
 *
 * @param float|int $time
 */
function choir_sleep($time)
{
    if (Runtime::getImpl() !== null) {
        return Runtime::getImpl()->sleep($time);
    }
    return sleep($time);
}
