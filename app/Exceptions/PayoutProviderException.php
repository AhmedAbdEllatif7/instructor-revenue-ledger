<?php

namespace App\Exceptions;

use RuntimeException;

class PayoutProviderException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?int $payoutId = null,
        public readonly ?string $idempotencyKey = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create an instance with payout context attached for logging.
     */
    public static function withContext(
        string $message,
        ?int $payoutId = null,
        ?string $idempotencyKey = null,
        ?\Throwable $previous = null
    ): static {
        return new static($message, $payoutId, $idempotencyKey, 0, $previous);
    }
}
