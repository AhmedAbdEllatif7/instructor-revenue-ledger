<?php

namespace App\Exceptions;

use RuntimeException;

class UnbalancedLedgerTransactionException extends RuntimeException
{
    public function __construct(int $debits, int $credits, string $refNumber)
    {
        parent::__construct(
            "Double-entry transaction '{$refNumber}' is unbalanced! Debits ({$debits} cents) !== Credits ({$credits} cents)."
        );
    }
}
