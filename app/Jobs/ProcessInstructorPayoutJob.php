<?php

namespace App\Jobs;

use App\Models\Payout;
use App\Services\Payout\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInstructorPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max 3 attempts before giving up.
     */
    public int $tries = 3;

    /**
     * Backoff delays in seconds between retries: 30s, 2min, 5min.
     * Prevents hammering the provider on transient failures.
     *
     * @var int[]
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $payoutId)
    {
    }

    public function handle(PayoutService $payoutService): void
    {
        $payout = Payout::find($this->payoutId);

        if (! $payout) {
            return;
        }

        // Idempotency: already settled — nothing to do
        if ($payout->isCompleted()) {
            return;
        }

        // Safety: payout is awaiting manual reconciliation (ADR-007).
        // A blind retry here risks double-paying the instructor.
        // The payout:reconcile command handles this state explicitly.
        if ($payout->isPendingReconciliation()) {
            return;
        }

        $payoutService->process($payout);
    }
}

