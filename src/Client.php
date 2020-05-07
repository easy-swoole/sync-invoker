<?php


namespace EasySwoole\SyncInvoker;



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
        $client->send(Protocol::pack(\Opis\Closure\serialize($command)));
        $data = $client->recv($this->timeout);
        if($data){
            $data = Protocol::unpack($data);
            return \Opis\Closure\unserialize($data);
        }
        return null;
    }

    public function callback(callable $call)
    {
        return $this->__call('callback',[$call]);
    }
}