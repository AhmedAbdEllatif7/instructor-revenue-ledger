<?php

namespace Tests\Feature;

use App\Contracts\PayoutProviderInterface;
use App\Jobs\ProcessInstructorPayoutJob;
use App\Models\Course;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchLog;
use App\Services\Allocation\RevenueAllocationService;
use App\Services\Ledger\LedgerService;
use App\Services\Payout\MockPayoutProvider;
use App\Services\Payout\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutProcessingTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;
    private RevenueAllocationService $allocationService;
    private PayoutService $payoutService;
    private MockPayoutProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = app(LedgerService::class);
        $this->allocationService = app(RevenueAllocationService::class);
        $this->mockProvider = app(PayoutProviderInterface::class);
        $this->payoutService = app(PayoutService::class);
    }

    /**
     * Helper to setup an instructor with an allocated balance.
     */
    private function setupInstructorWithBalance(int $amountCents = 7000): User
    {
        $instructor = User::create([
            'name' => 'Dr. Mostafa',
            'email' => 'mostafa@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $student = User::create([
            'name' => 'Ziad',
            'email' => 'ziad@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $course = Course::create(['instructor_id' => $instructor->id, 'title' => 'System Design']);

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Plan',
            'duration_months' => 1,
            'price_cents' => 10000,
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 1,
            'total_price_cents' => 10000,
            'monthly_price_cents' => 10000,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-30',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'amount_cents' => 10000,
            'currency' => 'EGP',
            'external_reference' => 'PAY_SETUP_' . uniqid(),
            'status' => SubscriptionPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        // Inbound cash into gateway & deferred revenue
        $this->ledgerService->recordSubscriptionPayment($subscription, $payment);

        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'period_key' => '2026-09',
            'seconds_watched' => 3600,
            'watched_at' => now(),
        ]);

        // Allocate revenue (credits instructor with 7000 cents = 70.00 EGP)
        $this->allocationService->allocate($subscription, '2026-09', 30);

        return $instructor;
    }

    public function test_successful_payout_moves_funds_through_state_machine_and_updates_ledger(): void
    {
        $instructor = $this->setupInstructorWithBalance(7000);

        $this->assertEquals(7000, $this->ledgerService->getInstructorBalanceCents($instructor));

        $this->mockProvider->setMode(MockPayoutProvider::MODE_SUCCESS);

        // 1. Initiate payout
        $payout = $this->payoutService->initiatePayout($instructor, '2026-09');

        $this->assertNotNull($payout);
        $this->assertEquals(Payout::STATUS_DRAFT, $payout->status);
        $this->assertEquals(7000, $payout->amount_cents);

        // 2. Process payout
        $processed = $this->payoutService->process($payout);

        $this->assertEquals(Payout::STATUS_COMPLETED, $processed->status);
        $this->assertNotNull($processed->provider_transfer_id);
        $this->assertNotNull($processed->processed_at);

        // 3. Ledger balances verification:
        // Available instructor balance should be 0
        $this->assertEquals(0, $this->ledgerService->getInstructorBalanceCents($instructor));

        // Escrow account should be 0 (funds passed through and cleared)
        $escrowAccount = $this->ledgerService->instructorEscrowAccount($instructor);
        $this->assertEquals(0, $escrowAccount->currentBalanceCents());

        // Cash assets decreased by 7000 cents (10000 inbound - 7000 payout = 3000 left for platform)
        $gatewayAccount = $this->ledgerService->gatewayAccount();
        $this->assertEquals(3000, $gatewayAccount->currentBalanceCents());

        // Platform commission remains intact at 3000 cents
        $this->assertEquals(3000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_REVENUES_COMMISSION));
    }

    public function test_permanent_provider_failure_releases_escrow_hold_back_to_instructor_balance(): void
    {
        $instructor = $this->setupInstructorWithBalance(7000);

        $this->mockProvider->setMode(MockPayoutProvider::MODE_PERMANENT_FAILURE);

        $payout = $this->payoutService->initiatePayout($instructor, '2026-09');
        $processed = $this->payoutService->process($payout);

        // State machine transition to FAILED
        $this->assertEquals(Payout::STATUS_FAILED, $processed->status);
        $this->assertStringContainsString('Bank account is invalid', $processed->failure_reason);

        // Invariant: Escrow is released, instructor balance safely RESTORED to 7000 cents!
        $this->assertEquals(7000, $this->ledgerService->getInstructorBalanceCents($instructor));

        $escrowAccount = $this->ledgerService->instructorEscrowAccount($instructor);
        $this->assertEquals(0, $escrowAccount->currentBalanceCents());
    }

    public function test_provider_timeout_moves_payout_to_pending_reconciliation_without_releasing_escrow(): void
    {
        $instructor = $this->setupInstructorWithBalance(7000);

        // Simulate provider timing out after receiving money transfer request
        $this->mockProvider->setMode(MockPayoutProvider::MODE_TIMEOUT);

        $payout = $this->payoutService->initiatePayout($instructor, '2026-09');
        $processed = $this->payoutService->process($payout);

        // Must transition to PENDING_RECONCILIATION (ADR-007)
        $this->assertEquals(Payout::STATUS_PENDING_RECONCILIATION, $processed->status);
        $this->assertStringContainsString('timeout', strtolower($processed->failure_reason));

        // CRITICAL INVARIANT:
        // Funds MUST remain FROZEN in Escrow! Do NOT release back to instructor!
        $this->assertEquals(0, $this->ledgerService->getInstructorBalanceCents($instructor));

        $escrowAccount = $this->ledgerService->instructorEscrowAccount($instructor);
        $this->assertEquals(7000, $escrowAccount->currentBalanceCents());
    }

    public function test_reconciliation_command_resolves_pending_payout_to_completed(): void
    {
        $instructor = $this->setupInstructorWithBalance(7000);

        // 1. Induce timeout to create PENDING_RECONCILIATION payout
        $this->mockProvider->setMode(MockPayoutProvider::MODE_TIMEOUT);
        $payout = $this->payoutService->initiatePayout($instructor, '2026-09');
        $this->payoutService->process($payout);

        $this->assertEquals(Payout::STATUS_PENDING_RECONCILIATION, $payout->fresh()->status);

        // 2. Run reconciliation command
        $this->artisan('payout:reconcile')
            ->expectsOutputToContain('Found 1 payout(s) requiring reconciliation')
            ->expectsOutputToContain('resolved to: completed')
            ->assertSuccessful();

        // 3. Payout must now be resolved to COMPLETED
        $reconciled = $payout->fresh();
        $this->assertEquals(Payout::STATUS_COMPLETED, $reconciled->status);
        $this->assertNotNull($reconciled->provider_transfer_id);
        $this->assertNotNull($reconciled->reconciled_at);

        // Escrow cleared, gateway decreased
        $escrowAccount = $this->ledgerService->instructorEscrowAccount($instructor);
        $this->assertEquals(0, $escrowAccount->currentBalanceCents());
    }

    public function test_duplicate_command_execution_does_not_pay_twice(): void
    {
        $instructor = $this->setupInstructorWithBalance(7000);

        $this->mockProvider->setMode(MockPayoutProvider::MODE_SUCCESS);

        // Run 1: Successful payout
        $this->artisan('payout:process', ['period_key' => '2026-09', '--sync' => true])
            ->assertSuccessful();

        $this->assertCount(1, Payout::all());
        $this->assertEquals(0, $this->ledgerService->getInstructorBalanceCents($instructor));

        // Run 2: Duplicate execution of the same command
        $this->artisan('payout:process', ['period_key' => '2026-09', '--sync' => true])
            ->expectsOutputToContain('Skipped: 1') // Skipped because balance is now 0 and payout exists
            ->assertSuccessful();

        // Still only 1 payout row in database
        $this->assertCount(1, Payout::all());
        $this->assertEquals(Payout::STATUS_COMPLETED, Payout::first()->status);
    }

    public function test_duplicate_job_execution_is_idempotent(): void
    {
        $instructor = $this->setupInstructorWithBalance(7000);

        $this->mockProvider->setMode(MockPayoutProvider::MODE_SUCCESS);

        $payout = $this->payoutService->initiatePayout($instructor, '2026-09');

        // Execute Job 1
        ProcessInstructorPayoutJob::dispatchSync($payout->id);

        $this->assertEquals(Payout::STATUS_COMPLETED, $payout->fresh()->status);
        $this->assertEquals(0, $this->ledgerService->getInstructorBalanceCents($instructor));

        // Execute Job 2 (e.g. queue duplicate / retry)
        ProcessInstructorPayoutJob::dispatchSync($payout->id);

        // State remains COMPLETED without duplicate money deductions
        $this->assertEquals(Payout::STATUS_COMPLETED, $payout->fresh()->status);
        $this->assertEquals(0, $this->ledgerService->getInstructorBalanceCents($instructor));
        $this->assertCount(1, Payout::all());
    }

    public function test_cannot_initiate_payout_with_zero_balance(): void
    {
        $instructor = User::create([
            'name' => 'Zero Balance Instructor',
            'email' => 'zero@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $payout = $this->payoutService->initiatePayout($instructor, '2026-09');

        $this->assertNull($payout);
        $this->assertCount(0, Payout::all());
    }
}
