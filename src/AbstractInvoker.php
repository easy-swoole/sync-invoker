<?php


namespace EasySwoole\SyncInvoker;

use EasySwoole\SyncInvoker\Exception\MethodNotFound;

abstract class AbstractInvoker
{
    private $allowMethods = [];
    private $command;

    function __construct()
    {
        //支持在子类控制器中以private，protected来修饰某个方法不可见
        $list = [];
        $ref = new \ReflectionClass(static::class);
        $public = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($public as $item) {
            array_push($list, $item->getName());
        }
        $this->allowMethods = array_diff($list,
            [
                '__hook', '__destruct',
                '__clone', '__construct', '__call',
                '__callStatic', '__get', '__set',
                '__isset', '__unset', '__sleep',
                '__wakeup', '__toString', '__invoke',
                '__set_state', '__clone', '__debugInfo'
            ]
        );
    }

    function __hook(Command $command)
    {
        $this->command = $command;
        try {
            if (in_array($command->getAction(), $this->allowMethods)) {
                $actionName = $command->getAction();
            } else {
                $actionName = 'methodNotFound';
            }
            return call_user_func([$this,$actionName],...$command->getArg());
        } catch (\Throwable $throwable) {
            //若没有重构onException，直接抛出给上层
            return $this->onException($throwable);
        }
    }

    protected function getCommand():Command
    {
        return $this->command;
    }

    protected function methodNotFound()
    {
        throw new MethodNotFound("method {$this->getCommand()->getAction()} not exit");
    }

    protected function onException(\Throwable $throwable)
    {
        throw $throwable;
    }

    public function callback(?callable $callback)
    {
        if(is_callable($callback)){
            return call_user_func($callback,$this);
        }else{
            return null;
        }
    }
}