<?php

declare(strict_types=1);

namespace Choir\Process;

use Choir\Exception\ChoirException;
use Choir\Monitor\ProcessMonitor;
use Choir\Server;

/**
 * 叉子类，所有创建子进程的函数方法都在这里
 */
class Forker
{
    /**
     * [INTERNAL] 让 Choir 以 daemon 形式运行，当前进程会被退出，新的进程将挂载到 pid 1 下
     *
     * @internal 仅 Choir 内部可调用
     * @throws ChoirException
     */
    public static function daemonize(): void
    {
        // 如果没有 pcntl 扩展，那需要抛出异常了
        if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
            throw new ChoirException('extension pcntl is not installed, cannot use daemon mode');
        }
        // 守护模式需要设置当前的 umask
        umask(0);
        $pid = pcntl_fork();
        // -1 失败
        if ($pid === -1) {
            throw new ChoirException('daemonize failed: fork failed');
        }
        // 从这里开始分成了两个进程了，两个进程分别执行下面的代码
        // pid 大于 0，说明当前是父进程，等于 0 则说明当前进程是 fork 后的新进程
        if ($pid > 0) {
            exit(0);
        }

        // 使当前进程成为会话的主进程（setsid）
        if (posix_setsid() === -1) {
            throw new ChoirException('daemonize failed: setsid failed');
        }

        // 再次 fork，避免 SVR4 系统重新控制终端
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ChoirException('daemonize failed: svr4 fork failed');
        }
        if ($pid !== 0) {
            echo 'Server started with daemon mode, pid: ' . $pid . PHP_EOL;
            exit(0);
        }
    }

    /**
     * 根据 worker 数量批量 fork worker 进程，需要在 STARTING 状态下调用
     *
     * @param  null|mixed     $worker_id
     * @throws ChoirException
     */
    public static function forkWorkers(int $worker_num, &$worker_id = null): int
    {
        // 如果没有 pcntl 扩展，那需要抛出异常了
        if (!function_exists('pcntl_fork')) {
            throw new ChoirException('fork workers failed: no pcntl extension installed');
        }

        // 根据传入的 Worker 数量 fork
        for ($i = 0; $i < $worker_num; ++$i) {
            $worker_id = $i;

            $type = self::forkWorker($worker_id);
            if ($type !== CHOIR_PROCESS_MASTER) {
                return $type;
            }
        }
        return CHOIR_PROCESS_MASTER;
    }

    /**
     * Fork 一个 Worker 进程
     *
     * @param  int            $worker_id Worker ID
     * @throws ChoirException
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public static function forkWorker(int $worker_id): int
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            ProcessMonitor::saveWorkerState($worker_id, ['pid' => $pid, 'status' => CHOIR_PROC_STARTING], Server::getInstance()->settings['monitor']['process'] ?? true);
            ProcessMonitor::setProcessType(CHOIR_PROCESS_MASTER);
            return CHOIR_PROCESS_MASTER;
        }
        if ($pid === 0) {
            // 重置随机数种子
            srand();
            mt_srand();
            ProcessMonitor::setProcessType(CHOIR_PROCESS_WORKER, $worker_id);
            return CHOIR_PROCESS_WORKER;
        }
        throw new ChoirException('fork workers failed: pcntl_fork failed');
    }
}
