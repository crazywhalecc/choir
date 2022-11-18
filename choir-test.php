<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

require_once 'vendor/autoload.php';

$server = new \Choir\Http\Server('0.0.0.0', 20001, false, [
    'worker-num' => 8,
]);

$server->on('request', function (Choir\Protocol\HttpConnection $connection) {
    $connection->end('hello world');
});

$server->start();
