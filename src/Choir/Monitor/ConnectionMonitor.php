<?php

declare(strict_types=1);

namespace Choir\Monitor;

/**
 * 连接监视器，用于监控和统计连接的数量和情况等
 */
class ConnectionMonitor
{
    /**
     * @var array|int[] TCP 连接失败的计数
     */
    private static array $tcp_fail_counts = [
        'send' => 0,
        'receive' => 0,
    ];

    /**
     * 添加 TCP 连接失败的计数器（当前进程）
     *
     * @param string $type 类型
     * @param int    $cnt  数量（默认1）
     */
    public static function addTcpFailCount(string $type, int $cnt = 1): void
    {
        if (!in_array($type, ['send', 'receive'])) {
            return;
        }
        self::$tcp_fail_counts[$type] += $cnt;
    }
}
