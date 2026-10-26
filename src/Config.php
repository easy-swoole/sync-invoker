<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Spl\SplBean;

class Config extends SplBean
{
    protected string $serverName = 'EasySwoole';
    protected int $workerNum = 3;
    protected string $tempDir;
    protected AbstractDriver $driver;
    protected int $maxPackageSize = 1024*1024*2;
    /** @var callable|null */
    protected mixed $onWorkerStart = null;

    public function getWorkerNum(): int
    {
        return $this->workerNum;
    }

    public function setWorkerNum(int $workerNum): void
    {
        $this->workerNum = $workerNum;
    }

    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    public function setTempDir(string $tempDir): void
    {
        $this->tempDir = $tempDir;
    }

    public function getDriver(): AbstractDriver
    {
        return $this->driver;
    }

    public function setDriver(AbstractDriver $driver): void
    {
        $this->driver = $driver;
    }

    public function getMaxPackageSize(): int
    {
        return $this->maxPackageSize;
    }

    public function setMaxPackageSize(int $maxPackageSize): void
    {
        $this->maxPackageSize = $maxPackageSize;
    }

    /**
     * @return string
     */
    public function getServerName(): string
    {
        return $this->serverName;
    }

    /**
     * @param string $serverName
     */
    public function setServerName(string $serverName): void
    {
        $this->serverName = $serverName;
    }

    /**
     * @return callable|null
     */
    public function getOnWorkerStart(): ?callable
    {
        return $this->onWorkerStart;
    }

    /**
     * @param callable|null $onWorkerStart
     */
    public function setOnWorkerStart(?callable $onWorkerStart): void
    {
        $this->onWorkerStart = $onWorkerStart;
    }

    protected function initialize(): void
    {
        if(empty($this->tempDir)){
            $this->tempDir = sys_get_temp_dir();
        }
    }
}
