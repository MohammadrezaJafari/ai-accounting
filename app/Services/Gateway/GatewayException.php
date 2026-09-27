<?php

namespace App\Services\Gateway;

use RuntimeException;

class GatewayException extends RuntimeException
{
    public function __construct(public int $status, string $message, public string $type = 'invalid_request_error')
    {
        parent::__construct($message);
    }
}
