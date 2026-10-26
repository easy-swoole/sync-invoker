<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\SyncInvoker\AbstractDriver;
use EasySwoole\SyncInvoker\Command;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DriverExceptionTest extends TestCase
{
    public function testCallbackReceivesOriginalException(): void
    {
        $driver = new ExceptionDriver();
        $command = $this->command('fail');

        $result = $driver->__hook($command);

        self::assertInstanceOf(RuntimeException::class, $result);
        self::assertSame('callback failed', $result->getMessage());
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

    protected function onException(\Throwable $throwable): void
    {
        $this->response($throwable);
    }
}
