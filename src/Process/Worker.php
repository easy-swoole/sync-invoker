<?php


namespace EasySwoole\SyncInvoker\Process;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\SyncInvoker\Config;
use EasySwoole\SyncInvoker\Protocol\Command;
use EasySwoole\SyncInvoker\Protocol\Protocol;
use Swoole\Coroutine\Socket;
use Swoole\Process;
use Swoole\Table;

class Worker extends AbstractUnixProcess
{
    private Config $config;
    private Table $workerTable;
    private int $workerIndex;
    private int $executed = 0;

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
        $this->workerTable->set(
            (string) $this->workerIndex,
            [
                'isAvailable' => 1,
                'pId' => (int) ($this->getPid() ?? getmypid())
            ]
        );
        parent::run($arg);
    }

    public function onAccept(Socket $socket): void
    {
        $this->setAvailable(false);
        $recycle = false;
        try {
            $header = $socket->recvAll(Protocol::HEADER_SIZE, 1);
            if (!is_string($header) || strlen($header) !== Protocol::HEADER_SIZE) {
                return;
            }
            $length = Protocol::packDataLength($header);
            $maxPackageSize = $this->config->getMaxPackageSize();
            if (Protocol::exceedsPackageSize($length, $maxPackageSize)) {
                $socket->sendAll(Protocol::pack(Protocol::REQUEST_TOO_LARGE), 1);
                return;
            }
            if ($length === 0) {
                return;
            }
            $data = $socket->recvAll($length, 1);
            if (!is_string($data) || strlen($data) !== $length) {
                return;
            }

            $command = unserialize($data);
            $expireTime = $command instanceof Command ? $command->getExpireTime() : null;
            if ($expireTime === null || !is_finite($expireTime) || $expireTime <= hrtime(true) / 1e9) {
                return;
            }

            $driver = $this->config->getDriver();
            $maxExecuteNum = $this->config->getMaxExecuteNum();
            $recycle = $maxExecuteNum > 0 && ++$this->executed >= $maxExecuteNum;
            $reply = $driver->__hook($command);
            if ($expireTime <= hrtime(true) / 1e9) {
                return;
            }
            $payload = serialize($reply);
            if (Protocol::exceedsPackageSize(strlen($payload), $maxPackageSize)) {
                $payload = Protocol::RESPONSE_TOO_LARGE;
            }
            $packet = Protocol::pack($payload);
            $remaining = $expireTime - hrtime(true) / 1e9;
            if ($remaining > 0) {
                $socket->sendAll($packet, $remaining);
            }
        } finally {
            $socket->close();
            if ($recycle) {
                $this->isShutDown = true;
                $this->workerTable->del((string) $this->workerIndex);
                Process::kill(getmypid(), SIGTERM);
            } else {
                $this->setAvailable(true);
            }
        }
    }

    private function setAvailable(bool $available): void
    {
        $this->workerTable->set(
            (string) $this->workerIndex,
            ['isAvailable' => (int) $available]
        );
    }
}
