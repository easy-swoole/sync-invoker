<?php


namespace EasySwoole\SyncInvoker;


class SyncInvoker
{
    private $client = Client::class;
    private $workerNum = 3;

    public function __construct(AbstractInvoker $worker)
    {

    }

    public function setClientClass(string $class)
    {
        $ref = new \ReflectionClass($class);
        if($ref->isSubclassOf(Client::class)){
            $this->client = $class;
            return true;
        }else{
            return false;
        }
    }

    public function setWorkerNum(int $num):SyncInvoker
    {
        $this->workerNum = $num;
        return $this;
    }

    public function client(?int $workerId = null):Client
    {
        $sock = '';
        return new $this->client($sock);
    }
}