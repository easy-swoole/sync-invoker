<?php

namespace EasySwoole\SyncInvoker\Client;

final class Response
{
    public function __construct(
        public readonly ResponseStatus $status,
        public readonly mixed $result = null,
        public readonly string $msg = '',
    ) {
    }
}
