<?php


namespace EasySwoole\SyncInvoker\Protocol;


class Protocol
{
    public const HEADER_SIZE = 4;

    // Single-byte replies reserved for size errors. Serialized PHP values are longer.
    public const REQUEST_TOO_LARGE = 'R';
    public const RESPONSE_TOO_LARGE = 'S';

    public static function exceedsPackageSize(int $bodyLength, int $maxPackageSize): bool
    {
        return $bodyLength + self::HEADER_SIZE > $maxPackageSize;
    }

    public static function pack(string $data): string
    {
        return pack('N', strlen($data)).$data;
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