<?php


namespace EasySwoole\SyncInvoker;



class Client
{
    private $sock;
    private $timeout;
    private $maxPackageSize;

    public function __construct(string $sock, int $maxPackageSize,float $timeout)
    {
        $this->sock = $sock;
        $this->timeout = $timeout;
        $this->maxPackageSize = $maxPackageSize;
    }

    public function __call($name, $arguments)
    {
        $command = new Command();
        $command->setArg($arguments);
        $command->setAction($name);
        $client = new UnixClient($this->sock,$this->maxPackageSize,$this->timeout);
        $client->send(Protocol::pack(serialize($command)));
        $data = $client->recv($this->timeout);
        if($data){
            $data = Protocol::unpack($data);
            return unserialize($data);
        }
        return null;
    }
}