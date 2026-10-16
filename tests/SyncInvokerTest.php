<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\SyncInvoker\Config;
use EasySwoole\SyncInvoker\SyncInvoker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Table;

final class SyncInvokerTest extends TestCase
{
    public function testInvokeHasFifteenSecondNonNullableDefault(): void
    {
        $parameter = (new ReflectionMethod(SyncInvoker::class, 'invoke'))->getParameters()[0];

        self::assertFalse($parameter->allowsNull());
        self::assertSame(15.0, $parameter->getDefaultValue());
        self::assertSame(15.0, (new Config())->getTimeout());
    }

    public function testWorkerTableIsCreatedDuringConstruction(): void
    {
        $config = new Config();
        $config->setWorkerNum(2);
        $invoker = new SyncInvoker($config);

        $property = new ReflectionProperty(SyncInvoker::class, 'workerTable');
        $table = $property->getValue($invoker);

        self::assertInstanceOf(Table::class, $table);
        self::assertSame(0, $table->count());
    }
}
