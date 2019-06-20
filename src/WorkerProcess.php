<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use Swoole\Coroutine\Socket;

class WorkerProcess extends AbstractUnixProcess
{
    function onAccept(Socket $socket)
    {
        /** @var AbstractInvoker $invoker */
        $invoker = $this->getConfig()->getArg();
        $header = $socket->recvAll(4,1);
        if(strlen($header) != 4){
            $socket->close();
            return;
        }
        $allLength = Protocol::packDataLength($header);
        $data = $socket->recvAll($allLength,1);
        if(strlen($data) == $allLength){
            $command = unserialize($data);
            $reply = null;
            if($command instanceof Command){
                $reply = $invoker->__hook($command);
            }
            $socket->sendAll(Protocol::pack(serialize($reply)));
            $socket->close();
        }else{
            $socket->close();
            return;
        }
    }
}