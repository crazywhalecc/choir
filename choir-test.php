<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

use Choir\Server;

require_once 'vendor/autoload.php';

$server = new Server('http://0.0.0.0:20001', [
    'worker-num' => 1,
    'logger-level' => 'debug',
]);

$server->on('workerStart', function () {
});

$server->on('request', function (Choir\Protocol\HttpConnection $connection) {
    $client = new \Choir\Http\Client\AsyncStreamClient();
    $client->sendRequestAsync(
        \Choir\Http\HttpFactory::createRequest('GET', 'http://ip.zhamao.xin:80'),
        function (Psr\Http\Message\ResponseInterface $response) use ($connection) {
            $connection->end('Received: ' . $response->getBody()->getContents());
        },
        function () use ($connection) {
            $connection->end(\Choir\Http\HttpFactory::createResponse(500, null, [], 'ERROR!'));
            // Server::logError('连接错误！');
        }
    );
});

$server->start();
