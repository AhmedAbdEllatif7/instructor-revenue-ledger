<?php

namespace App\Filament\Widgets;

use App\Models\LedgerAccount;
use App\Models\Payout;
use App\Services\Ledger\LedgerService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LedgerStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $ledgerService = app(LedgerService::class);

        // 1. Deferred Revenue Liability
        $deferredRevenueCents = $ledgerService->getAccountBalanceCents(LedgerService::CODE_LIABILITIES_DEFERRED);

        // 2. Instructor Payables (Total Available Balances)
        $instructorPayablesCents = LedgerAccount::where('code', 'like', 'liabilities:instructor:payable:%')
            ->get()
            ->sum(fn (LedgerAccount $account): int => $account->currentBalanceCents());

        // 3. Platform Commission Revenue
        $commissionRevenueCents = $ledgerService->getAccountBalanceCents(LedgerService::CODE_REVENUES_COMMISSION);

        // 4. In-Flight Escrow (Pending Provider confirmation)
        $escrowHoldCents = LedgerAccount::where('code', 'like', 'liabilities:instructor:payout_in_flight:%')
            ->get()
            ->sum(fn (LedgerAccount $account): int => $account->currentBalanceCents());

        // 5. Total Completed Payouts
        $completedPayoutsCents = (int) Payout::where('status', Payout::STATUS_COMPLETED)->sum('amount_cents');

        // 6. Breakage Revenue (Unclaimed Watch Time)
        $breakageRevenueCents = $ledgerService->getAccountBalanceCents(LedgerService::CODE_REVENUES_BREAKAGE);

        return [
            Stat::make('Deferred Revenue', number_format($deferredRevenueCents / 100, 2) . ' EGP')
                ->description('Unearned customer subscription liabilities')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Instructor Payables', number_format($instructorPayablesCents / 100, 2) . ' EGP')
                ->description('Outstanding available instructor balances')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Platform Commission', number_format($commissionRevenueCents / 100, 2) . ' EGP')
                ->description('Cumulative earned platform commission')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),

            Stat::make('Disbursed Payouts', number_format($completedPayoutsCents / 100, 2) . ' EGP')
                ->description('Total successfully completed payouts')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('info'),

            Stat::make('In-Flight Escrow', number_format($escrowHoldCents / 100, 2) . ' EGP')
                ->description('Held funds pending payout completion')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('gray'),

            Stat::make('Breakage Revenue', number_format($breakageRevenueCents / 100, 2) . ' EGP')
                ->description('100% platform-retained inactive consumption')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('primary'),
        ];
    }
}
