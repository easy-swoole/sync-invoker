<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use EasySwoole\SyncInvoker\Exception\Exception;
use Swoole\Server;
use Swoole\Table;

class SyncInvoker
{
    protected Config $config;

    protected bool $hasAttach = false;

    protected Table $workerTable;

    public function __construct(?Config $config = null)
    {
        if($config == null){
            $config = new Config();
        }
        $this->config = $config;
        $this->workerTable = new Table(256);
        $this->workerTable->column('isAvailable', Table::TYPE_INT, 1);
        $this->workerTable->create();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function __generateWorkerProcess(): array
    {
        $ret = [];
        for ($i = 0;$i < $this->config->getWorkerNum();$i++){
            $config = new UnixProcessConfig();
            $config->setProcessGroup("{$this->config->getServerName()}.SyncInvoker");
            $config->setProcessName("{$this->config->getServerName()}.SyncInvoker.Worker.{$i}");
            $config->setSocketFile($this->getSocket($i));
            $config->setAsyncCallback($this->config->isAsyncAccept());
            $config->setArg([
                'workerIndex'=>$i,
                'workerTable'=>$this->workerTable,
                'config'=>$this->config
            ]);
            $ret[] = new Worker($config);
        }
        return $ret;
    }

    public function invoke(float $timeout = 15.0): Client
    {
        if($timeout <= 0){
            throw new \InvalidArgumentException('timeout must be greater than 0');
        }
        if(!$this->hasAttach){
            throw new Exception('SyncInvoker must be attached to server before invoke');
        }
        $sockets = [];
        for($i = 0; $i < $this->config->getWorkerNum(); $i++){
            $sockets[$i] = $this->getSocket($i);
        }
        return new Client(
            $sockets,
            $this->workerTable,
            $this->getConfig()->getMaxPackageSize(),
            $timeout
        );
    }

    private function getSocket(int $index): string
    {
        return "{$this->config->getTempDir()}/SyncInvoker.Worker.{$index}.sock";
    }

    public function attachServer(Server $server): void
    {
        if(!$this->hasAttach){
            $list = $this->__generateWorkerProcess();
            /** @var AbstractUnixProcess $process */
            foreach ($list as $process){
                $server->addProcess($process->getProcess());
            }
            $this->hasAttach = true;
        }else{
            throw new Exception("can not attach server twice");
        }

    }

}
