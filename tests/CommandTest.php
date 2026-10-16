<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\SyncInvoker\Command;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    public function testTimeoutSurvivesUnixProtocolSerialization(): void
    {
        $command = new Command();
        $command->setAction('blockingTask');
        $command->setArg(['value']);
        $command->setTimeout(0.25);

        $copy = unserialize(serialize($command));

        self::assertInstanceOf(Command::class, $copy);
        self::assertSame('blockingTask', $copy->getAction());
        self::assertSame(['value'], $copy->getArg());
        self::assertSame(0.25, $copy->getTimeout());
    }
}
