<?php

namespace EasySwoole\SyncInvoker\Exception;

class InvokeException extends Exception
{
    private ExceptionStatus $status = ExceptionStatus::UNKNOWN;

    public function getStatus(): ExceptionStatus
    {
        return $this->status;
    }

    public function setStatus(ExceptionStatus $status): void
    {
        $this->status = $status;
    }
}
