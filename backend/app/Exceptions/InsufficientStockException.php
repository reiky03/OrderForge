<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requested stock adjustment would make available stock negative.');
    }
}
