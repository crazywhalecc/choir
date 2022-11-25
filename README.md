# Choir

一个纯 PHP 写的 Server 库，由 Workerman 改进而来，作为一个学习的项目而编写。

## Choir 的功能

Choir 内部使用 PHP 原生的 Socket Server 实现了一个 TCP 和 UDP 的服务器，在此基础上实现了 HTTP、WebSocket 等协议，可以使用 Choir 来实现一个纯 CLI 环境下的 Web 应用服务。

Choir 基于部分 Workerman 的代码，功能与 Workerman 相似，但相较于底层的信号处理、包处理以及开放接口采用更加通用的 PSR 标准，便于融合其他框架。

## Choir 文档

> 在写了在写了。

## 与 Workerman 的不同

### 1. 重构目录结构

Choir 重构了 Workerman 的 Worker 结构，采用 Server 对象对服务进行描述，同时单个 Server 可以监听不同的端口，从最开始统一了 Web Server 的概念。

### 2. 完善 WebSocket 协议实现

对 WebSocket 协议实现来说，Workerman 仅实现了 UTF-8 明文，Choir 实现了支持二进制传输及分包传输，更支持了一些额外的 WebSocket 操作对象，例如 Frame 等。

### 3. 重构协议层接口

重构协议层的接口，对协议的解析流程进行了调整。

### 4. 支持协程

原生支持 Fiber 协程一键开启，使用 PHP 8.1 时自动开启纤程，且可通过传入的配置项切换使用 Swoole、Swow 协程库等。

### 5. 源码清晰

Choir 源码的每一部分都添加了注释，你可以在查看 Choir 源码的过程中快速了解它是如何运作的。

### 6. 开放性和易用性并存

Choir 同 Workerman 一样，默认提供了一些初始的参数显示和信号处理参数，但 Choir 可以通过传参和修改静态变量的方式进行自定义。

## Choir 的不足

Choir 目前仅实现了基础的 Server 和 Client 组件，其他附带的组件暂时正在开发中。

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
