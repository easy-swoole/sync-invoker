# Sync Invoker

## 场景
Swoole4.x后，提供了非常强大的协程能力，让我们可以更好的压榨服务器性能，提高并发。然而，目前PHP在swoole协程生态上，并不是很完善，比如没有协程版本的monogodb客户端，而为了避免在worker中调用了同步阻塞的Api，例如在Http回调中使用了同步的芒果客户端，导致worker退化为同步阻塞，导致没办法完全的发挥协程的优势，
EasySwoole 提供了一个同步程序协程调用转化驱动。

## 原理
启动自定义进程监听UnixSocket，然后worker端调用协程客户端发送命令到自定义进程并处理，然后吧处理结果返回给worker的协程客户端。

## 示例代码

```

use EasySwoole\SyncInvoker\AbstractInvoker;
use EasySwoole\SyncInvoker\SyncInvoker;
use EasySwoole\Component\Singleton;

class MySync extends AbstractInvoker{

    public function test($a,$b)
    {
        return $a+$b;
    }

    public function a()
    {
        return 'this is a';
    }


}

class MySyncInvoker extends SyncInvoker
{
    use Singleton;
}

//实例化 MySyncInvoker

MySyncInvoker::getInstance(new MySync());

$http = new swoole_http_server("0.0.0.0", 9501);

MySyncInvoker::getInstance()->attachServer($http);

$http->on("request", function ($request, $response) {
    $ret = MySyncInvoker::getInstance()->client()->test(1,2);
    var_dump(MySyncInvoker::getInstance()->client()->a());
    var_dump(MySyncInvoker::getInstance()->client()->a(1));
    var_dump(MySyncInvoker::getInstance()->client()->fuck());
    $response->end($ret);
});

$http->start();

```
