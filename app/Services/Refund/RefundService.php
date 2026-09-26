<?php

namespace App\Services\Refund;

use App\Exceptions\RefundNotAllowedException;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(protected LedgerService $ledgerService)
    {
    }

    /**
     * Process a full refund for a subscription (ADR-003 & ADR-008).
     *
     * Only the unrecognized (still-deferred) portion is refunded.
     * Already-recognized months stay with instructors — no clawbacks ever.
     *
     * Idempotent: calling twice returns the same result without side effects.
     *
     * @throws RefundNotAllowedException
     */
    public function refund(Subscription $subscription): int
    {
        if ($subscription->status === Subscription::STATUS_REFUNDED) {
            // Already refunded — return 0 change to preserve idempotency
            return 0;
        }

        if ($subscription->status === Subscription::STATUS_CANCELED) {
            throw new RefundNotAllowedException(
                "Subscription #{$subscription->id} is already canceled and cannot be refunded."
            );
        }

        if ($subscription->status === Subscription::STATUS_EXPIRED) {
            throw new RefundNotAllowedException(
                "Subscription #{$subscription->id} has expired. Refunds are only allowed on active subscriptions."
            );
        }

        // Calculate unrecognized (still-deferred) amount:
        // Total paid - sum of already-recognized monthly tranches
        $totalPaidCents = $subscription->total_price_cents;

        $recognizedCents = (int) $subscription
            ->periodAllocations()
            ->sum('recognized_amount_cents');

        $refundAmountCents = $totalPaidCents - $recognizedCents;

        if ($refundAmountCents <= 0) {
            // All revenue has already been recognized — nothing to refund
            // Mark subscription canceled (not refunded) since instructors earned everything
            $subscription->update([
                'status' => Subscription::STATUS_CANCELED,
                'canceled_at' => now(),
            ]);

            return 0;
        }

        return DB::transaction(function () use ($subscription, $refundAmountCents) {
            // 1. Record the double-entry refund in the ledger (idempotent via reference number)
            $this->ledgerService->recordRefund($subscription, $refundAmountCents);

            // 2. Mark the subscription as refunded
            $subscription->update([
                'status' => Subscription::STATUS_REFUNDED,
                'canceled_at' => now(),
            ]);

            // 3. Mark all subscription payments as refunded
            $subscription->payments()->each(function (SubscriptionPayment $payment) use ($refundAmountCents) {
                // If the refund amount equals the full payment — full refund
                // If less (some months recognized) — partial refund
                $newStatus = $refundAmountCents >= $payment->amount_cents
                    ? SubscriptionPayment::STATUS_REFUNDED
                    : SubscriptionPayment::STATUS_PARTIALLY_REFUNDED;

                $payment->update([
                    'status' => $newStatus,
                    'refunded_at' => now(),
                ]);
            });

            return $refundAmountCents;
        });
    }

    /**
     * Calculate the refundable amount for a subscription without side effects.
     */
    public function calculateRefundableAmountCents(Subscription $subscription): int
    {
        if (in_array($subscription->status, [
            Subscription::STATUS_REFUNDED,
            Subscription::STATUS_CANCELED,
            Subscription::STATUS_EXPIRED,
        ], true)) {
            return 0;
        }

        $totalPaidCents = $subscription->total_price_cents;
        $recognizedCents = (int) $subscription->periodAllocations()->sum('recognized_amount_cents');

        return max(0, $totalPaidCents - $recognizedCents);
    }
}
