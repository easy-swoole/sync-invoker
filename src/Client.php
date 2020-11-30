<?php


namespace EasySwoole\SyncInvoker;



class Client extends AbstractClient
{
    private $sock;
    private $timeout;
    private $maxPackageSize;

    public function __construct(string $sock, float $timeout, int $maxPackageSize)
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
        $client = new UnixClient($this->sock,$this->maxPackageSize);
        $client->send(Protocol::pack(\Opis\Closure\serialize($command)));
        $data = $client->recv($this->timeout);
        if($data){
            $data = Protocol::unpack($data);
            return \Opis\Closure\unserialize($data);
        }
        return null;
    }
}