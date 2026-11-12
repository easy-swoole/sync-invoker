<?php

namespace EasySwoole\SyncInvoker\Tests\Integration;

use EasySwoole\Component\Process\Socket\UnixProcessConfig;
use EasySwoole\SyncInvoker\Driver\AbstractDriver;
use EasySwoole\SyncInvoker\Client\Client;
use EasySwoole\SyncInvoker\Protocol\Command;
use EasySwoole\SyncInvoker\Config;
use EasySwoole\SyncInvoker\Protocol\Protocol;
use EasySwoole\SyncInvoker\Client\Response;
use EasySwoole\SyncInvoker\Client\ResponseStatus;
use EasySwoole\SyncInvoker\Transport\UnixClient;
use EasySwoole\SyncInvoker\Process\Worker;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Table;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkerQueueTest extends TestCase
{
    public function testWorkerExecutesQueuedCallsSerially(): void
    {
        $this->withWorker(function (string $path, Table $table, QueueDriver $driver): void {
            $firstReply = $this->startHoldingCall($path, $table, $driver, 3.0);
            $second = $this->enqueueRecord($path, hrtime(true) / 1e9 + 3.0, 'second');

            self::assertSame(0, $table->get('0', 'isAvailable'));
            self::assertSame(['hold started'], $driver->events);

            $driver->release->push(true);
            self::assertEquals(new Response(ResponseStatus::Ok, 'held'), $firstReply->pop(2.0));
            $reply = $second->recv(2.0);
            self::assertIsString($reply);
            self::assertSame('second', unserialize(Protocol::unpack($reply)));
            self::assertSame(['hold started', 'hold finished', 'second'], $driver->events);
            self::assertSame(1, $table->get('0', 'isAvailable'));
        });
    }

    public function testWorkerSkipsRequestThatExpiredInQueue(): void
    {
        $this->withWorker(function (string $path, Table $table, QueueDriver $driver): void {
            $firstReply = $this->startHoldingCall($path, $table, $driver, 3.0);
            $expireTime = hrtime(true) / 1e9 + 0.15;
            $queued = $this->enqueueRecord($path, $expireTime, 'expired');
            while (($remaining = $expireTime - hrtime(true) / 1e9) > 0) {
                Coroutine::sleep($remaining);
            }

            $driver->release->push(true);
            self::assertEquals(new Response(ResponseStatus::Ok, 'held'), $firstReply->pop(2.0));
            $reply = $queued->recv(2.0);
            self::assertTrue($reply === '' || $reply === false);

            $client = new Client([0 => $path], $table, 4096, 2.0);
            self::assertEquals(new Response(ResponseStatus::Ok, 'probe'), $client->record('probe'));
            self::assertSame(['hold started', 'hold finished', 'probe'], $driver->events);
        });
    }

    public function testClientTimeoutLeavesRunningTaskAndWorkerAlone(): void
    {
        $this->withWorker(function (string $path, Table $table, QueueDriver $driver): void {
            $firstReply = $this->startHoldingCall($path, $table, $driver, 0.15);
            $response = $firstReply->pop(2.0);
            self::assertInstanceOf(Response::class, $response);
            self::assertSame(ResponseStatus::Timeout, $response->status);
            self::assertSame(0, $table->get('0', 'isAvailable'));
            self::assertSame(['hold started'], $driver->events);

            $driver->release->push(true);
            $client = new Client([0 => $path], $table, 4096, 2.0);
            self::assertEquals(new Response(ResponseStatus::Ok, 'probe'), $client->record('probe'));
            self::assertSame(['hold started', 'hold finished', 'probe'], $driver->events);
        });
    }

    public function testWaitingForReplyUsesBudgetLeftAfterSerializingRequest(): void
    {
        $this->withWorker(function (string $path, Table $table, QueueDriver $driver): void {
            $client = new Client([0 => $path], $table, 4096, 0.6);
            $start = hrtime(true);
            $response = $client->hold(new SlowRequestArgument());
            $elapsed = (hrtime(true) - $start) / 1e9;

            self::assertSame(ResponseStatus::Timeout, $response->status);
            self::assertSame(['hold started'], $driver->events);
            self::assertLessThan(0.85, $elapsed);

            $driver->release->push(true);
            $probe = new Client([0 => $path], $table, 4096, 2.0);
            self::assertEquals(new Response(ResponseStatus::Ok, 'probe'), $probe->record('probe'));
        });
    }

    public function testResponseDistinguishesBusinessValuesFromConnectionFailure(): void
    {
        $this->withWorker(function (string $path, Table $table): void {
            $client = new Client([0 => $path], $table, 4096, 2.0);
            self::assertEquals(new Response(ResponseStatus::Ok, null), $client->returnValue(null));
            self::assertEquals(new Response(ResponseStatus::Ok, false), $client->returnValue(false));

            $unreachableClient = new Client([0 => $path . '.missing'], $table, 4096, 2.0);
            $failure = $unreachableClient->returnValue(null);
            self::assertSame(ResponseStatus::TransportError, $failure->status);
            self::assertNull($failure->result);
            self::assertStringContainsString('connect', $failure->msg);
        });
    }

    public function testClientRejectsOversizedRequestBeforeConnecting(): void
    {
        $this->withWorker(function (string $path, Table $table, QueueDriver $driver): void {
            $client = new Client([0 => $path . '.missing'], $table, 512, 2.0);
            $reply = $client->record(str_repeat('x', 512));

            self::assertEquals(new Response(ResponseStatus::PackageTooLarge, msg: 'Request exceeds maxPackageSize'), $reply);
            self::assertSame([], $driver->events);
        }, 512);
    }

    public function testWorkerRejectsOversizedRequestFromHeaderAlone(): void
    {
        $this->withWorker(function (string $path, Table $table, QueueDriver $driver): void {
            $client = new UnixClient($path, 512, 2.0);
            $header = pack('N', 513 - Protocol::HEADER_SIZE);
            self::assertSame(strlen($header), $client->send($header, 2.0));
            self::assertSame(Protocol::pack(Protocol::REQUEST_TOO_LARGE), $client->recv(0.5));
            $client->close();

            $largerClient = new Client([0 => $path], $table, 1024, 2.0);
            $reply = $largerClient->record(str_repeat('x', 512));
            self::assertEquals(new Response(ResponseStatus::PackageTooLarge, msg: 'Request exceeds worker maxPackageSize'), $reply);
            self::assertSame([], $driver->events);
        }, 512);
    }

    public function testWorkerRejectsOversizedResponseAndAllowsExactLimit(): void
    {
        $this->withWorker(function (string $path, Table $table): void {
            $client = new Client([0 => $path], $table, 512, 2.0);
            $atLimit = str_repeat('x', 499);
            self::assertSame(512, strlen(Protocol::pack(serialize($atLimit))));
            self::assertEquals(new Response(ResponseStatus::Ok, $atLimit), $client->makeResponse(499));
            self::assertEquals(
                new Response(ResponseStatus::PackageTooLarge, msg: 'Response exceeds worker maxPackageSize'),
                $client->makeResponse(500)
            );
            self::assertEquals(new Response(ResponseStatus::Ok, 'probe'), $client->record('probe'));
        }, 512);
    }

    private function withWorker(callable $test, int $maxPackageSize = 4096): void
    {
        $directory = sys_get_temp_dir() . '/si-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0700));
        $path = $directory . '/worker.sock';
        $failure = null;
        try {
            Coroutine\run(static function () use ($path, $test, $maxPackageSize, &$failure): void {
                $driver = new QueueDriver();
                $config = new Config();
                $config->setDriver($driver);
                $config->setMaxPackageSize($maxPackageSize);
                $table = new Table(256);
                $table->column('isAvailable', Table::TYPE_INT, 1);
                $table->column('pId', Table::TYPE_INT, 8);
                $table->create();

                $processConfig = new UnixProcessConfig();
                $processConfig->setSocketFile($path);
                $processConfig->setAsyncCallback(false);
                $worker = new class($processConfig) extends Worker {
                    public function getPid(): ?int
                    {
                        return getmypid();
                    }

                    public function stop(): void
                    {
                        if (isset($this->socketServer)) {
                            $this->onShutDown();
                        }
                    }
                };
                Coroutine::create(static function () use ($worker, $config, $table, &$failure): void {
                    try {
                        $worker->run([
                            'config' => $config,
                            'workerIndex' => 0,
                            'workerTable' => $table,
                        ]);
                    } catch (\Throwable $throwable) {
                        $failure = $throwable;
                    }
                });

                try {
                    $deadline = hrtime(true) / 1e9 + 2.0;
                    while ($failure === null && !file_exists($path) && hrtime(true) / 1e9 < $deadline) {
                        Coroutine::sleep(0.001);
                    }
                    if ($failure === null) {
                        self::assertFileExists($path);
                        $test($path, $table, $driver);
                    }
                } catch (\Throwable $throwable) {
                    $failure = $throwable;
                } finally {
                    $driver->release->close();
                    $worker->stop();
                }
            });
            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function startHoldingCall(string $path, Table $table, QueueDriver $driver, float $timeout): Channel
    {
        $reply = new Channel(1);
        $client = new Client([0 => $path], $table, 4096, $timeout);
        Coroutine::create(static fn () => $reply->push($client->hold()));
        self::assertTrue($driver->started->pop(2.0));
        return $reply;
    }

    private function enqueueRecord(string $path, float $expireTime, string $value): UnixClient
    {
        $command = new Command();
        $command->setAction('record');
        $command->setArg([$value]);
        $command->setExpireTime($expireTime);

        $client = new UnixClient($path, 4096, 2.0);
        $packet = Protocol::pack(serialize($command));
        self::assertSame(strlen($packet), $client->send($packet, 2.0));
        return $client;
    }
}

final class SlowRequestArgument
{
    public function __serialize(): array
    {
        Coroutine::sleep(0.4);
        return [];
    }
}

final class QueueDriver extends AbstractDriver
{
    public array $events = [];
    public Channel $started;
    public Channel $release;

    public function __construct()
    {
        parent::__construct();
        $this->started = new Channel(1);
        $this->release = new Channel(1);
    }

    public function hold(): void
    {
        $this->events[] = 'hold started';
        $this->started->push(true);
        $this->release->pop(3.0);
        $this->events[] = 'hold finished';
        $this->response('held');
    }

    public function makeResponse(int $size): void
    {
        $this->response(str_repeat('x', $size));
    }

    public function returnValue(mixed $value): void
    {
        $this->response($value);
    }

    public function record(string $value): void
    {
        $this->events[] = $value;
        $this->response($value);
    }
}
