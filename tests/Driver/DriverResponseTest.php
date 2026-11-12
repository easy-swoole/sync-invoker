<?php

namespace EasySwoole\SyncInvoker\Tests\Driver;

use EasySwoole\SyncInvoker\Driver\AbstractDriver;
use EasySwoole\SyncInvoker\Protocol\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DriverResponseTest extends TestCase
{
    #[DataProvider('nextRequests')]
    public function testResponseBelongsToCurrentRequest(string $action, ?string $expected): void
    {
        $driver = new ResponseDriver();
        self::assertSame('first', $driver->__hook($this->command('respond')));
        self::assertSame($expected, $driver->__hook($this->command($action)));
    }

    public static function nextRequests(): array
    {
        return [
            'action sets no response' => ['noResponse', null],
            'onRequest short-circuits without a response' => ['blocked', null],
            'onRequest short-circuits with its own response' => ['blockedWithResponse', 'blocked'],
        ];
    }

    private function command(string $action): Command
    {
        $command = new Command();
        $command->setAction($action);
        return $command;
    }
}

final class ResponseDriver extends AbstractDriver
{
    public function respond(): void
    {
        $this->response('first');
    }

    public function noResponse(): void
    {
    }

    protected function onRequest(Command $command): bool
    {
        if ($command->getAction() === 'blockedWithResponse') {
            $this->response('blocked');
            return false;
        }
        return $command->getAction() !== 'blocked';
    }
}
