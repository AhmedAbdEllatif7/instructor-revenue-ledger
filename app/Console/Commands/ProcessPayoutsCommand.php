<?php

namespace App\Console\Commands;

use App\Jobs\ProcessInstructorPayoutJob;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payout\PayoutService;
use Illuminate\Console\Command;

class ProcessPayoutsCommand extends Command
{
    protected $signature = 'payout:process 
                            {period_key : Accounting period in YYYY-MM format} 
                            {--sync : Process immediately instead of queuing}';

    protected $description = 'Orchestrate monthly instructor payouts for all eligible instructors';

    public function handle(LedgerService $ledgerService, PayoutService $payoutService): int
    {
        $periodKey = (string) $this->argument('period_key');
        $sync = (bool) $this->option('sync');

        if (! preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            $this->error("Invalid period key format '{$periodKey}'. Expected YYYY-MM (e.g. 2026-09).");

            return self::FAILURE;
        }

        $this->info("Scanning eligible instructors for period: {$periodKey}...");

        $initiatedCount = 0;
        $skippedCount = 0;

        User::query()
            ->where('role', User::ROLE_INSTRUCTOR)
            ->chunkById(100, function ($instructors) use ($ledgerService, $payoutService, $periodKey, $sync, &$initiatedCount, &$skippedCount) {
                foreach ($instructors as $instructor) {
                    $balance = $ledgerService->getInstructorBalanceCents($instructor);

                    if ($balance <= 0) {
                        $skippedCount++;
                        continue;
                    }

                    $payout = $payoutService->initiatePayout($instructor, $periodKey);

                    if (! $payout) {
                        $skippedCount++;
                        continue;
                    }

                    if ($sync) {
                        $this->line("Processing payout #{$payout->id} synchronously for {$instructor->name}...");
                        $payoutService->process($payout);
                    } else {
                        ProcessInstructorPayoutJob::dispatch($payout->id);
                        $this->line("Queued payout job for Instructor {$instructor->name} (#{$instructor->id}) - Amount: {$balance} cents");
                    }

                    $initiatedCount++;
                }
            });

        $this->info("Done! Initiated: {$initiatedCount}, Skipped: {$skippedCount}.");

        return self::SUCCESS;
    }
}

