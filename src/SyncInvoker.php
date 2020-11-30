<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Component\Process\Socket\UnixProcessConfig;

class SyncInvoker
{
    private $client = null;
    private $workerNum = 3;
    private $tempDir;
    private $invoker;
    private $maxPackageSize = 1024*1024*10;//10M

    public function __construct(AbstractInvoker $worker)
    {
        $this->invoker = $worker;
        $this->tempDir = sys_get_temp_dir();
    }

    /**
     * @param float|int $maxPackageSize
     */
    public function setMaxPackageSize($maxPackageSize): void
    {
        $this->maxPackageSize = $maxPackageSize;
    }

    public function setClient(AbstractClient  $client)
    {
        $this->client = $client;
    }

    public function setTempDir(string $dir)
    {
        $this->tempDir = $dir;
        return $this;
    }

    public function setWorkerNum(int $num):SyncInvoker
    {
        $this->workerNum = $num;
        return $this;
    }

    public function client(?int $workerId = null,float $timeout = 3):AbstractClient
    {
        if($workerId === null){
            mt_srand();
            $workerId = rand(0,$this->workerNum - 1);
        }
        $socket = $this->tempDir."/SyncInvoker.Worker.{$workerId}.sock";
        if($this->client){
            $this->client = new Client($socket,$timeout,$this->maxPackageSize);
        }
        return $this->client;
    }

    public function generateProcess():array
    {
        $ret = [];
        for ($i = 0;$i < $this->workerNum;$i++){
            $config = new UnixProcessConfig();
            $config->setProcessGroup('SyncInvoker');
            $config->setProcessName("SyncInvoker.Worker.{$i}");
            $socket = $this->tempDir."/SyncInvoker.Worker.{$i}.sock";
            $config->setSocketFile($socket);
            $config->setArg($this->invoker);
            $ret[] = new WorkerProcess($config);
        }
        return $ret;
    }


    public function attachServer(\swoole_server $server)
    {
        $list = $this->generateProcess();
        /** @var AbstractUnixProcess $process */
        foreach ($list as $process){
            $server->addProcess($process->getProcess());
        }
    }
}