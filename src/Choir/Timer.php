<?php

declare(strict_types=1);

namespace Choir;

use Choir\EventLoop\EventHandler;

class Timer
{
    protected static array $tasks = [];

    protected static array $status = [];

    protected static int $timer_id = 0;

    /**
     * [MASTER] 使用 SIGALRM 初始化 Master 进程的秒级定时器
     *
     * @see https://www.workerman.net/q/5143
     */
    public static function initMasterTimer(): void
    {
        if (function_exists('pcntl_signal')) {
            Server::logDebug('initializing timer alarm signal');
            pcntl_signal(SIGALRM, function () {
                if (!EventHandler::$event) {
                    /* @noinspection PhpComposerExtensionStubsInspection */
                    pcntl_alarm(1);
                    Timer::tick();
                }
            }, false);
        }
    }

    /**
     * [MASTER, WORKER] 添加一个定时器
     *
     * @param  float    $time_interval 间隔时间，Master 进程下最小单位为秒，Worker 下为毫秒
     * @param  callable $func          回调函数
     * @param  array    $args          传入参数
     * @param  bool     $persistent    是否持久化，默认为 True，当为 False 时，该计时器只执行一遍
     * @return bool|int 失败时返回 False，成功时返回计时器 ID
     */
    public static function add(float $time_interval, callable $func, array $args = [], bool $persistent = true)
    {
        // 必须大于等于0
        if ($time_interval < 0) {
            Server::logDebug('bad time interval: ' . $time_interval);
            return false;
        }

        // 如果声明了 EventLoop，那就给 EventLoop 中插入计时器，不用自己的
        if (EventHandler::$event) {
            return $persistent ? EventHandler::$event->repeat($time_interval, $func, $args) : EventHandler::$event->delay($time_interval, $func, $args);
        }

        // 非 Choir Server 运行环境，直接返回
        if (Server::getInstance() === null) {
            return false;
        }

        if (empty(static::$tasks) && function_exists('pcntl_alarm')) {
            pcntl_alarm(1);
        }

        $run_time = time() + $time_interval;
        if (!isset(static::$tasks[$run_time])) {
            static::$tasks[$run_time] = [];
        }

        // 计时器 ID 计数
        static::$timer_id = static::$timer_id === PHP_INT_MAX ? 1 : ++static::$timer_id;

        // 计时器状态记录
        static::$status[static::$timer_id] = true;

        // 计时器任务记录
        static::$tasks[$run_time][static::$timer_id] = [$func, $args, $persistent, $time_interval];

        return static::$timer_id;
    }

    /**
     * [MASTER] 计时器单次 tick 方法
     */
    public static function tick(): void
    {
        // 无任务，alarm
        if (empty(static::$tasks)) {
            /* @noinspection PhpComposerExtensionStubsInspection */
            pcntl_alarm(0);
            return;
        }

        // 有任务
        $time_now = time();
        foreach (static::$tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $i => $one_task) {
                    [$task_func, $task_args, $persistent, $time_interval] = $one_task;

                    // 运行任务
                    try {
                        $task_func(...$task_args);
                    } catch (\Throwable $e) {
                        $str = choir_exception_as_string($e);
                        Server::logError($str);
                    }

                    // 如果是持久性任务，并且任务还在，那么将任务生成到下一个执行时间上
                    if ($persistent && !empty(static::$status[$i])) {
                        // 生成新的时间，当前时间加上间隔
                        $new_run_time = time() + $time_interval;
                        if (!isset(static::$tasks[$new_run_time])) {
                            static::$tasks[$new_run_time] = [];
                        }
                        static::$tasks[$new_run_time][$i] = [
                            $task_func,
                            (array) $task_args,
                            $persistent,
                            $time_interval,
                        ];
                    }
                }
                // 删除当前时间（秒）的任务
                unset(static::$tasks[$run_time]);
            }
        }
    }

    /**
     * [MASTER, WORKER] 删除所有计时器
     */
    public static function delAll(): void
    {
        self::$tasks = self::$status = [];
        if (\function_exists('pcntl_alarm')) {
            \pcntl_alarm(0);
        }
        EventHandler::$event && EventHandler::$event->deleteAllTimer();
    }
}
