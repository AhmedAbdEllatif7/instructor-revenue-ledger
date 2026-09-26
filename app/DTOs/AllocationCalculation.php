<?php

namespace App\DTOs;

use LogicException;

readonly class AllocationCalculation
{
    /**
     * @param array<int, InstructorShare> $instructorShares
     */
    public function __construct(
        public int $subscriptionId,
        public string $periodKey,
        public int $recognizedAmountCents,
        public int $platformFeeCents,
        public int $instructorPoolCents,
        public bool $isBreakage,
        public array $instructorShares,
    ) 
    {
        $this->assertInvariant();
    }

    /**
     * Verify that zero cents are lost or invented (ADR-004 invariant).
     */
    public function assertInvariant(): void
    {
        $instructorsTotal = $this->totalInstructorsCents();
        $sum = $this->platformFeeCents + $instructorsTotal;
        if ($sum !== $this->recognizedAmountCents) {
            throw new LogicException("Financial invariant violated! Recognized amount ({$this->recognizedAmountCents}) !== Platform fee ({$this->platformFeeCents}) + Instructor shares ({$instructorsTotal})");
        }
    }

    public function totalInstructorsCents(): int
    {
        $total = 0;
        foreach ($this->instructorShares as $share) {
            $total += $share->amountCents;
        }

        return $total;
    }
}
