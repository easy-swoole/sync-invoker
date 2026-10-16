<?php


namespace EasySwoole\SyncInvoker;


class Command
{
    protected ?string $action = null;
    protected array $arg = [];
    protected float $timeout = 15.0;

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

    /**
     * @param mixed $arg
     */
    public function setArg(array $arg): void
    {
        $this->arg = $arg;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }
}
