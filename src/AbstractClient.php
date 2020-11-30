<?php


namespace EasySwoole\SyncInvoker;


abstract class AbstractClient
{
    abstract public function __construct(string $sock,float $timeout,int $maxPackageSize);
    abstract public function __call($name, $arguments);
    public function callback(callable $call)
    {
        return $this->__call('callback',[$call]);
    }
}