<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Services\Payout\PayoutService;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payout:reconcile';

    protected $description = 'Reconcile unresolved payouts stuck in PENDING_RECONCILIATION with payment provider';

    public function handle(PayoutService $payoutService): int
    {
        $this->info('Scanning payouts pending reconciliation...');

        $total = Payout::where('status', Payout::STATUS_PENDING_RECONCILIATION)->count();

        if ($total === 0) {
            $this->info('No payouts require reconciliation at this time.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} payout(s) requiring reconciliation.");

        Payout::query()
            ->where('status', Payout::STATUS_PENDING_RECONCILIATION)
            ->chunkById(50, function ($payouts) use ($payoutService) {
                foreach ($payouts as $payout) {
                    $this->line("Reconciling Payout #{$payout->id} (Idempotency Key: {$payout->idempotency_key})...");
                    $resolved = $payoutService->reconcile($payout);
                    $this->info("Payout #{$payout->id} resolved to: {$resolved->status} (Transfer ID: {$resolved->provider_transfer_id})");
                }
            });

        return self::SUCCESS;
    }
}

