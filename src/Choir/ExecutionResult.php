<?php

declare(strict_types=1);

namespace Choir;

class ExecutionResult
{
    public int $code;

    public string $stdout;

    public string $stderr;

    public function __construct(int $code, $stdout = '', $stderr = '')
    {
        $this->code = $code;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }
}
