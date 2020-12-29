<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use Swoole\Server;

class SyncInvoker
{
    protected $config;

    public function __construct(Config $config = null)
    {
        if($config == null){
            $config = new Config();
        }
        $this->config = $config;
    }

    function getConfig():Config
    {
        return $this->config;
    }

    public function __generateWorkerProcess():array
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
                'config'=>$this->config
            ]);
            $ret[] = new Worker($config);
        }
        return $ret;
    }

    function invoke(float $timeout = null)
    {
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        mt_srand();
        $id = mt_rand(0,$this->config->getWorkerNum() -1);
        return new Client($this->getSocket($id),$this->getConfig()->getMaxPackageSize(),$timeout);
    }

    private function getSocket(int $index)
    {
        return "{$this->config->getTempDir()}/SyncInvoker.Worker.{$index}.sock";
    }

    public function attachServer(Server $server)
    {
        $list = $this->__generateWorkerProcess();
        /** @var AbstractUnixProcess $process */
        foreach ($list as $process){
            $server->addProcess($process->getProcess());
        }
    }
}