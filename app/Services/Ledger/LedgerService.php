<?php

namespace App\Services\Ledger;

use App\Exceptions\UnbalancedLedgerTransactionException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPeriodAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    public const CODE_ASSETS_GATEWAY = 'assets:gateway';
    public const CODE_LIABILITIES_DEFERRED = 'liabilities:deferred_revenue';
    public const CODE_REVENUES_COMMISSION = 'revenues:platform:commission';
    public const CODE_REVENUES_BREAKAGE = 'revenues:platform:breakage';

    /**
     * Get or create a ledger account idempotently.
     */
    public function getOrCreateAccount(string $code,string $name,string $type,?User $holder = null): LedgerAccount {
        return LedgerAccount::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => $type,
                'holder_type' => $holder ? User::class : null,
                'holder_id' => $holder?->id,
                'currency' => 'EGP',
            ]
        );
    }

    public function gatewayAccount(): LedgerAccount
    {
        return $this->getOrCreateAccount(self::CODE_ASSETS_GATEWAY, 'Payment Gateway Transit', LedgerAccount::TYPE_ASSET);
    }

    public function deferredRevenueAccount(): LedgerAccount
    {
        return $this->getOrCreateAccount(self::CODE_LIABILITIES_DEFERRED, 'Customer Deferred Revenue', LedgerAccount::TYPE_LIABILITY);
    }

    public function platformCommissionAccount(): LedgerAccount
    {
        return $this->getOrCreateAccount(self::CODE_REVENUES_COMMISSION, 'Platform Commission Revenue', LedgerAccount::TYPE_REVENUE);
    }

    public function platformBreakageAccount(): LedgerAccount
    {
        return $this->getOrCreateAccount(self::CODE_REVENUES_BREAKAGE, 'Platform Breakage Revenue (Unclaimed Watch Time)', LedgerAccount::TYPE_REVENUE);
    }

    public function instructorPayableAccount(User|int $instructor): LedgerAccount
    {
        $id = $instructor instanceof User ? $instructor->id : $instructor;
        $name = $instructor instanceof User ? $instructor->name : "Instructor #{$id}";

        return $this->getOrCreateAccount("liabilities:instructor:payable:{$id}","Payable to {$name}", LedgerAccount::TYPE_LIABILITY,$instructor instanceof User ? $instructor : User::find($id));
    }

    public function instructorEscrowAccount(User|int $instructor): LedgerAccount
    {
        $id = $instructor instanceof User ? $instructor->id : $instructor;
        $name = $instructor instanceof User ? $instructor->name : "Instructor #{$id}";

        return $this->getOrCreateAccount("liabilities:instructor:payout_in_flight:{$id}","Payout Escrow for {$name}", LedgerAccount::TYPE_LIABILITY,$instructor instanceof User ? $instructor : User::find($id));
    }

    /**
     * Post a balanced double-entry transaction.
     *
     * @param array<int, array{ledger_account_id: int, direction: string, amount_cents: int}> $entries
     * @throws UnbalancedLedgerTransactionException
     */
    public function postTransaction(string $referenceNumber, string $type, string $description, ?string $periodKey, array $entries): LedgerTransaction {
        // App-level Idempotency: return existing transaction if already posted
        $existing = LedgerTransaction::with('entries.account')->where('reference_number', $referenceNumber)->first();

        if ($existing) {
            return $existing;
        }

        // Validate debits sum === credits sum
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($entries as $entry) {
            $amount = (int) $entry['amount_cents'];
            if ($amount <= 0) {
                continue;
            }

            if ($entry['direction'] === LedgerEntry::DIRECTION_DEBIT) {
                $totalDebits += $amount;
            } elseif ($entry['direction'] === LedgerEntry::DIRECTION_CREDIT) {
                $totalCredits += $amount;
            }
        }

        if ($totalDebits !== $totalCredits || $totalDebits === 0) {
            throw new UnbalancedLedgerTransactionException($totalDebits, $totalCredits, $referenceNumber);
        }

        return DB::transaction(function () use ($referenceNumber, $type, $description, $periodKey, $entries) {
            $transaction = LedgerTransaction::create([
                'reference_number' => $referenceNumber,
                'type' => $type,
                'description' => $description,
                'period_key' => $periodKey,
                'posted_at' => now(),
            ]);

            foreach ($entries as $entry) {
                if ($entry['amount_cents'] <= 0) {
                    continue;
                }

                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $entry['ledger_account_id'],
                    'direction' => $entry['direction'],
                    'amount_cents' => $entry['amount_cents'],
                ]);
            }

            return $transaction->load('entries.account');
        });
    }

    /**
     * Record an inbound subscription payment into Deferred Revenue (ADR-003).
     * Debit: Assets:Gateway
     * Credit: Liabilities:DeferredRevenue
     */
    public function recordSubscriptionPayment(Subscription $subscription,SubscriptionPayment $payment): LedgerTransaction {
        $referenceNumber = "SUB_PAY_{$payment->id}";

        $entries = [
            [
                'ledger_account_id' => $this->gatewayAccount()->id,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount_cents' => $payment->amount_cents,
            ],
            [
                'ledger_account_id' => $this->deferredRevenueAccount()->id,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount_cents' => $payment->amount_cents,
            ],
        ];

        return $this->postTransaction(
            referenceNumber: $referenceNumber,
            type: LedgerTransaction::TYPE_SUBSCRIPTION_PAYMENT,
            description: "Upfront subscription payment #{$payment->external_reference} for Student #{$subscription->student_id}",
            periodKey: null,
            entries: $entries
        );
    }

    /**
     * Record recognized monthly revenue allocation in the ledger (ADR-003 & ADR-005).
     * Debit: Liabilities:DeferredRevenue (recognized amount)
     * Credit: Revenues:Platform:Commission or Breakage (platform cut)
     * Credit: Liabilities:Instructor:Payable (for each instructor share)
     */
    public function recordRevenueAllocation(SubscriptionPeriodAllocation $allocation): LedgerTransaction {
        // If already linked to a transaction, return it
        if ($allocation->ledger_transaction_id) {
            return LedgerTransaction::with('entries.account')->findOrFail($allocation->ledger_transaction_id);
        }

        $referenceNumber = "REV_ALLOC_{$allocation->id}";

        $platformAccount = $allocation->is_breakage
            ? $this->platformBreakageAccount()
            : $this->platformCommissionAccount();

        $entries = [
            // Debit Deferred Revenue to reduce unearned liability
            [
                'ledger_account_id' => $this->deferredRevenueAccount()->id,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount_cents' => $allocation->recognized_amount_cents,
            ],
            // Credit Platform Revenue
            [
                'ledger_account_id' => $platformAccount->id,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount_cents' => $allocation->platform_fee_cents,
            ],
        ];

        // Credit each instructor's payable account
        $allocation->loadMissing('instructorAllocations.instructor');
        foreach ($allocation->instructorAllocations as $share) {
            $instructorAccount = $this->instructorPayableAccount($share->instructor_id);
            $entries[] = [
                'ledger_account_id' => $instructorAccount->id,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount_cents' => $share->amount_cents,
            ];
        }

        $transaction = $this->postTransaction(
            referenceNumber: $referenceNumber,
            type: LedgerTransaction::TYPE_MONTHLY_REVENUE_RECOGNITION,
            description: "Monthly revenue recognition for Subscription #{$allocation->subscription_id} Period {$allocation->period_key}",
            periodKey: $allocation->period_key,
            entries: $entries
        );

        $allocation->updateQuietly(['ledger_transaction_id' => $transaction->id]);

        return $transaction;
    }

    /**
     * Get an instructor's current withdrawable balance in cents.
     * Liabilities:Instructor:Payable (Credits - Debits)
     */
    public function getInstructorBalanceCents(User|int $instructor): int
    {
        $id = $instructor instanceof User ? $instructor->id : $instructor;
        $account = LedgerAccount::where('code', "liabilities:instructor:payable:{$id}")->first();

        return $account ? $account->currentBalanceCents() : 0;
    }

    /**
     * Get total cumulative earnings credited to an instructor across all time.
     */
    public function getInstructorTotalEarningsCents(User|int $instructor): int
    {
        $id = $instructor instanceof User ? $instructor->id : $instructor;
        $account = LedgerAccount::where('code', "liabilities:instructor:payable:{$id}")->first();

        return $account ? $account->creditsSumCents() : 0;
    }

    /**
     * Get balance of any ledger account by code.
     */
    public function getAccountBalanceCents(string $code): int
    {
        $account = LedgerAccount::where('code', $code)->first();

        return $account ? $account->currentBalanceCents() : 0;
    }
}
