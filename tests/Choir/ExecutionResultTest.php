<?php

declare(strict_types=1);

namespace Tests\Choir;

use Choir\ExecutionResult;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class ExecutionResultTest extends TestCase
{
    public function testConstruct()
    {
        $a = new ExecutionResult(0, 'hello', '');
        $this->assertEquals(0, $a->code);
        $this->assertEquals('hello', $a->stdout);
        $this->assertEquals('', $a->stderr);
    }
}
