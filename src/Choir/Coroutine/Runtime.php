<?php

declare(strict_types=1);

namespace Choir\Coroutine;

use Choir\Coroutine\Impl\FiberCoroutine;
use Choir\Coroutine\Impl\SwooleCoroutine;

class Runtime
{
    public static array $coroutine_impl = [
        'swoole' => SwooleCoroutine::class,
        'fiber' => FiberCoroutine::class,
    ];

    /**
     * @var null|CoroutineInterface 对应协程接口的实现
     * @internal 仅限 Choir 内部使用
     */
    private static ?CoroutineInterface $impl = null;

    /**
     * 初始化协程环境，如果当前环境不支持协程，则返回 False
     */
    public static function initCoroutineEnv(?string $type = null): bool
    {
        // 传入了指定的已知类型名称，直接取
        if ($type !== null && isset(self::$coroutine_impl[$type])) {
            $class = self::$coroutine_impl[$type];
            if (!$class::isAvailable()) {
                return false;
            }
            /* @phpstan-ignore-next-line */
            self::$impl = method_exists($class, 'getInstance') ? $class::getInstance() : new $class();
            return true;
        }

        // 没传入类型，自动根据列表选择
        if ($type === null) {
            foreach (self::$coroutine_impl as $v) {
                if ($v::isAvailable()) {
                    self::$impl = $v::getInstance();
                    return true;
                }
            }
            return false;
        }

        // 传入了，但传入的是类名，如果是实现了 CoroutineInterface 接口的，则尝试验证并使用
        if (is_a($type, CoroutineInterface::class, true)) {
            if ($type::isAvailable()) {
                self::$impl = method_exists($type, 'getInstance') ? $type::getInstance() : new $type();
                return true;
            }
        }

        // 其他情况，失败
        return false;
    }

    public static function getImpl(): ?CoroutineInterface
    {
        return self::$impl;
    }
}
