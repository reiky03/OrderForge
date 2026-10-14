<?php

namespace App\Exceptions;

class InvalidOrderStateException extends \RuntimeException
{
    /**
     * Create a new class instance.
     */
    public function __construct(string $message = 'The order cannot transition from its current state.')
    {
        parent::__construct($message);
    }
}
