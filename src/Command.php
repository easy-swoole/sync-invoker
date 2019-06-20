<?php


namespace EasySwoole\SyncInvoker;


class Command
{
    protected $action;
    protected $arg = [];

    /**
     * @return mixed
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * @param mixed $action
     */
    public function setAction($action): void
    {
        $this->action = $action;
    }

    /**
     * @return mixed
     */
    public function getArg()
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
}