<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use Swoole\Coroutine\Socket;

class Worker extends AbstractUnixProcess
{
    private $config;
    function run($arg)
    {
        /** @var Config $config */
        $config = $arg['config'];
        $this->config = $config;
        if(is_callable($config->getOnWorkerStart())){
            call_user_func($config->getOnWorkerStart(),$this);
        }
        parent::run($arg);
    }

    function onAccept(Socket $socket)
    {
        /** @var Config $config */
        $config = $this->config;
        $header = $socket->recvAll(4,1);
        if(strlen($header) != 4){
            $socket->close();
            return;
        }
        $allLength = Protocol::packDataLength($header);
        $data = $socket->recvAll($allLength,1);
        if(strlen($data) == $allLength){
            try{
                $command = \Opis\Closure\unserialize($data);
                $reply = null;
                if($command instanceof Command){
                    $driver = clone $config->getDriver();
                    $reply = $driver->__hook($command);
                }
                $socket->sendAll(Protocol::pack(\Opis\Closure\serialize($reply)));
            }catch (\Throwable $exception){
                throw $exception;
            } finally {
                $socket->close();
            }
        }else{
            $socket->close();
        }
    }
}