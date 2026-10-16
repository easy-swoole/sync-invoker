<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\Component\Invoker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InvokerTimeoutTest extends TestCase
{
    public function testSignalInterruptsBlockingCall(): void
    {
        $startedAt = microtime(true);

        try{
            Invoker::exec(static function (): void {
                usleep(500000);
            }, 50000);
            self::fail('The blocking call was not interrupted');
        }catch(RuntimeException $exception){
            self::assertSame('func timeout', $exception->getMessage());
            self::assertLessThan(0.3, microtime(true) - $startedAt);
        }
    }
}
