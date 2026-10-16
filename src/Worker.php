<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Invoker;
use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use Swoole\Coroutine\Socket;
use Swoole\Table;

class Worker extends AbstractUnixProcess
{
    private Config $config;
    private Table $workerTable;
    private int $workerIndex;

    public function run(mixed $arg): void
    {
        /** @var Config $config */
        $config = $arg['config'];
        $this->config = $config;
        $this->workerTable = $arg['workerTable'];
        $this->workerIndex = $arg['workerIndex'];
        if(is_callable($config->getOnWorkerStart())){
            call_user_func($config->getOnWorkerStart(),$this);
        }
        $this->releaseWorker();
        parent::run($arg);
    }

    public function onAccept(Socket $socket): void
    {
        try{
            $config = $this->config;
            $header = $socket->recvAll(4,1);
            if(!is_string($header) || strlen($header) !== 4){
                return;
            }
            $allLength = Protocol::packDataLength($header);
            $data = $socket->recvAll($allLength,1);
            if(is_string($data) && strlen($data) === $allLength){
                $command = unserialize($data);
                $reply = null;
                if($command instanceof Command){
                    $driver = $config->getDriver();
                    $timeout = $command->getTimeout();
                    $reply = Invoker::exec(
                        static function () use ($driver, $command): mixed {
                            return $driver->__hook($command);
                        },
                        max(1, (int) round($timeout * 1000000))
                    );
                }
                $socket->sendAll(Protocol::pack(serialize($reply)));
            }
        } finally {
            $socket->close();
            $this->releaseWorker();
        }
    }

    private function releaseWorker(): void
    {
        $this->workerTable->set(
            (string) $this->workerIndex,
            ['isAvailable' => 1]
        );
    }
}
