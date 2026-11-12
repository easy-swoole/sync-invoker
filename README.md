# Sync Invoker

在 Swoole 协程服务中，通过独立进程执行同步阻塞方法，避免同步客户端或阻塞 API
占用业务 Worker 的协程调度线程。

## 运行要求

- PHP 8.1 或更高版本
- Swoole 5.1 或更高版本
- 支持 Unix Socket 和进程信号的操作系统

## 工作原理

1. `SyncInvoker` 创建多个自定义进程，每个进程监听独立的 Unix Socket。
2. 每个进程串行执行一个同步阻塞任务。
3. 每个 worker 在共享 `Swoole\Table` 中登记 PID，并维护自己的 `isAvailable` 状态。
4. 客户端优先随机选择一个空闲 worker。全部繁忙时，随机选择一个已启动的 worker 投递。
5. 命令、参数和截止时间 `expireTime` 通过 Unix Socket 发送。等待中的连接由该 worker 的 Socket 队列承接。
6. worker 串行处理连接，开始执行前跳过已经过期的请求。
7. 客户端超时后关闭连接。已经开始的任务继续执行。

共享表固定创建 256 行容量。实际同步进程数量由 `workerNum` 决定，不应超过 256。

## 代码目录

- `src/SyncInvoker.php` 和 `src/Config.php` 提供服务挂载和配置入口。
- `src/Client/` 管理调用、超时和返回状态。
- `src/Driver/` 提供业务方法分发和生命周期钩子。
- `src/Process/` 管理 worker 的串行执行和进程回收。
- `src/Protocol/` 定义命令和数据包格式。
- `src/Transport/` 封装 Unix Socket 客户端。
- `tests/Integration/` 覆盖跨模块的 Socket 调用流程。

## 安装

```bash
composer require easyswoole/sync-invoker
```

## 基本用法

```php
<?php

use EasySwoole\SyncInvoker\Driver\AbstractDriver;
use EasySwoole\SyncInvoker\Config;
use EasySwoole\SyncInvoker\Client\ResponseStatus;
use EasySwoole\SyncInvoker\SyncInvoker;
use EasySwoole\SyncInvoker\Process\Worker;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require 'vendor/autoload.php';

final class Driver extends AbstractDriver
{
    public function plus(int $a, int $b): void
    {
        $this->response($a + $b);
    }

    public function blockingTask(): void
    {
        sleep(10);
        $this->response('done');
    }

    protected function actionNotFound(): void
    {
        $this->response([
            'success' => false,
            'error' => $this->getRequest()->getAction() . ' not found',
        ]);
    }

    protected function onException(\Throwable $throwable): void
    {
        $this->response([
            'success' => false,
            'error' => $throwable->getMessage(),
        ]);
    }
}

$config = new Config();
$config->setWorkerNum(3);
$config->setDriver(new Driver());
$config->setOnWorkerStart(static function (Worker $worker): void {
    echo 'sync worker started: ' . $worker->getArg()['workerIndex'] . PHP_EOL;
});

$invoker = new SyncInvoker($config);
$server = new Server('0.0.0.0', 9501);

// 必须在 Server::start() 前挂载自定义进程。
$invoker->attachServer($server);

$server->on('request', static function (
    Request $request,
    Response $response
) use ($invoker): void {
    // 默认 timeout 为 15 秒。
    $sum = $invoker->invoke()->plus(1, 2);

    // timeout 单位为秒。客户端超时后关闭连接，已开始的任务继续执行。
    $timeoutResult = $invoker->invoke(0.5)->blockingTask();

    $response->end(json_encode([
        'sum' => $sum->status === ResponseStatus::Ok ? $sum->result : null,
        'sumStatus' => $sum->status->value,
        'blockingTask' => $timeoutResult,
    ], JSON_UNESCAPED_UNICODE));
});

$server->start();
```

## 调用响应

`$invoker->invoke()->plus(1, 2)` 返回 `EasySwoole\SyncInvoker\Client\Response`，包含三个只读字段。

| 字段 | 含义 |
| --- | --- |
| `status` | `Ok`（`ok`）、`Timeout`（`timeout`）、`TransportError`（`transport_error`）或 `PackageTooLarge`（`package_too_large`），均为 `ResponseStatus` 枚举值。 |
| `result` | `Ok` 时是 Driver 设置的业务结果，可以是 `null` 或 `false`。其他状态下为 `null`。 |
| `msg` | 用于诊断的消息，成功时为空字符串。调用方根据 `status` 分支。 |

Driver 继续用 `$this->response($value)` 设置业务结果。

`maxPackageSize` 按字节限制请求和响应的完整数据包，包含 4 字节包头和序列化后的包体。等于上限可以通过。
客户端在发送前检查请求，worker 在读取包体前再次检查。超大的请求会被拒绝，Driver 不会执行。
超大的响应会改发 `PackageTooLarge`，此时 Driver 已经执行。

## Timeout 语义

调用入口为：

```php
public function invoke(float $timeout = 15.0): Client
```

- 单位为秒，支持小数。
- 默认值为 15 秒。
- 不接受 `null`。
- 必须是大于 `0` 的有限数值，否则抛出 `InvalidArgumentException`。
- timeout 是单次调用的总预算。客户端从开始调用计算同一个 `expireTime`，连接、发送和接收都使用剩余时间。
- `expireTime` 使用同一台主机上的单调时钟。任务排队期间耗尽预算时，worker 关闭连接并跳过 Driver 调用。
- 客户端超时或 Socket 通信失败时关闭连接，分别返回 `Timeout` 或 `TransportError` 状态。
- 任务一旦开始便继续执行。执行完已经过期，或发送响应时连接断开，worker 会丢弃响应并处理下一个连接。
- 如果任务一直阻塞，所在 worker 会持续繁忙，排在它后面的任务要等它恢复后才能处理。

