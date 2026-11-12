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

    public function testServerNameSeparatesWorkerSocketPaths(): void
    {
        $first = new Config(['serverName' => 'ServerA1', 'workerNum' => 1, 'tempDir' => '/tmp']);
        $second = new Config(['workerNum' => 1, 'tempDir' => '/tmp']);
        $second->setServerName('ServerB2');

        foreach ([$first, $second] as $config) {
            $worker = (new SyncInvoker($config))->__generateWorkerProcess()[0];
            self::assertSame(
                '/tmp/SyncInvoker.' . $config->getServerName() . '.0.sock',
                $worker->getConfig()->getSocketFile()
            );
        }
    }

    public function testServerNameRejectsNonAlphanumericValues(): void
    {
        $config = new Config();
        foreach (['', 'server-name', 'server_name', 'server/name', "name\n", '名字'] as $name) {
            try {
                $config->setServerName($name);
                self::fail('Expected an invalid serverName to be rejected');
            } catch (\InvalidArgumentException) {
                self::assertSame('EasySwoole', $config->getServerName());
            }
        }
    }

    public function testServerNameIsValidatedWhenConfigIsLoadedFromArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(['serverName' => '../server']);
    }

    public function testMaxExecuteNumDefaultsToUnlimitedAndCanBeConfigured(): void
    {
        $config = new Config();
        self::assertSame(0, $config->getMaxExecuteNum());
        $config->setMaxExecuteNum(100);
        self::assertSame(100, $config->getMaxExecuteNum());
        self::assertSame(2, (new Config(['maxExecuteNum' => 2]))->getMaxExecuteNum());

        $this->expectException(\InvalidArgumentException::class);
        $config->restore(['maxExecuteNum' => -1]);
    }

    public function testGeneratedWorkersDisableAsyncAccept(): void
    {
        $invoker = new SyncInvoker();

        foreach($invoker->__generateWorkerProcess() as $worker){
            self::assertFalse($worker->getConfig()->isAsyncCallback());
        }
    }
}
