# Sync Invoker

## 场景
Swoole4.x后，提供了非常强大的协程能力，让我们可以更好的压榨服务器性能，提高并发。然而，目前PHP在swoole协程生态上，并不是很完善，比如没有协程版本的monogodb客户端，而为了避免在worker中调用了同步阻塞的Api，例如在Http回调中使用了同步的芒果客户端，导致worker退化为同步阻塞，导致没办法完全的发挥协程的优势，
EasySwoole 提供了一个同步程序协程调用转化驱动。

## 原理
启动自定义进程监听UnixSocket，然后worker端调用协程客户端发送命令到自定义进程并处理，然后把处理结果返回给worker的协程客户端。

## 示例代码

```php
use EasySwoole\SyncInvoker\AbstractDriver;
use EasySwoole\SyncInvoker\SyncInvoker;
use EasySwoole\SyncInvoker\Worker;
require 'vendor/autoload.php';

class Driver extends AbstractDriver
{
    function plus($a,$b)
    {
        $this->response($a + $b);
    }

    protected function actionNotFound()
    {
        $this->response($this->getRequest()->getAction().' not found');
    }

}

$invoker = new SyncInvoker();
$invoker->getConfig()->setDriver(new Driver());
$invoker->getConfig()->setOnWorkerStart(function (Worker $worker){
    var_dump('worker start at Id '.$worker->getArg()['workerIndex']);
});

$http = new swoole_http_server("0.0.0.0", 9501);

$invoker->attachServer($http);

$http->on("request", function ($request, $response)use($invoker) {
    $ret = $invoker->invoke()->plus(1,2);
    var_dump($ret);

    $ret = $invoker->invoke()->plus2(1,2);
    var_dump($ret);

    $ret = $invoker->invoke()->callback(function (Driver $mySync){
        $mySync->response('this is callback');
        //return 'this is callback';
    });
    $response->end($ret);
});

$http->start();
```