### Callback、事务与幂等性

**任务投递后，客户端收到 `Timeout`，不能据此判断任务是否执行。**
在截止时间附近，worker 可能刚通过过期检查并开始执行，客户端随即超时断开。任务也可能已经执行完成，
但响应没能在截止时间前送达。只有 worker 在开始执行前发现任务已过期时，才会跳过该任务。
对于事务、HTTP 请求、消息发送和文件写入等有副作用的任务，调用方重试时应使用业务唯一键或幂等 token 去重。

## 异常处理

`AbstractDriver::onException()` 默认会重新抛出异常。如果希望业务异常不再
传播到 worker 进程边界，应在 Driver 中覆盖此方法，并且不要再次抛出：

```php
protected function onException(\Throwable $throwable): void
{
    $this->response([
        'success' => false,
        'error' => $throwable->getMessage(),
    ]);
}
```

Driver callback 抛出的原始异常会直接传给 `onException()`。客户端 timeout 只会关闭连接。

`onException()` 自身也不应抛出新的异常，否则异常会继续传播到自定义进程入口。

## Driver 必须无状态

Driver 实例不会为每次调用 clone。每个 worker 进程拥有自己独立的 Driver 实例，并且
请求可分配到任一已启动的 worker。因此不要在 Driver 属性中保存以下状态：

- 当前请求的数据
- 调用次数或任务进度
- 需要跨请求读取的临时结果
- 需要在多个 worker 间保持一致的数据

需要共享状态时，应使用数据库、Redis、Swoole Table 或其他进程间共享机制。

Driver 方法应通过 `$this->response()` 设置返回值：

```php
public function findUser(int $id): void
{
    $user = $this->syncClient->find($id);
    $this->response($user);
}
```

只有 public 方法能够被客户端调用。private、protected 方法以及 Driver 内部生命周期
方法不会作为远程调用入口。

## Worker 并发和超时模型

同一个 worker 只允许串行执行一个同步任务：

- async accept 固定关闭，不提供配置开关。
- 每个 worker 同一时间只执行一个同步任务。
- 需要提高并行度时，应增加 `workerNum`，而不是开启 worker 内协程并发。
- 空闲标记由 worker 更新，客户端用它优先选择空闲进程。同一个 worker 可以收到多个客户端的投递。
- Socket 队列按各 worker 独立维护。投递到繁忙 worker 的请求留在该 worker 的队列里。

### Worker 异常退出

例如，worker 正在执行任务 A，任务 B 已经连接并在该 worker 的 Unix Socket 监听队列中等待。
如果 A 触发 `pcntl_alarm()` 导致 worker 退出，旧连接随之关闭，B 不会执行。
B 的客户端在发送或等待响应时检测到连接失败，会收到 `ResponseStatus::TransportError`。
如果客户端的调用预算先耗尽，则会收到 `ResponseStatus::Timeout`。

Swoole 拉起的新 worker 只接收新建立的连接。旧 worker 队列里的请求不会转移，Sync Invoker 也不会自动重投。

### Worker 定期回收

`maxExecuteNum` 是单个 worker 进程执行的任务数上限，默认 `0` 表示不限。只有进入 Driver 的请求才计数，
排队过期或包长超限而被拒绝的请求不计数。达到上限后，worker 会先完成当前任务并尝试发送响应，
关闭连接后直接退出。Swoole 拉起的新 worker 从零开始计数。旧 Socket 上已排队的请求不会转移或自动重投。

## 主要配置

```php
$config = new Config();
$config->setServerName('EasySwoole');
$config->setWorkerNum(3);
$config->setMaxExecuteNum(1000);
$config->setTempDir(sys_get_temp_dir());
$config->setMaxPackageSize(2 * 1024 * 1024);
$config->setDriver(new Driver());
$config->setOnWorkerStart(static function (Worker $worker): void {
    // 初始化当前 worker 独享的同步客户端连接。
});
```

Socket 文件名为 `SyncInvoker.<serverName>.<workerIndex>.sock`。
`serverName` 只接受非空的 ASCII 字母和数字。在同一个 `tempDir` 中运行多个 Invoker 时，
请在挂载 Server 前为它们设置不同的名称。在不区分大小写的文件系统上，名称需要有大小写之外的区别。

`onWorkerStart` 在同步 worker 进程内执行，适合创建只能在当前进程使用的数据库或第三方
客户端连接。不要在父进程创建连接后再依赖 fork 复制到 worker。

## Worker 信息

`workerInfo()` 返回共享表中已经启动的 worker 进程信息：

```php
$workers = $invoker->workerInfo();

// 示例：
// [
//     ['isAvailable' => 1, 'pId' => 12345],
//     ['isAvailable' => 0, 'pId' => 12346],
// ]
```

`isAvailable` 为 `1` 表示可接收下一个连接，为 `0` 表示正在处理一个连接。`pId` 是对应同步 worker 的
进程 ID。返回值是普通列表，不使用 worker 编号作为索引。尚未启动的 worker 不会出现
在数组中。

## 测试

```bash
composer test
```
