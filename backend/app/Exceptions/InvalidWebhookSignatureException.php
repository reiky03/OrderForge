<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidWebhookSignatureException extends RuntimeException
{
    public function __construct(string $message = 'The webhook signature or timestamp is invalid.')
    {
        parent::__construct($message);
    }
}
