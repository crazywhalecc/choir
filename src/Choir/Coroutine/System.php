<?php

declare(strict_types=1);

namespace Choir\Coroutine;

use Choir\ExecutionResult;

class System
{
    /**
     * 挂起多少秒
     *
     * @param float|int $time 暂停的秒数，支持小数到 0.001
     */
    public static function sleep($time)
    {
        Runtime::getImpl()->sleep($time);
    }

    public static function exec(string $cmd): ExecutionResult
    {
        return Runtime::getImpl()->exec($cmd);
    }
}
