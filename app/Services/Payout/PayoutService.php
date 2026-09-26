<?php

namespace App\Services\Payout;

use App\Contracts\PayoutProviderInterface;
use App\DTOs\PayoutResponse;
use App\Exceptions\PayoutProviderTimeoutException;
use App\Models\Payout;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

class PayoutService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected PayoutProviderInterface $provider
    ) {
    }

    /**
     * Initiate a payout record for an instructor for a given period in DRAFT status.
     * Prevents duplicates via unique database constraint.
     */
    public function initiatePayout(User $instructor, string $periodKey): ?Payout
    {
        // 1. Idempotency check: Return existing payout if already initiated
        $existing = Payout::where('instructor_id', $instructor->id)
            ->where('period_key', $periodKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Check instructor withdrawable balance
        $balanceCents = $this->ledgerService->getInstructorBalanceCents($instructor);

        if ($balanceCents <= 0) {
            return null; // Nothing to withdraw
        }

        $idempotencyKey = "PAYOUT_{$instructor->id}_{$periodKey}";

        try {
            return Payout::create([
                'instructor_id' => $instructor->id,
                'period_key' => $periodKey,
                'amount_cents' => $balanceCents,
                'status' => Payout::STATUS_DRAFT,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            // Concurrent creation race condition safety
            return Payout::where('instructor_id', $instructor->id)
                ->where('period_key', $periodKey)
                ->first();
        }
    }

    /**
     * Process a payout through the state machine and external provider.
     * Concurrency-safe via atomic cache lock and pessimistic database row locking.
     */
    public function process(Payout $payout): Payout
    {
        $lockKey = "payout_lock_{$payout->id}";

        return Cache::lock($lockKey, 60)->block(10, function () use ($payout) {
            // Step 1: Place escrow hold and transition to PROCESSING within atomic DB transaction
            DB::transaction(function () use ($payout) {
                /** @var Payout $lockedPayout */
                $lockedPayout = Payout::where('id', $payout->id)->lockForUpdate()->firstOrFail();

                if ($lockedPayout->status === Payout::STATUS_COMPLETED) {
                    return; // Already finished
                }

                if ($lockedPayout->status === Payout::STATUS_PENDING_RECONCILIATION) {
                    return; // Awaiting status reconciliation; cannot retry blindly
                }

                if ($lockedPayout->status === Payout::STATUS_DRAFT) {
                    // Two-phase ledger hold (ADR-006): Move funds from Payable to Escrow In-Flight
                    $this->ledgerService->holdPayoutFunds($lockedPayout);
                    $lockedPayout->update(['status' => Payout::STATUS_PROCESSING]);
                }

                $payout->status = $lockedPayout->status;
            });

            $payout->refresh();

            if ($payout->status === Payout::STATUS_COMPLETED || $payout->status === Payout::STATUS_PENDING_RECONCILIATION) {
                return $payout;
            }

            // Step 2: Call external payment provider (OUTSIDE of DB transaction to avoid holding DB locks over HTTP)
            try {
                $response = $this->provider->sendPayout($payout);
            } catch (PayoutProviderTimeoutException $e) {
                // Network timeout: status is unknown (ADR-007)
                $response = PayoutResponse::unknown($e->getMessage());
            } catch (\Throwable $e) {
                $response = PayoutResponse::unknown("Provider exception: " . $e->getMessage());
            }

            // Step 3: Handle result and transition state
            return DB::transaction(function () use ($payout, $response) {
                /** @var Payout $lockedPayout */
                $lockedPayout = Payout::where('id', $payout->id)->lockForUpdate()->firstOrFail();

                if ($response->isSuccess()) {
                    // Success: Clear escrow and record funds leaving the gateway
                    $this->ledgerService->completePayout($lockedPayout, $response->transferId);

                    $lockedPayout->update([
                        'status' => Payout::STATUS_COMPLETED,
                        'provider_transfer_id' => $response->transferId,
                        'processed_at' => now(),
                        'failure_reason' => null,
                    ]);
                } elseif ($response->isFailed()) {
                    // Permanent failure: Release escrow hold back to instructor payable
                    $this->ledgerService->releasePayoutHold($lockedPayout, $response->failureReason ?? 'Payment rejected');

                    $lockedPayout->update([
                        'status' => Payout::STATUS_FAILED,
                        'failure_reason' => $response->failureReason,
                    ]);
                } else {
                    // Unknown / Timeout: Transition to PENDING_RECONCILIATION (ADR-007)
                    // Escrow remains FROZEN to protect against double payout
                    $lockedPayout->update([
                        'status' => Payout::STATUS_PENDING_RECONCILIATION,
                        'failure_reason' => $response->failureReason ?? 'Timeout awaiting provider confirmation',
                    ]);
                }

                return $lockedPayout;
            });
        });
    }

    /**
     * Reconcile a payout stuck in PENDING_RECONCILIATION by querying the provider's status endpoint.
     */
    public function reconcile(Payout $payout): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING_RECONCILIATION) {
            return $payout;
        }

        $lockKey = "payout_reconcile_lock_{$payout->id}";

        return Cache::lock($lockKey, 60)->block(10, function () use ($payout) {
            // Query provider transfer status using idempotency key
            $response = $this->provider->getTransferStatus($payout->idempotency_key);

            return DB::transaction(function () use ($payout, $response) {
                /** @var Payout $lockedPayout */
                $lockedPayout = Payout::where('id', $payout->id)->lockForUpdate()->firstOrFail();

                if ($lockedPayout->status !== Payout::STATUS_PENDING_RECONCILIATION) {
                    return $lockedPayout;
                }

                if ($response->isSuccess()) {
                    $this->ledgerService->completePayout($lockedPayout, $response->transferId);

                    $lockedPayout->update([
                        'status' => Payout::STATUS_COMPLETED,
                        'provider_transfer_id' => $response->transferId,
                        'reconciled_at' => now(),
                        'failure_reason' => null,
                    ]);
                } elseif ($response->isFailed()) {
                    $this->ledgerService->releasePayoutHold($lockedPayout, $response->failureReason ?? 'Failed upon reconciliation');

                    $lockedPayout->update([
                        'status' => Payout::STATUS_FAILED,
                        'reconciled_at' => now(),
                        'failure_reason' => $response->failureReason,
                    ]);
                }

                return $lockedPayout;
            });
        });
    }
}
