<?php


namespace EasySwoole\SyncInvoker;

use Swoole\Coroutine;
use Swoole\Process;
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
        $worker = $this->acquireWorker($deadline);
        if($worker === null){
            return null;
        }
        $workerId = $worker['workerId'];
        $workerPid = $worker['pId'];

        $remainingTimeout = $deadline - microtime(true);
        if($remainingTimeout <= 0){
            $this->releaseWorker($workerId);
            return null;
        }

        $command = new Command();
        $command->setArg($arguments);
        $command->setAction($name);
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
        $data = $client->recv($remainingTimeout);
        if(is_string($data) && $data !== ''){
            $data = Protocol::unpack($data);
            return unserialize($data);
        }
        if($data === false && $client->getErrorCode() === SOCKET_ETIMEDOUT){
            $this->killWorker($workerId, $workerPid);
        }
        return null;
    }

    /** @return array{workerId: int, pId: int}|null */
    private function acquireWorker(float $deadline): ?array
    {
        $workerIds = array_keys($this->sockets);
        while(microtime(true) < $deadline){
            shuffle($workerIds);
            foreach($workerIds as $workerId){
                $worker = $this->workerTable->get((string) $workerId);
                if($worker === false || (int) $worker['pId'] <= 0){
                    continue;
                }
                $available = $this->workerTable->decr(
                    (string) $workerId,
                    'isAvailable'
                );
                if($available === 0){
                    $pid = (int) $this->workerTable->get((string) $workerId, 'pId');
                    if($pid > 0){
                        return ['workerId' => $workerId, 'pId' => $pid];
                    }
                    $this->releaseWorker($workerId);
                    continue;
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

    private function killWorker(int $workerId, int $workerPid): void
    {
        $currentPid = (int) $this->workerTable->get((string) $workerId, 'pId');
        if($currentPid !== $workerPid){
            return;
        }
        $this->workerTable->set(
            (string) $workerId,
            ['isAvailable' => -1]
        );
        Process::kill($workerPid, SIGKILL);
    }
}
