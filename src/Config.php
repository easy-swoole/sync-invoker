<?php


namespace EasySwoole\SyncInvoker;


use EasySwoole\Spl\SplBean;

class Config extends SplBean
{
    protected $serverName = 'EasySwoole';
    protected $workerNum = 3;
    protected $tempDir;
    /** @var AbstractDriver */
    protected $driver;
    protected $maxPackageSize = 1024*1024*2;
    protected $timeout = 3.0;
    /** @var callable|null */
    protected $onWorkerStart;
    protected $asyncAccept = true;
    /**
     * @return int
     */
    public function getWorkerNum(): int
    {
        return $this->workerNum;
    }

    /**
     * @param int $workerNum
     */
    public function setWorkerNum(int $workerNum): void
    {
        $this->workerNum = $workerNum;
    }

    /**
     * @return mixed
     */
    public function getTempDir()
    {
        return $this->tempDir;
    }

    /**
     * @param mixed $tempDir
     */
    public function setTempDir($tempDir): void
    {
        $this->tempDir = $tempDir;
    }

    /**
     * @return AbstractDriver
     */
    public function getDriver(): AbstractDriver
    {
        return $this->driver;
    }

    /**
     * @param AbstractDriver $driver
     */
    public function setDriver(AbstractDriver $driver): void
    {
        $this->driver = $driver;
    }

    /**
     * @return float|int
     */
    public function getMaxPackageSize()
    {
        return $this->maxPackageSize;
    }

    /**
     * @param float|int $maxPackageSize
     */
    public function setMaxPackageSize($maxPackageSize): void
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
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * @param float $timeout
     */
    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
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

    /**
     * @return bool
     */
    public function isAsyncAccept(): bool
    {
        return $this->asyncAccept;
    }

    /**
     * @param bool $asyncAccept
     */
    public function setAsyncAccept(bool $asyncAccept): void
    {
        $this->asyncAccept = $asyncAccept;
    }

    protected function initialize(): void
    {
        if(empty($this->tempDir)){
            $this->tempDir = sys_get_temp_dir();
        }
    }
}