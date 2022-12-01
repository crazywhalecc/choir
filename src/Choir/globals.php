<?php

declare(strict_types=1);

// 全局常量部分

// Choir 版本 ID（x.y.z：x0y0z）
const CHOIR_VERSION_ID = 100;
const CHOIR_VERSION = '0.1.0-alpha';

// Choir 进程状态码
const CHOIR_PROC_NONE = 0;
const CHOIR_PROC_STARTING = 1;
const CHOIR_PROC_STARTED = 2;
const CHOIR_PROC_STOPPING = 3;
const CHOIR_PROC_STOPPED = 4;
const CHOIR_PROC_DIED = 5;
const CHOIR_PROC_RELOADING = 6;

// Choir 进程类型
const CHOIR_PROCESS_MASTER = 1;
const CHOIR_PROCESS_WORKER = 2;
const CHOIR_PROCESS_USER = 4;

// Choir TCP 连接状态
const CHOIR_TCP_INITIAL = 0;
const CHOIR_TCP_CONNECTING = 1;
const CHOIR_TCP_ESTABLISHED = 2;
const CHOIR_TCP_CLOSING = 4;
const CHOIR_TCP_CLOSED = 8;

// Choir TCP 错误码
const CHOIR_TCP_SEND_FAILED = 2;

const CHOIR_WS_CLOSE_NORMAL = 1000;
const CHOIR_WS_CLOSE_GOING_AWAY = 1001;
const CHOIR_WS_CLOSE_PROTOCOL_ERROR = 1002;
const CHOIR_WS_CLOSE_DATA_ERROR = 1003;
const CHOIR_WS_CLOSE_STATUS_ERROR = 1005;
const CHOIR_WS_CLOSE_ABNORMAL = 1006;
const CHOIR_WS_CLOSE_MESSAGE_ERROR = 1007;
const CHOIR_WS_CLOSE_POLICY_ERROR = 1008;
const CHOIR_WS_CLOSE_MESSAGE_TOO_BIG = 1009;
const CHOIR_WS_CLOSE_EXTENSION_MISSING = 1010;
const CHOIR_WS_CLOSE_SERVER_ERROR = 1011;
const CHOIR_WS_CLOSE_TLS = 1015;

// Choir 所需的临时目录
define('CHOIR_TMP_DIR', PHP_OS_FAMILY === 'Windows' ? 'C:\\Windows\\Temp' : (!empty($env = getenv('TMPDIR')) ? $env : (is_writable('/tmp') ? '/tmp' : (getcwd() . '/.zm-tmp'))));

// 全局方法部分

/**
 * 根据 Exception 生成一个便于打印在终端的字符串形式
 */
function choir_exception_as_string(Throwable $e): string
{
    $str = 'Uncaught ' . get_class($e) . ' with code [' . $e->getCode() . ']: ';
    $str .= $e->getMessage() . ' on ' . $e->getFile() . '[' . $e->getLine() . ']' . PHP_EOL;
    $str .= $e->getTraceAsString();
    return $str;
}

/**
 * 返回当前 Choir 实例的 ID
 */
function choir_id(): string
{
    global $choir_token;
    if ($choir_token === null) {
        $choir_token = sha1(__DIR__ . ':' . getcwd());
    }
    return $choir_token;
}

function call_func_stopwatch(callable $callback, bool $microseconds = true): float
{
    $time = microtime(true);
    $callback();
    $result = microtime(true) - $time;
    if ($microseconds) {
        $result = $result * 1000;
    }
    return round($result, 3);
}
