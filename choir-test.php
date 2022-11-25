<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

use Choir\Coroutine\System;
use Choir\Server;

require_once 'vendor/autoload.php';

$server = new Server('http://0.0.0.0:20001', [
    'worker-num' => 8,
    'logger-level' => 'debug',
]);

$server->on('workerStart', function () {
    choir_go(function () {
        echo "开始\n";
        $time = microtime(true);
        choir_sleep(5);
        echo '5秒之后: ' . round(microtime(true) - $time, 3) . "\n";
    });
    choir_go(function () {
        echo "再次开始！\n";
        $time = microtime(true);
        System::exec('php -r "sleep(2);"');
        echo '2秒之后: ' . round(microtime(true) - $time, 3) . "\n";
    });
    echo "测试协程开始！\n";
});

$server->on('request', function (Choir\Protocol\HttpConnection $connection) {
    $connection->end('hello world');
});

$server->start();
