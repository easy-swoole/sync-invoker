<?php


namespace EasySwoole\SyncInvoker;


use Swoole\Coroutine\Client;

class UnixClient
{
    private Client $client;

    public function __construct(string $unixSock, int $maxPackageSize, float $timeout)
    {
        $this->client = new Client(SWOOLE_UNIX_STREAM);
        $this->client->set(
            [
                'open_length_check' => true,
                'package_length_type'   => 'N',
                'package_length_offset' => 0,
                'package_body_offset'   => 4,
                'package_max_length'    => $maxPackageSize,
                'timeout'=>$timeout
            ]
        );
        $this->client->connect($unixSock,0,3);
    }

    public function __destruct()
    {
        if($this->client->isConnected()){
            $this->client->close();
        }
    }

    public function send(string $rawData): int|false
    {
        if($this->client->isConnected()){
            return $this->client->send($rawData);
        }else{
            return false;
        }
    }

    public function recv(float $timeout = 0.1): string|false
    {
        if($this->client->isConnected()){
            return $this->client->recv($timeout);
        }else{
            return false;
        }
    }

    public function getErrorCode(): int
    {
        return $this->client->errCode;
    }
}
