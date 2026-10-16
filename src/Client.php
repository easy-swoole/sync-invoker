<?php


namespace EasySwoole\SyncInvoker;

use Swoole\Coroutine;
use Swoole\Table;


class Client
{
    /** @var array<int, string> */
    private array $sockets;
    private Table $workerTable;
    private float $timeout;
    private int $maxPackageSize;

    /** @param array<int, string> $sockets */
    public function __construct(array $sockets, Table $workerTable, int $maxPackageSize, float $timeout)
    {
        $this->sockets = $sockets;
        $this->workerTable = $workerTable;
        $this->timeout = $timeout;
        $this->maxPackageSize = $maxPackageSize;
    }

    public function __call(string $name, array $arguments): mixed
    {
        $deadline = microtime(true) + $this->timeout;
        $workerId = $this->acquireWorker($deadline);
        if($workerId === null){
            return null;
        }

        $remainingTimeout = $deadline - microtime(true);
        if($remainingTimeout <= 0){
            $this->releaseWorker($workerId);
            return null;
        }

        $command = new Command();
        $command->setArg($arguments);
        $command->setAction($name);
        $command->setTimeout($remainingTimeout);
        try{
            $client = new UnixClient($this->sockets[$workerId], $this->maxPackageSize, $remainingTimeout);
            $sent = $client->send(Protocol::pack(serialize($command)));
        }catch(\Throwable $throwable){
            $this->releaseWorker($workerId);
            throw $throwable;
        }
        if($sent === false){
            $this->releaseWorker($workerId);
            return null;
        }
        // Leave a small window for the worker to serialize and send the result
        // after its execution timer fires.
        $data = $client->recv($remainingTimeout + 0.1);
        if($data){
            $data = Protocol::unpack($data);
            return unserialize($data);
        }
        return null;
    }

    private function acquireWorker(float $deadline): ?int
    {
        $workerIds = array_keys($this->sockets);
        while(microtime(true) < $deadline){
            shuffle($workerIds);
            foreach($workerIds as $workerId){
                $available = $this->workerTable->decr(
                    (string) $workerId,
                    'isAvailable'
                );
                if($available === 0){
                    return $workerId;
                }
                $this->workerTable->incr(
                    (string) $workerId,
                    'isAvailable'
                );
            }
            if(Coroutine::getCid() >= 0){
                Coroutine::sleep(0.001);
            }else{
                usleep(1000);
            }
        }
        return null;
    }

    private function releaseWorker(int $workerId): void
    {
        $this->workerTable->set(
            (string) $workerId,
            ['isAvailable' => 1]
        );
    }
}
