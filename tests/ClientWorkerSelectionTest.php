<?php

namespace EasySwoole\SyncInvoker\Tests;

use EasySwoole\SyncInvoker\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Swoole\Table;

final class ClientWorkerSelectionTest extends TestCase
{
    private Table $table;
    private Client $client;

    protected function setUp(): void
    {
        $this->table = new Table(2);
        $this->table->column('isAvailable', Table::TYPE_INT, 1);
        $this->table->column('pId', Table::TYPE_INT, 8);
        $this->table->create();
        $this->table->set('0', ['isAvailable' => 1, 'pId' => 1001]);
        $this->table->set('1', ['isAvailable' => 0, 'pId' => 1002]);
        $this->client = new Client(
            [0 => '/tmp/worker-0.sock', 1 => '/tmp/worker-1.sock'],
            $this->table,
            1024,
            1.0
        );
    }

    public function testAcquireOnlyReturnsAnAvailableWorkerAndClaimsIt(): void
    {
        self::assertSame(
            ['workerId' => 0, 'pId' => 1001],
            $this->acquireWorker(microtime(true) + 0.1)
        );
        self::assertSame(0, $this->table->get('0', 'isAvailable'));
        self::assertSame(0, $this->table->get('1', 'isAvailable'));
    }

    public function testAcquireReturnsNullWhenAllWorkersStayBusy(): void
    {
        $this->table->set('0', ['isAvailable' => 0]);
        self::assertNull($this->acquireWorker(microtime(true) + 0.01));
        self::assertSame(0, $this->table->get('0', 'isAvailable'));
        self::assertSame(0, $this->table->get('1', 'isAvailable'));
    }

    public function testReleaseMakesWorkerAvailableAgain(): void
    {
        self::assertSame(
            ['workerId' => 0, 'pId' => 1001],
            $this->acquireWorker(microtime(true) + 0.1)
        );
        $method = new ReflectionMethod(Client::class, 'releaseWorker');
        $method->invoke($this->client, 0);

        self::assertSame(1, $this->table->get('0', 'isAvailable'));
        self::assertSame(
            ['workerId' => 0, 'pId' => 1001],
            $this->acquireWorker(microtime(true) + 0.1)
        );
    }

    public function testWorkerWithoutPidCannotBeAcquired(): void
    {
        $this->table->set('0', ['isAvailable' => 1, 'pId' => 0]);

        self::assertNull($this->acquireWorker(microtime(true) + 0.01));
        self::assertSame(1, $this->table->get('0', 'isAvailable'));
    }

    public function testKillDoesNotTouchRestartedWorker(): void
    {
        $this->table->set('0', ['isAvailable' => 1, 'pId' => 2001]);
        $method = new ReflectionMethod(Client::class, 'killWorker');
        $method->invoke($this->client, 0, 1001);

        self::assertSame(1, $this->table->get('0', 'isAvailable'));
        self::assertSame(2001, $this->table->get('0', 'pId'));
    }

    /** @return array{workerId: int, pId: int}|null */
    private function acquireWorker(float $deadline): ?array
    {
        $method = new ReflectionMethod(Client::class, 'acquireWorker');
        return $method->invoke($this->client, $deadline);
    }
}
