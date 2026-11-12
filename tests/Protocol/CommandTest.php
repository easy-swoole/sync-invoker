<?php

namespace EasySwoole\SyncInvoker\Tests\Protocol;

use EasySwoole\SyncInvoker\Protocol\Command;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    public function testCommandSurvivesUnixProtocolSerialization(): void
    {
        $command = new Command();
        $command->setAction('blockingTask');
        $command->setArg(['value']);
        $command->setExpireTime(123.5);

        $copy = unserialize(serialize($command));

        self::assertInstanceOf(Command::class, $copy);
        self::assertSame('blockingTask', $copy->getAction());
        self::assertSame(['value'], $copy->getArg());
        self::assertSame(123.5, $copy->getExpireTime());
    }
}
