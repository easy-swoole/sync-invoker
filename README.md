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
3. 共享 `Swoole\Table` 使用 `isAvailable` 标记当前可用的 worker，并通过 `pId` 保存
   worker 进程 ID。
4. 客户端调用方法时，从空闲 worker 中随机选择并原子抢占一个 worker。
5. 命令和参数序列化后通过 Unix Socket 发送给 worker。
6. 客户端在 timeout 内等待返回；超时后立即向本次调用记录的 worker PID 发送 `SIGKILL`。
7. Swoole Server 自动拉起新的 worker，新进程重新登记 PID 并恢复可用状态。

共享表固定创建 256 行容量。实际同步进程数量由 `workerNum` 决定，不应超过 256。

## 安装

```bash
composer require easyswoole/sync-invoker
```

## 基本用法

```php
<?php

use EasySwoole\SyncInvoker\AbstractDriver;
use EasySwoole\SyncInvoker\Config;
use EasySwoole\SyncInvoker\SyncInvoker;
use EasySwoole\SyncInvoker\Worker;
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

    // timeout 单位为秒；超时后执行该任务的 worker 进程会被强制终止。
    $timeoutResult = $invoker->invoke(0.5)->blockingTask();

    $response->end(json_encode([
        'sum' => $sum,
        'blockingTask' => $timeoutResult,
    ], JSON_UNESCAPED_UNICODE));
});

$server->start();
```

## Timeout 语义

调用入口为：

```php
public function invoke(float $timeout = 15.0): Client
```

- 单位为秒，支持小数。
- 默认值为 15 秒。
- 不接受 `null`。
- 必须大于 `0`，否则抛出 `InvalidArgumentException`。
- timeout 是一次调用的总时间预算，包括等待空闲 worker、Unix Socket 通讯和方法执行。
- 所有 worker 都忙且等待超过 timeout 时，调用返回 `null`。
- Socket 连接、发送或接收失败时，调用也可能返回 `null`。
- Socket 收包明确超时时，客户端立即 `SIGKILL` 本次调用开始时记录的 worker PID，
  返回 `null`，并等待 Swoole Server 自动拉起替代进程。
- 超时路径不会把旧 worker 恢复为可用；只有新 worker 启动后才会写入新 PID 和可用状态。

### `signal=9` 属于预期日志

`SIGKILL` 无法被 worker 捕获、忽略或执行清理，因此 Swoole 会把旧 worker 记录为
`signal=9` 异常退出，例如：

```text
worker(pid=12345, id=2) abnormal exit, status=0, signal=9
```

当该日志与一次 Sync Invoker timeout 对应时，属于预期行为，不表示 Swoole 无法恢复；
Swoole Server 会自动拉起替代 worker。不要无条件忽略所有 `signal=9`，因为手工
`kill -9`、OOM 或其他故障也可能产生相同信号，监控系统应结合 timeout 记录、PID 和
发生时间判断。

### Callback、事务与幂等性

超时强杀不会执行 Driver 的 `onException()`、`finally`、析构函数或 shutdown callback。
因此 callback 包含数据库事务或外部副作用时必须特别注意：

- 未提交事务通常会在数据库连接断开后回滚，但最终行为取决于数据库和驱动，不能依赖
  PHP 清理代码执行回滚。
- 事务可能已经成功提交，但结果尚未来得及通过 Unix Socket 返回；此时调用方得到
  `null`，不能据此判断业务操作一定失败。
- HTTP API、消息发送、文件写入等操作也可能已经产生副作用，只是返回阶段发生超时。
- 调用方重试可能造成重复写入、重复扣款或重复发送，callback 应使用业务唯一键、
  幂等 token、唯一索引或去重记录保证幂等。
- 尽量缩短事务范围，不要在数据库事务中执行耗时的外部网络调用。
- 数据库连接及其他进程内资源必须在 `onWorkerStart` 中创建，以便 worker 被拉起后重建。

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

Driver callback 抛出的原始异常会直接传给 `onException()`。客户端 timeout 采用进程
强杀，不会进入 Driver 的 `onException()`。

`onException()` 自身也不应抛出新的异常，否则异常会继续传播到自定义进程入口。

## Driver 必须无状态

Driver 实例不会为每次调用 clone。每个 worker 进程拥有自己独立的 Driver 实例，并且
请求会分配到任意空闲 worker。因此不要在 Driver 属性中保存以下状态：

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
- 客户端抢占 worker 时同时记录 PID，避免误杀重启后的新进程。
- 只有 Unix Socket 收包错误明确为 `SOCKET_ETIMEDOUT` 时才会强杀。
- `SIGKILL` 是进程级强杀，即使任务卡在不可中断的 PHP 扩展调用中也能终止整个 worker。
- worker 内的数据库连接等进程资源会随进程销毁，并在新 worker 的 `onWorkerStart` 中重建。

## 主要配置

```php
$config = new Config();
$config->setServerName('EasySwoole');
$config->setWorkerNum(3);
$config->setTempDir(sys_get_temp_dir());
$config->setMaxPackageSize(2 * 1024 * 1024);
$config->setDriver(new Driver());
$config->setOnWorkerStart(static function (Worker $worker): void {
    // 初始化当前 worker 独享的同步客户端连接。
});
```

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

`isAvailable` 为 `1` 表示空闲，为 `0` 表示正在执行任务；`pId` 是对应同步 worker 的
进程 ID。返回值是普通列表，不使用 worker 编号作为索引。尚未启动的 worker 不会出现
在数组中。

## 测试

```bash
composer test
```
