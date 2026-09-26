<?php

namespace App\Services\Allocation;

use App\DTOs\AllocationCalculation;
use App\DTOs\InstructorShare;
use App\Models\InstructorAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPeriodAllocation;
use App\Models\WatchLog;
use App\Services\Ledger\LedgerService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevenueAllocationService
{
    public const DEFAULT_PLATFORM_FEE_PERCENT = 30;

    public function __construct(protected ?LedgerService $ledgerService = null) {
        $this->ledgerService = $this->ledgerService ?? app(LedgerService::class);
    }

    /**
     * Calculate monthly revenue allocation without writing to the database.
     */
    public function calculate(Subscription $subscription,string $periodKey,int $platformFeePercent = self::DEFAULT_PLATFORM_FEE_PERCENT): AllocationCalculation {
        $recognizedAmountCents = $subscription->monthly_price_cents;

        // Query consumption for this student in the given accounting period
        $logs = WatchLog::query()
            ->where('student_id', $subscription->student_id)
            ->where('period_key', $periodKey)
            ->select('instructor_id', DB::raw('SUM(seconds_watched) as total_seconds'))
            ->groupBy('instructor_id')
            ->having('total_seconds', '>', 0)
            ->get();

        $totalSeconds = (int) $logs->sum('total_seconds');

        // Edge Case: Breakage (Student watched 0 seconds during active month - ADR-002)
        if ($totalSeconds === 0) {
            return new AllocationCalculation(
                subscriptionId: $subscription->id,
                periodKey: $periodKey,
                recognizedAmountCents: $recognizedAmountCents,
                platformFeeCents: $recognizedAmountCents, // 100% breakage revenue to platform
                instructorPoolCents: 0,
                isBreakage: true,
                instructorShares: [],
            );
        }

        // Calculate initial platform share and instructor pool (ADR-002)
        // Platform cut = floor(recognizedAmount * percent / 100)
        $initialPlatformFeeCents = (int) floor(($recognizedAmountCents * $platformFeePercent) / 100);
        $instructorPoolCents = $recognizedAmountCents - $initialPlatformFeeCents;

        // Calculate each instructor's share using floor rounding (ADR-004)
        $instructorShares = [];
        $totalInstructorAllocated = 0;

        foreach ($logs as $log) {
            $seconds = (int) $log->total_seconds;
            // Instructor share = floor(pool * seconds / totalSeconds)
            $shareCents = (int) floor(($instructorPoolCents * $seconds) / $totalSeconds);
            $sharePercentage = round(($seconds / $totalSeconds) * 100, 4);

            $instructorShares[] = new InstructorShare(
                instructorId: (int) $log->instructor_id,
                watchedSeconds: $seconds,
                sharePercentage: $sharePercentage,
                amountCents: $shareCents,
            );

            $totalInstructorAllocated += $shareCents;
        }

        // Remainder Absorption (ADR-004):
        // Platform absorbs any fractional cents left over from floor rounding.
        // Guarantees: Recognized Amount = Platform Fee + Sum(Instructor Shares)
        $finalPlatformFeeCents = $recognizedAmountCents - $totalInstructorAllocated;

        return new AllocationCalculation(
            subscriptionId: $subscription->id,
            periodKey: $periodKey,
            recognizedAmountCents: $recognizedAmountCents,
            platformFeeCents: $finalPlatformFeeCents,
            instructorPoolCents: $instructorPoolCents,
            isBreakage: false,
            instructorShares: $instructorShares,
        );
    }

    /**
     * Atomically record revenue allocation in the database with strict idempotency.
     */
    public function allocate(Subscription $subscription, string $periodKey, int $platformFeePercent = self::DEFAULT_PLATFORM_FEE_PERCENT): SubscriptionPeriodAllocation {
        // App-level Idempotency Check: return existing allocation if already processed
        $existing = SubscriptionPeriodAllocation::query()
            ->with('instructorAllocations')
            ->where('subscription_id', $subscription->id)
            ->where('period_key', $periodKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $calc = $this->calculate($subscription, $periodKey, $platformFeePercent);

        try {
            return DB::transaction(function () use ($calc) {
                $periodAllocation = SubscriptionPeriodAllocation::create([
                    'subscription_id' => $calc->subscriptionId,
                    'period_key' => $calc->periodKey,
                    'recognized_amount_cents' => $calc->recognizedAmountCents,
                    'platform_fee_cents' => $calc->platformFeeCents,
                    'instructor_pool_cents' => $calc->instructorPoolCents,
                    'is_breakage' => $calc->isBreakage,
                ]);

                foreach ($calc->instructorShares as $share) {
                    InstructorAllocation::create([
                        'period_allocation_id' => $periodAllocation->id,
                        'instructor_id' => $share->instructorId,
                        'watched_seconds' => $share->watchedSeconds,
                        'share_percentage' => $share->sharePercentage,
                        'amount_cents' => $share->amountCents,
                    ]);
                }

                $periodAllocation->load('instructorAllocations');

                // Book revenue recognition into the double-entry ledger atomically (ADR-003 & ADR-005)
                $this->ledgerService->recordRevenueAllocation($periodAllocation);

                return $periodAllocation;
            });
        } catch (QueryException $e) {
            // Concurrency safety: If another worker inserted between check and transaction,
            // the database unique constraint prevents duplicates; fetch and return existing.
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'sub_period_alloc_unique')) {
                return SubscriptionPeriodAllocation::query()
                    ->with('instructorAllocations')
                    ->where('subscription_id', $subscription->id)
                    ->where('period_key', $periodKey)
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * Batch allocate all active subscriptions for a given period.
     *
     * @return int Number of subscriptions allocated
     */
    public function allocatePeriod(
        string $periodKey,
        int $platformFeePercent = self::DEFAULT_PLATFORM_FEE_PERCENT
    ): int {
        // Determine start and end of period key (e.g. '2026-09')
        $periodStart = Carbon::createFromFormat('Y-m', $periodKey)->startOfMonth();
        $periodEnd = Carbon::createFromFormat('Y-m', $periodKey)->endOfMonth();

        $processedCount = 0;

        // Process subscriptions in chunks for scalability
        Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('starts_at', '<=', $periodEnd)
            ->where('ends_at', '>=', $periodStart)
            ->chunkById(200, function (Collection $subscriptions) use ($periodKey, $platformFeePercent, &$processedCount) {
                foreach ($subscriptions as $subscription) {
                    $this->allocate($subscription, $periodKey, $platformFeePercent);
                    $processedCount++;
                }
            });

        return $processedCount;
    }
}
