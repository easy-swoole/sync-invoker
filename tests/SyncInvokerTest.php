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

    public function testWorkerInfoReturnsProcessTableRows(): void
    {
        $invoker = new SyncInvoker();
        $property = new ReflectionProperty(SyncInvoker::class, 'workerTable');
        /** @var Table $table */
        $table = $property->getValue($invoker);
        $table->set('5', ['isAvailable' => 1, 'pId' => 12345]);
        $table->set('9', ['isAvailable' => 0, 'pId' => 12346]);

        self::assertSame([
            0 => ['isAvailable' => 1, 'pId' => 12345],
            1 => ['isAvailable' => 0, 'pId' => 12346],
        ], $invoker->workerInfo());
    }
}
