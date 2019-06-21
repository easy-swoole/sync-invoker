<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\SuperClosure;
use SuperClosure\Serializer;

class Client
{
    private $sock;
    private $timeout;

    final public function __construct(string $sock,float $timeout)
    {
        $this->sock = $sock;
        $this->timeout = $timeout;
    }

    public function __call($name, $arguments)
    {
        $command = new Command();
        $command->setArg($arguments);
        $command->setAction($name);
        $client = new UnixClient($this->sock);
        $client->send(Protocol::pack(serialize($command)));
        $data = $client->recv($this->timeout);
        if($data){
            $data = Protocol::unpack($data);
            return unserialize($data);
        }
        return null;
    }

    public function callback(callable $call)
    {
        if($call instanceof \Closure){
            $call = new SuperClosure($call);
        }
        return $this->__call('callback',[$call]);
    }
}