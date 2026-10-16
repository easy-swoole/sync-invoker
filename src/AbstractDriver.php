<?php


namespace EasySwoole\SyncInvoker;

use EasySwoole\SyncInvoker\Exception\Exception;
use EasySwoole\SyncInvoker\Exception\ExceptionStatus;
use EasySwoole\SyncInvoker\Exception\InvokeException;

abstract class AbstractDriver
{
    /** @var list<string> */
    private array $allowMethods = [];
    private ?Command $request = null;
    private mixed $response = null;

    public function __construct()
    {
        //支持在子类控制器中以private，protected来修饰某个方法不可见
        $list = [];
        $ref = new \ReflectionClass(static::class);
        $public = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($public as $item) {
            $list[] = $item->getName();
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

    public function __hook(Command $command): mixed
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
            if($throwable instanceof InvokeException){
                $exception = $throwable;
            }else{
                $exception = new InvokeException(
                    $throwable->getMessage(),
                    (int) $throwable->getCode(),
                    $throwable
                );
            }
            if(!($throwable instanceof InvokeException) &&
                $throwable instanceof \RuntimeException
                && $throwable->getMessage() === 'func timeout'
            ){
                $exception->setStatus(ExceptionStatus::TIMEOUT);
            }elseif(!($throwable instanceof InvokeException)){
                $exception->setStatus(ExceptionStatus::CALLBACK);
            }
            $this->onException($exception);
            return $this->response;
        }
    }


    protected function onRequest(Command $command): bool
    {
        return true;
    }

    protected function afterRequest(): void
    {

    }

    protected function getRequest(): Command
    {
        return $this->request;
    }

    public function response(mixed $response): void
    {
        $this->response = $response;
    }

    protected function actionNotFound(): void
    {
        throw new Exception("action {$this->getRequest()->getAction()} not exit");
    }

    protected function onException(\Throwable $throwable): void
    {
        throw $throwable;
    }
}
