<?php


namespace EasySwoole\SyncInvoker\Protocol;


class Protocol
{
    public const HEADER_SIZE = 4;

    public static function pack(string $data): string
    {
        $length = strlen($data);
        if ($length > 0xffffffff) {
            throw new \LengthException('Packet body exceeds the 32-bit protocol length');
        }
        return pack('N', $length) . $data;
    }

    public static function packDataLength(string $head): int
    {
        return unpack('N', $head)[1];
    }

    public static function unpack(string $data):string
    {
        return substr($data, self::HEADER_SIZE);
    }
}
