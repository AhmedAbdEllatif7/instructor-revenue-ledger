<?php

namespace App\DTOs;

readonly class InstructorShare
{
    public function __construct(
        public int $instructorId,
        public int $watchedSeconds,
        public float $sharePercentage,
        public int $amountCents,
    ) {
    }

    public function toArray(): array
    {
        return [
            'instructor_id' => $this->instructorId,
            'watched_seconds' => $this->watchedSeconds,
            'share_percentage' => $this->sharePercentage,
            'amount_cents' => $this->amountCents,
        ];
    }
}
