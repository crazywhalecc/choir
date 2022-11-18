# Choir

一个纯 PHP 写的 Server 库，由 Workerman 改进而来，作为一个学习的项目而编写。

## HTTP 服务器样例

```php
require_once 'vendor/autoload.php';

// 也可以使用 Url 模式：$server = new \Choir\Server("http://0.0.0.0:20001");
$server = new \Choir\Http\Server('0.0.0.0', 20001, false, [
    'worker-num' => 8,
]);

$server->on('request', function (Choir\Protocol\HttpConnection $connection) {
    $connection->end('hello world');
});

$server->start();
```
