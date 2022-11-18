<?php

declare(strict_types=1);

namespace Choir\Monitor;

use Choir\Exception\MonitorException;
use Choir\Server;

/**
 * 进程监视器，用于记录当前进程的状态和子进程的状态
 */
class ProcessMonitor
{
    /**
     * @var null|array<string, mixed> 记录 Master 进程的全局静态变量
     */
    private static ?array $master = null;

    /**
     * @var null|array<int, array> 记录 Worker 进程的全局静态变量
     */
    private static ?array $worker = null;

    /**
     * @var int 记录当前进程的类型
     */
    private static int $process_type = CHOIR_PROCESS_MASTER;

    /**
     * @var array Master 进程记录的 Worker 进程重启次数
     */
    private static array $worker_reload_time = [];

    /**
     * [MASTER] 保存当前 MASTER 进程的状态
     *
     * @throws MonitorException
     */
    public static function saveMasterPid(bool $save_file = true): void
    {
        // 获取 pid
        $pid = getmypid();
        self::$master['pid'] = $pid;
        Server::logDebug('Saving master pid: ' . $pid);

        // 保存 master 进程的 pid
        if ($save_file && PHP_OS_FAMILY !== 'Windows' && file_put_contents(CHOIR_TMP_DIR . DIRECTORY_SEPARATOR . choir_id() . '.master.pid', strval($pid)) === false) {
            throw new MonitorException('cannot save master pid to temp dir');
        }
    }

    /**
     * [MASTER, WORKER] 获取当前 Choir Server 实例下的 master pid
     * 如果当前进程不是 Master，且用于保存进程 pid 的临时文件也无法找到，则返回 null
     */
    public static function getMasterPid(): ?int
    {
        // 当前全局变量已经存了，就不找文件了
        if (isset(self::$master['pid'])) {
            return self::$master['pid'];
        }
        // 一般 Master pid 只有在外部进程时需要找文件
        if (!file_exists($file = CHOIR_TMP_DIR . DIRECTORY_SEPARATOR . choir_id() . '.master-pid')) {
            return null;
        }
        // 读取文件的 pid
        $pid = file_get_contents($file);
        if ($pid === false) {
            return null;
        }
        return intval($pid);
    }

    /**
     * 保存 Worker 进程的状态文件
     *
     * @param  int              $worker_id worker id
     * @param  array            $array     状态数组，包含键名：pid
     * @throws MonitorException 如果需要保存文件，但无法保存到文件，则抛出异常
     */
    public static function saveWorkerState(int $worker_id, array $array, bool $save_file = true): void
    {
        // 已经存在的话，就更新状态
        if (isset(self::$worker[$worker_id])) {
            self::$worker[$worker_id] = array_merge(self::$worker[$worker_id], $array);
        } else {
            self::$worker[$worker_id] = $array;
        }

        // 保存文件
        if ($save_file && file_put_contents(CHOIR_TMP_DIR . DIRECTORY_SEPARATOR . choir_id() . '.worker-' . $worker_id . '.state', json_encode(self::$worker[$worker_id])) === false) {
            throw new MonitorException('cannot save worker state to temp dir');
        }
    }

    /**
     * 获取当前进程的类型
     */
    public static function getCurrentProcessType(): int
    {
        return self::$process_type;
    }

    /**
     * @param int      $process_type 当前进程类型
     * @param null|int $id           如果当前进程是 Worker，未初始化 CHOIR_WORKER_ID 且传入了 id 变量，则设置全局常量
     */
    public static function setProcessType(int $process_type, ?int $id = null): void
    {
        self::$process_type = $process_type;
        if ($process_type === CHOIR_PROCESS_WORKER && $id !== null && !defined('CHOIR_WORKER_ID')) {
            define('CHOIR_WORKER_ID', $id);
        }
    }

    /**
     * [MASTER] 获取当前已知的 Worker 状态列表
     *
     * @return array[]
     */
    public static function getWorkerStates(): array
    {
        return self::$worker;
    }

    /**
     * [MASTER] 增加记录 worker 进程重启的次数
     *
     * @param int $worker_id worker 的 id
     */
    public static function addWorkerReloadTime(int $worker_id): void
    {
        if (!isset(self::$worker_reload_time[$worker_id])) {
            self::$worker_reload_time[$worker_id] = 1;
        } else {
            ++self::$worker_reload_time[$worker_id];
        }
    }

    /**
     * [MASTER] 验证是否所有的 Worker 进程已停止
     */
    public static function isAllWorkerStopped(): bool
    {
        foreach (self::$worker as $state) {
            if ($state['status'] !== CHOIR_PROC_STOPPED) {
                return false;
            }
        }
        return true;
    }
}
