<?php


namespace EasySwoole\SyncInvoker;

use EasySwoole\SyncInvoker\Exception\Exception;

abstract class AbstractDriver
{
    private $allowMethods = [];
    private $request;
    private $response;

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
                '__set_state', '__clone', '__debugInfo','response'
            ]
        );
    }

    function __hook(Command $command)
    {
        $this->request = $command;
        try {
            if($this->onRequest($command) === false){
                return $this->response;
            }
            if (in_array($command->getAction(), $this->allowMethods)) {
                $actionName = $command->getAction();
            } else {
                $actionName = 'actionNotFound';
            }
            call_user_func([$this,$actionName],...$command->getArg());
            $this->afterRequest();
            return $this->response;
        } catch (\Throwable $throwable) {
            $this->onException($throwable);
            return $this->response;
        }
    }


    protected function onRequest(Command $command):bool
    {
        return true;
    }

    protected function afterRequest()
    {

    }

    protected function getRequest():Command
    {
        return $this->request;
    }

    function response($response): void
    {
        $this->response = $response;
    }

    protected function actionNotFound()
    {
        throw new Exception("action {$this->getRequest()->getAction()} not exit");
    }

    protected function onException(\Throwable $throwable)
    {
        throw $throwable;
    }

    public function callback(?callable $callback)
    {
        if(is_callable($callback)){
            $ret = call_user_func($callback,$this);
            if($ret !== null && $this->response === null){
                $this->response = $ret;
            }
        }else{
            throw new Exception('callback method require a callable callback');
        }
    }
}