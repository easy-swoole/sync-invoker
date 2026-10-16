<?php

namespace EasySwoole\SyncInvoker\Exception;

enum ExceptionStatus: string
{
    case UNKNOWN = 'unknown';
    case CALLBACK = 'callback';
    case TIMEOUT = 'timeout';
}
