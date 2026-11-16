<?php

namespace EasySwoole\SyncInvoker\Transport;

use Swoole\Coroutine\Socket;

final class PacketReader
{
    public static function readBody(Socket $socket, int $length, float $timeout): string|false
    {
        $deadline = hrtime(true) / 1e9 + $timeout;
        $body = '';
        while ($length > 0) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                return false;
            }

            // Grow the buffer as data arrives.
            $chunk = $socket->recv(min($length, 65536), $remaining);
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $body .= $chunk;
            $length -= strlen($chunk);
        }
        return $body;
    }
}
