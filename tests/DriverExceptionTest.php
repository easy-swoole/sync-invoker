<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\Component\Invoker;
use EasySwoole\SyncInvoker\AbstractDriver;
use EasySwoole\SyncInvoker\Command;
use EasySwoole\SyncInvoker\Exception\ExceptionStatus;
use EasySwoole\SyncInvoker\Exception\InvokeException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DriverExceptionTest extends TestCase
{
    public function testCallbackExceptionHasCallbackStatus(): void
    {
        $driver = new ExceptionDriver();
        $command = $this->command('fail');

        $result = $driver->__hook($command);

        self::assertSame(ExceptionStatus::CALLBACK, $result['status']);
        self::assertInstanceOf(RuntimeException::class, $result['previous']);
    }

    public function testInvokerTimeoutHasTimeoutStatus(): void
    {
        $driver = new ExceptionDriver();
        $command = $this->command('block');

        $result = Invoker::exec(
            static fn (): mixed => $driver->__hook($command),
            50000
        );

        self::assertSame(ExceptionStatus::TIMEOUT, $result['status']);
        self::assertInstanceOf(RuntimeException::class, $result['previous']);
    }

    public function testExplicitInvokeExceptionStatusIsPreserved(): void
    {
        $driver = new ExceptionDriver();

        $result = $driver->__hook($this->command('explicit'));

        self::assertSame(ExceptionStatus::TIMEOUT, $result['status']);
        self::assertNull($result['previous']);
    }

    private function command(string $action): Command
    {
        $command = new Command();
        $command->setAction($action);
        return $command;
    }
}

final class ExceptionDriver extends AbstractDriver
{
    public function fail(): void
    {
        throw new RuntimeException('callback failed');
    }

    public function block(): void
    {
        usleep(500000);
    }

    public function explicit(): void
    {
        $exception = new InvokeException('explicit status');
        $exception->setStatus(ExceptionStatus::TIMEOUT);
        throw $exception;
    }

    protected function onException(\Throwable $throwable): void
    {
        self::assertInvokerException($throwable);
        $this->response([
            'status' => $throwable->getStatus(),
            'previous' => $throwable->getPrevious(),
        ]);
    }

    private static function assertInvokerException(\Throwable $throwable): void
    {
        if(!$throwable instanceof InvokeException){
            throw $throwable;
        }
    }
}
