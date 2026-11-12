<?php

namespace EasySwoole\SyncInvoker\Tests\Client;

use EasySwoole\SyncInvoker\Client\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Swoole\Table;

final class ClientWorkerSelectionTest extends TestCase
{
    private Table $table;
    private Client $client;

    protected function setUp(): void
    {
        $this->table = new Table(256);
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

    public function testPrefersIdleWorkerWithoutChangingItsState(): void
    {
        self::assertSame(0, $this->selectWorker());
        self::assertSame(1, $this->table->get('0', 'isAvailable'));
        self::assertSame(0, $this->table->get('1', 'isAvailable'));
    }

    public function testSelectsBusyWorkerWhenAllAreBusy(): void
    {
        $this->table->set('0', ['isAvailable' => 0]);

        self::assertContains($this->selectWorker(), [0, 1]);
        self::assertSame(0, $this->table->get('0', 'isAvailable'));
        self::assertSame(0, $this->table->get('1', 'isAvailable'));
    }

    public function testSkipsWorkersWithoutPid(): void
    {
        $this->table->set('0', ['pId' => 0]);
        self::assertSame(1, $this->selectWorker());

        $this->table->del('1');
        self::assertNull($this->selectWorker());
    }

    private function selectWorker(): ?int
    {
        return (new ReflectionMethod(Client::class, 'selectWorker'))->invoke($this->client);
    }
}
