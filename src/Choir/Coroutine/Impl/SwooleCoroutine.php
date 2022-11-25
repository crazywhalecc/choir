<?php

declare(strict_types=1);

namespace Choir\Coroutine\Impl;

use Choir\Coroutine\CoroutineInterface;
use Choir\ExecutionResult;
use Choir\SingletonTrait;
use Swoole\Coroutine;

class SwooleCoroutine implements CoroutineInterface
{
    use SingletonTrait;

    private static array $resume_values = [];

    public static function isAvailable(): bool
    {
        return extension_loaded('swoole') || extension_loaded('openswoole');
    }

    public function create(callable $callback, ...$args): int
    {
        return go($callback, ...$args);
    }

    public function suspend()
    {
        Coroutine::yield();
        if (isset(self::$resume_values[$this->getCid()])) {
            $value = self::$resume_values[$this->getCid()];
            unset(self::$resume_values[$this->getCid()]);
            return $value;
        }
        return null;
    }

    public function resume(int $cid, $value = null)
    {
        if (Coroutine::exists($cid)) {
            self::$resume_values[$cid] = $value;
            Coroutine::resume($cid);
            return $cid;
        }
        return false;
    }

    public function getCid(): int
    {
        return Coroutine::getCid();
    }

    public function sleep($time)
    {
        Coroutine::sleep($time);
    }

    public function exec(string $cmd): ExecutionResult
    {
        $result = Coroutine\System::exec($cmd);
        return new ExecutionResult($result['code'], $result['output']);
    }
}
