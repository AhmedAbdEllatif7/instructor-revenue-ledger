<?php

namespace App\DTOs;

readonly class PayoutResponse
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public string $status,
        public ?string $transferId = null,
        public ?string $failureReason = null
    ) {
    }

    public static function success(string $transferId): self
    {
        return new self(
            status: self::STATUS_SUCCESS,
            transferId: $transferId,
            failureReason: null
        );
    }

    public static function failed(string $failureReason): self
    {
        return new self(
            status: self::STATUS_FAILED,
            transferId: null,
            failureReason: $failureReason
        );
    }

    public static function unknown(string $reason): self
    {
        return new self(
            status: self::STATUS_UNKNOWN,
            transferId: null,
            failureReason: $reason
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isUnknown(): bool
    {
        return $this->status === self::STATUS_UNKNOWN;
    }
}
