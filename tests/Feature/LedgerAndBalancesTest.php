<?php

namespace Tests\Feature;

use App\Exceptions\UnbalancedLedgerTransactionException;
use App\Models\Course;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchLog;
use App\Services\Allocation\RevenueAllocationService;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerAndBalancesTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;
    private RevenueAllocationService $allocationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = app(LedgerService::class);
        $this->allocationService = app(RevenueAllocationService::class);
    }

    public function test_records_subscription_payment_into_deferred_revenue(): void
    {
        $student = User::create([
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Annual Plan',
            'duration_months' => 12,
            'price_cents' => 120000, // 1,200.00 EGP
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 12,
            'total_price_cents' => 120000,
            'monthly_price_cents' => 10000,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-08-31',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'amount_cents' => 120000,
            'currency' => 'EGP',
            'external_reference' => 'GATEWAY_REF_001',
            'status' => SubscriptionPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $tx = $this->ledgerService->recordSubscriptionPayment($subscription, $payment);

        $this->assertTrue($tx->isBalanced());
        $this->assertEquals(120000, $tx->totalDebitCents());
        $this->assertEquals(120000, $tx->totalCreditCents());

        // Asset (Gateway) has +120000 cents (debit balance)
        $this->assertEquals(120000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_ASSETS_GATEWAY));

        // Liability (Deferred Revenue) has +120000 cents (credit balance)
        $this->assertEquals(120000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_LIABILITIES_DEFERRED));
    }

    public function test_revenue_allocation_moves_deferred_revenue_to_instructors_and_platform_with_exact_accounting_balance(): void
    {
        $student = User::create([
            'name' => 'Karim',
            'email' => 'karim@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $instructorA = User::create([
            'name' => 'Dr. Tarek',
            'email' => 'tarek@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $instructorB = User::create([
            'name' => 'Dr. Nadia',
            'email' => 'nadia@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $courseA = Course::create(['instructor_id' => $instructorA->id, 'title' => 'Algorithms']);
        $courseB = Course::create(['instructor_id' => $instructorB->id, 'title' => 'Operating Systems']);

        $plan = SubscriptionPlan::create([
            'name' => 'Annual Plan',
            'duration_months' => 12,
            'price_cents' => 120000,
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 12,
            'total_price_cents' => 120000,
            'monthly_price_cents' => 10000,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-08-31',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'amount_cents' => 120000,
            'currency' => 'EGP',
            'external_reference' => 'GATEWAY_REF_002',
            'status' => SubscriptionPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        // 1. Initial upfront payment
        $this->ledgerService->recordSubscriptionPayment($subscription, $payment);

        // 2. Watch logs for September:
        // Instructor A: 75 minutes (4500s), Instructor B: 25 minutes (1500s)
        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $courseA->id,
            'instructor_id' => $instructorA->id,
            'period_key' => '2026-09',
            'seconds_watched' => 4500,
            'watched_at' => '2026-09-15',
        ]);

        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $courseB->id,
            'instructor_id' => $instructorB->id,
            'period_key' => '2026-09',
            'seconds_watched' => 1500,
            'watched_at' => '2026-09-20',
        ]);

        // 3. Allocate September revenue (triggers ledger booking automatically)
        $allocation = $this->allocationService->allocate($subscription, '2026-09', 30);

        $this->assertNotNull($allocation->ledger_transaction_id);

        // 4. Verify Ledger Balances:
        // Deferred revenue: 120000 - 10000 = 110000 cents
        $this->assertEquals(110000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_LIABILITIES_DEFERRED));

        // Platform Commission: 30% of 10000 = 3000 cents
        $this->assertEquals(3000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_REVENUES_COMMISSION));

        // Instructor A (75% of 7000 = 5250 cents)
        $this->assertEquals(5250, $this->ledgerService->getInstructorBalanceCents($instructorA));
        $this->assertEquals(5250, $this->ledgerService->getInstructorTotalEarningsCents($instructorA));

        // Instructor B (25% of 7000 = 1750 cents)
        $this->assertEquals(1750, $this->ledgerService->getInstructorBalanceCents($instructorB));
        $this->assertEquals(1750, $this->ledgerService->getInstructorTotalEarningsCents($instructorB));

        // Universal Accounting Equation Check:
        // Assets (120000) = Liabilities (110000 Deferred + 5250 InstA + 1750 InstB) + Equity/Revenue (3000 Platform)
        $totalAssets = $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_ASSETS_GATEWAY);
        $totalLiabilities = 110000 + 5250 + 1750;
        $totalRevenues = 3000;

        $this->assertEquals($totalAssets, $totalLiabilities + $totalRevenues);
    }

    public function test_unbalanced_transaction_throws_exception_and_rolls_back(): void
    {
        $accountA = $this->ledgerService->gatewayAccount();
        $accountB = $this->ledgerService->platformCommissionAccount();

        $this->expectException(UnbalancedLedgerTransactionException::class);

        $this->ledgerService->postTransaction(
            referenceNumber: 'UNBALANCED_001',
            type: 'manual_adjustment',
            description: 'Intentionally unbalanced test transaction',
            periodKey: null,
            entries: [
                ['ledger_account_id' => $accountA->id, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount_cents' => 5000],
                ['ledger_account_id' => $accountB->id, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount_cents' => 4000], // 1000 cent mismatch!
            ]
        );

        $this->assertEquals(0, LedgerTransaction::where('reference_number', 'UNBALANCED_001')->count());
        $this->assertEquals(0, LedgerEntry::count());
    }

    public function test_breakage_credits_breakage_revenue_account(): void
    {
        $student = User::create([
            'name' => 'Inactive User',
            'email' => 'silent@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Pass',
            'duration_months' => 1,
            'price_cents' => 8000,
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 1,
            'total_price_cents' => 8000,
            'monthly_price_cents' => 8000,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-30',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'amount_cents' => 8000,
            'currency' => 'EGP',
            'external_reference' => 'GATEWAY_REF_003',
            'status' => SubscriptionPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->ledgerService->recordSubscriptionPayment($subscription, $payment);

        // 0 watch time -> Breakage
        $allocation = $this->allocationService->allocate($subscription, '2026-09');

        $this->assertTrue($allocation->is_breakage);

        // 100% of recognized amount (8000 cents) credited to Platform Breakage account
        $this->assertEquals(8000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_REVENUES_BREAKAGE));
        $this->assertEquals(0, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_REVENUES_COMMISSION));
    }
}
