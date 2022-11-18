<?php

declare(strict_types=1);
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright walkor<walkor@workerman.net>
 * @see      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Choir\EventLoop;

interface EventInterface
{
    /**
     * 检查实现的 EventLoop 在当前环境下是否可用
     */
    public static function isAvailable(): bool;

    /**
     * 延迟调用一个回调
     *
     * @param mixed $func 回调
     * @param mixed $args 传入回调的参数
     */
    public function delay(float $delay, $func, $args);

    /**
     * 重复性地调用一个回调，即计时器
     *
     * @param mixed $func 回调
     * @param mixed $args 传入回调的参数
     */
    public function repeat(float $interval, $func, $args);

    /**
     * 删除一个计时器
     *
     * @param mixed $timer_id 计时器 ID
     */
    public function deleteTimer($timer_id): bool;

    /**
     * 添加一个资源到事件循环，如果这个资源可读，则会调用回调
     *
     * @param mixed $stream 资源
     * @param mixed $func   回调
     */
    public function onReadable($stream, $func);

    /**
     * 删除一个可读资源的回调
     *
     * @param mixed $stream 资源
     */
    public function offReadable($stream);

    /**
     * 添加一个资源到事件循环，如果这个资源可写，则会调用回调
     *
     * @param mixed $stream 资源
     * @param mixed $func   回调
     */
    public function onWritable($stream, $func);

    /**
     * 删除一个可写资源的回调
     *
     * @param mixed $stream 资源
     */
    public function offWritable($stream);

    /**
     * 添加一个 Unix Signal 到事件循环，当 Signal 触发时，调用回调
     *
     * @param mixed $signal Unix 信号量
     * @param mixed $func   回调
     */
    public function onSignal($signal, $func);

    /**
     * 取消一个 Signal 的回调
     *
     * @param mixed $signal Unix 信号量
     */
    public function offSignal($signal);

    /**
     * 删除所有定时器
     */
    public function deleteAllTimer();

    /**
     * 运行事件循环
     */
    public function run();

    /**
     * 停止事件循环
     */
    public function stop();

    /**
     * 获取 Timer 数量
     */
    public function getTimerCount(): int;
}
