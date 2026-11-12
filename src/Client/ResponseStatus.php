<?php

namespace EasySwoole\SyncInvoker\Client;

enum ResponseStatus: string
{
    case Ok = 'ok';
    case Timeout = 'timeout';
    case TransportError = 'transport_error';
    case PackageTooLarge = 'package_too_large';
}
