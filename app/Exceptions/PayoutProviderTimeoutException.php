<?php

namespace App\Exceptions;

class PayoutProviderTimeoutException extends PayoutProviderException
{
    public function __construct(
        string $message = 'Payment provider connection timed out without response.',
        ?int $payoutId = null,
        ?string $idempotencyKey = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $payoutId, $idempotencyKey, 0, $previous);
    }
}
