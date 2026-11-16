<?php

namespace EasySwoole\SyncInvoker\Transport;

use EasySwoole\SyncInvoker\Protocol\Protocol;
use Swoole\Coroutine\Socket;

class UnixClient
{
    private Socket $socket;
    private bool $connected;

    public function __construct(string $unixSock, private float $timeout)
    {
        $this->socket = new Socket(AF_UNIX, SOCK_STREAM, 0);
        $this->connected = $this->socket->connect($unixSock, 0, $timeout);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        if ($this->connected) {
            $this->connected = false;
            $this->socket->close();
        }
    }

    public function send(string $rawData, float $timeout = 0): int|false
    {
        if (!$this->connected) {
            return false;
        }
        return $this->socket->sendAll($rawData, $timeout ?: $this->timeout);
    }

    public function recv(float $timeout = 0.1): string|false
    {
        if (!$this->connected) {
            return false;
        }

        $timeout = $timeout ?: $this->timeout;
        $deadline = hrtime(true) / 1e9 + $timeout;
        $header = $this->socket->recvAll(Protocol::HEADER_SIZE, $timeout);
        if ($header === false || strlen($header) !== Protocol::HEADER_SIZE) {
            return false;
        }

        $length = Protocol::packDataLength($header);
        $body = PacketReader::readBody($this->socket, $length, $deadline - hrtime(true) / 1e9);
        return $body === false ? false : $header . $body;
    }

    public function getErrorCode(): int
    {
        return $this->socket->errCode;
    }
}
