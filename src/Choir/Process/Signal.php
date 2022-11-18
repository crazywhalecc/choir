<?php

declare(strict_types=1);

namespace Choir\Process;

use Choir\EventLoop\EventHandler;
use Choir\Exception\MonitorException;
use Choir\Monitor\ProcessMonitor;
use Choir\Server;

class Signal
{
    /**
     * 初始化 Choir Server 默认的 Signal：SIGTERM、SIGUSR1、SIGQUIT、SIGINT
     */
    public static function initDefaultSignal(int $process_type, array $signals = [], bool $reinstall = false): void
    {
        // 必须加载 pcntl 扩展才能初始化信号
        if (!extension_loaded('pcntl')) {
            Server::logError('pcntl is not installed, cannot install default signals');
            return;
        }
        if ($signals === []) {
            $signals = [SIGTERM, SIGUSR1, SIGQUIT, SIGINT];
        }
        Server::logDebug(($reinstall ? 'Rei' : 'I') . 'nstalling default signal for ' . ($process_type === CHOIR_PROCESS_MASTER ? 'master' : 'worker') . ': ' . implode(', ', $signals));
        foreach ($signals as $signal) {
            if ($reinstall) {
                if ($process_type === CHOIR_PROCESS_WORKER && $signal === SIGQUIT) {
                    pcntl_signal(SIGQUIT, SIG_DFL, false);
                    continue;
                }
                pcntl_signal($signal, SIG_IGN, false);
                EventHandler::$event->onSignal($signal, $process_type === CHOIR_PROCESS_MASTER ? [static::class, 'onMasterSignalHandler'] : [static::class, 'onWorkerSignalHandler']);
            } else {
                pcntl_signal($signal, $process_type === CHOIR_PROCESS_MASTER ? [static::class, 'onMasterSignalHandler'] : [static::class, 'onWorkerSignalHandler'], false);
            }
        }
    }

    /**
     * Choir 自带的默认信号监听
     *
     * @param  int              $signal 信号量
     * @throws MonitorException
     * @throws \Throwable
     */
    public static function onMasterSignalHandler(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:   // 停服，这两个信号只能 Master 注册
                Server::getInstance()->stop();
                break;
            case SIGQUIT:
                Server::getInstance()->stop(0, true);
                break;
            case SIGUSR1:   // 重载 Worker 们
                if (ProcessMonitor::getCurrentProcessType() === CHOIR_PROCESS_MASTER || ProcessMonitor::getMasterPid() !== getmypid()) {
                    Server::getInstance()->reload();
                }
                break;
            case SIGINT:
                /* @phpstan-ignore-next-line */
                if (CHOIR_SINGLE_MODE || ProcessMonitor::getCurrentProcessType() === CHOIR_PROCESS_MASTER || ProcessMonitor::getMasterPid() !== getmypid()) {
                    // 单例进程下直接退出，多 Worker 则不响应
                    Server::getInstance()->stop(130);
                }
                break;
        }
    }

    /**
     * Choir 自带的默认信号监听
     *
     * @param  int        $signal 信号量
     * @throws \Throwable
     */
    public static function onWorkerSignalHandler(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
                if (Server::getInstance() !== null) {
                    Server::getInstance()->exitWorker();
                }
                break;
            case SIGQUIT:
                exit(127);
            case SIGUSR1:
            case SIGINT:
                Server::logDebug('Signal ' . ($signal === SIGUSR1 ? 'SIGUSR1' : 'SIGINT') . ' captured in worker process');
                break;
        }
    }
}
