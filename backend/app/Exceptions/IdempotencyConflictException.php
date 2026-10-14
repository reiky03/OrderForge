<?php

namespace App\Exceptions;

class IdempotencyConflictException extends \RuntimeException
{
    /**
     * Create a new class instance.
     */
    public function __construct(string $message = 'This idempotency key was already used with a different request.')
    {
        parent::__construct($message);
    }
}
