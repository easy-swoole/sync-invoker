# Sync Invoker

在 Swoole 协程服务中，通过独立进程执行同步阻塞方法，避免同步客户端或阻塞 API
占用业务 Worker 的协程调度线程。

## 运行要求

- PHP 8.1 或更高版本
- Swoole 5.1 或更高版本
- `pcntl` 扩展和 Unix 信号支持
- Linux、macOS 等支持 `SIGALRM` 的系统

`EasySwoole\Component\Invoker` 使用 `SIGALRM` 中断超时任务，因此不支持 Windows，
也不能与进程内其他 `SIGALRM` 用法混用。

## 工作原理

1. `SyncInvoker` 创建多个自定义进程，每个进程监听独立的 Unix Socket。
2. 每个进程串行执行一个同步阻塞任务。
3. 共享 `Swoole\Table` 使用 `isAvailable` 标记当前可用的 worker。
4. 客户端调用方法时，从空闲 worker 中随机选择并原子抢占一个 worker。
5. 命令、参数和 timeout 序列化后通过 Unix Socket 发送给 worker。
6. worker 使用 `EasySwoole\Component\Invoker` 执行方法，并将 timeout 从秒转换为微秒。
7. 方法完成、抛出异常或超时后，worker 关闭当前连接并恢复为可用状态。

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

    // timeout 单位为秒；该调用将在约 500ms 后被 SIGALRM 中断。
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
- worker 收到的执行 timeout 是扣除等待 worker 所消耗时间后的剩余值。
- 所有 worker 都忙且等待超过 timeout 时，调用返回 `null`。
- Socket 连接、发送或接收失败时，调用也可能返回 `null`。

超时后 `Invoker` 抛出 `RuntimeException('func timeout')`。该异常会先进入 Driver 的
`onException()`；是否继续抛出由 Driver 的异常处理实现决定。

## 异常处理

`AbstractDriver::onException()` 默认会重新抛出异常。如果希望业务异常和 timeout 不再
传播到 worker 进程边界，应在 Driver 中覆盖此方法，并且不要再次抛出：

```php
protected function onException(\Throwable $throwable): void
{
    if(
        $throwable instanceof \RuntimeException
        && $throwable->getMessage() === 'func timeout'
    ){
        $this->response([
            'success' => false,
            'error' => 'timeout',
        ]);
        return;
    }

    $this->response([
        'success' => false,
        'error' => $throwable->getMessage(),
    ]);
}
```

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

## Worker 并发模型

`Invoker` 使用的 alarm 和 `SIGALRM` 都是进程级资源，同一个 worker 不能并发执行多个
带超时的任务。因此：

- `asyncAccept` 默认必须保持为 `false`。
- 每个 worker 同一时间只执行一个同步任务。
- 需要提高并行度时，应增加 `workerNum`，而不是开启 worker 内协程并发。
- 不要在 Driver、`onWorkerStart` 或同一 worker 的其他代码中注册 `SIGALRM`。
- 不要嵌套调用 `Invoker::exec()`。

这里的 timeout 是信号触发的软中断。普通 PHP 代码和可被信号中断的系统调用能够及时
结束；如果第三方扩展长期停留在不可中断的底层 C 调用中，信号可能需要等控制权返回
PHP 后才能生效。

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

## 测试

```bash
composer test
```
