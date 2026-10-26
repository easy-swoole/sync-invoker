<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\SyncInvoker\Command;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    public function testCommandSurvivesUnixProtocolSerialization(): void
    {
        $command = new Command();
        $command->setAction('blockingTask');
        $command->setArg(['value']);

        $copy = unserialize(serialize($command));

        self::assertInstanceOf(Command::class, $copy);
        self::assertSame('blockingTask', $copy->getAction());
        self::assertSame(['value'], $copy->getArg());
    }
}
