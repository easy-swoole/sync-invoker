<?php


namespace EasySwoole\SyncInvoker\Protocol;


class Command
{
    protected ?string $action = null;
    protected array $arg = [];
    // Absolute deadline in seconds on the local monotonic clock.
    protected ?float $expireTime = null;

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getArg(): array
    {
        return $this->arg;
    }

    public function setArg(array $arg): void
    {
        $this->arg = $arg;
    }

    public function getExpireTime(): ?float
    {
        return $this->expireTime;
    }

    public function setExpireTime(float $expireTime): void
    {
        $this->expireTime = $expireTime;
    }
}
