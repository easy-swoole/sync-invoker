# Sync Invoker

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
