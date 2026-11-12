<?php

namespace EasySwoole\SyncInvoker\Client;

use EasySwoole\SyncInvoker\Protocol\Command;
use EasySwoole\SyncInvoker\Protocol\Protocol;
use EasySwoole\SyncInvoker\Transport\UnixClient;
use Swoole\Coroutine;
use Swoole\Table;

class Client
{
    /** @param array<int, string> $sockets */
    public function __construct(
        private array $sockets,
        private Table $workerTable,
        private int $maxPackageSize,
        private float $timeout
    ) {
    }

    public function __call(string $name, array $arguments): Response
    {
        $expireTime = hrtime(true) / 1e9 + $this->timeout;
        $workerId = $this->waitForWorker($expireTime);
        if ($workerId === null) {
            return $this->timeoutResponse();
        }

        $command = new Command();
        $command->setArg($arguments);
        $command->setAction($name);
        $command->setExpireTime($expireTime);
        $payload = serialize($command);

        $remaining = $expireTime - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            return $this->timeoutResponse();
        }
        if (Protocol::exceedsPackageSize(strlen($payload), $this->maxPackageSize)) {
            return new Response(ResponseStatus::PackageTooLarge, msg: 'Request exceeds maxPackageSize');
        }
        $packet = Protocol::pack($payload);
        $remaining = $expireTime - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            return $this->timeoutResponse();
        }
        $client = new UnixClient($this->sockets[$workerId], $this->maxPackageSize, $remaining);
        try {
            if (!$client->isConnected()) {
                return $this->socketFailure($client, $expireTime, 'connect');
            }

            $remaining = $expireTime - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                return $this->timeoutResponse();
            }
            if ($client->send($packet, $remaining) !== strlen($packet)) {
                return $this->socketFailure($client, $expireTime, 'send request');
            }

            $remaining = $expireTime - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                return $this->timeoutResponse();
            }
            $data = $client->recv($remaining);
            if ($data === false || $data === '') {
                return $this->socketFailure($client, $expireTime, 'receive response');
            }
            if (hrtime(true) / 1e9 >= $expireTime) {
                return $this->timeoutResponse();
            }

            return $this->decodeResponse($data, $expireTime);
        } finally {
            $client->close();
        }
    }

    private function decodeResponse(string $data, float $expireTime): Response
    {
        $payload = Protocol::unpack($data);
        $sizeError = match ($payload) {
            Protocol::REQUEST_TOO_LARGE => 'Request exceeds worker maxPackageSize',
            Protocol::RESPONSE_TOO_LARGE => 'Response exceeds worker maxPackageSize',
            default => null,
        };
        if ($sizeError !== null) {
            return new Response(ResponseStatus::PackageTooLarge, msg: $sizeError);
        }
        $result = @unserialize($payload);
        if (hrtime(true) / 1e9 >= $expireTime) {
            return $this->timeoutResponse();
        }
        if ($result === false && $payload !== 'b:0;') {
            return new Response(ResponseStatus::TransportError, msg: 'Invalid worker response');
        }
        return new Response(ResponseStatus::Ok, $result);
    }

    private function timeoutResponse(): Response
    {
        return new Response(ResponseStatus::Timeout, msg: 'Call deadline exceeded');
    }

    private function socketFailure(UnixClient $client, float $expireTime, string $operation): Response
    {
        $error = $client->getErrorCode();
        if (hrtime(true) / 1e9 >= $expireTime || (defined('SOCKET_ETIMEDOUT') && $error === SOCKET_ETIMEDOUT)) {
            return $this->timeoutResponse();
        }

        return new Response(ResponseStatus::TransportError, msg: "Could not {$operation} (socket error {$error})");
    }

    private function waitForWorker(float $expireTime): ?int
    {
        $workerId = $this->selectWorker();
        while ($workerId === null && hrtime(true) / 1e9 < $expireTime) {
            if (Coroutine::getCid() >= 0) {
                Coroutine::sleep(0.001);
            } else {
                usleep(1000);
            }
            $workerId = $this->selectWorker();
        }
        return $workerId;
    }

    private function selectWorker(): ?int
    {
        $idle = [];
        $busy = [];
        foreach (array_keys($this->sockets) as $workerId) {
            $worker = $this->workerTable->get((string) $workerId);
            if ($worker === false || $worker['pId'] <= 0) {
                continue;
            }
            if ($worker['isAvailable'] === 1) {
                $idle[] = $workerId;
            } else {
                $busy[] = $workerId;
            }
        }
        $candidates = $idle ?: $busy;
        if ($candidates === []) {
            return null;
        }
        return $candidates[array_rand($candidates)];
    }
}
