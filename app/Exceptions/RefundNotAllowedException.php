<?php

namespace App\Exceptions;

use RuntimeException;

class RefundNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'Refund is not allowed for this subscription.')
    {
        parent::__construct($message);
    }
}
