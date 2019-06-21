<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Component\Process\Socket\UnixProcessConfig;

class SyncInvoker
{
    private $client = Client::class;
    private $workerNum = 3;
    private $tempDir;
    private $invoker;

    public function __construct(AbstractInvoker $worker)
    {
        $this->invoker = $worker;
        $this->tempDir = sys_get_temp_dir();
    }

    public function setClientClass(string $class)
    {
        $ref = new \ReflectionClass($class);
        if($ref->isSubclassOf(Client::class)){
            $this->client = $class;
            return true;
        }else{
            return false;
        }
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

    public function client(?int $workerId = null):Client
    {
        if(empty($workerId)){
            mt_srand();
            $workerId = rand(1,$this->workerNum);
        }
        $socket = $this->tempDir.'/'.md5(static::class)."{$workerId}.sock";
        return new $this->client($socket);
    }

    public function generateProcess():array
    {
        $ret = [];
        for ($i = 1;$i <= $this->workerNum;$i++){
            $config = new UnixProcessConfig();
            $config->setProcessName('SyncInvoker');
            $socket = $this->tempDir.'/'.md5(static::class)."{$i}.sock";
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