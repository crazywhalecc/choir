<?php

declare(strict_types=1);

namespace Choir;

use Choir\Exception\ChoirException;
use Choir\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class GlobalTest extends TestCase
{
    public function testChoirExceptionAsString()
    {
        $exception = new ChoirException();
        $str = choir_exception_as_string($exception);
        $this->assertIsString($str);
        $this->assertStringStartsWith('Uncaught Choir\Exception\ChoirException with code [0]:', $str);
    }

    public function testChoirId()
    {
        $this->assertIsString(choir_id());
        $this->assertEquals(40, strlen(choir_id()));
        $this->assertEquals(choir_id(), choir_id());
    }

    public function testCallFuncStopwatch()
    {
        $this->assertIsFloat(call_func_stopwatch(function () {}));
        $this->assertGreaterThanOrEqual(1, call_func_stopwatch(function () {
            sleep(1);
        }));
    }

    public function testChoirGo()
    {
        $this->expectException(RuntimeException::class);
        choir_go(function () {});
    }

    public function testChoirSleep()
    {
        $this->assertEquals(0, choir_sleep(0));
    }
}
