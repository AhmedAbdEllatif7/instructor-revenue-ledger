<?php

namespace Tests\Feature;

use App\Exceptions\RefundNotAllowedException;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchLog;
use App\Services\Allocation\RevenueAllocationService;
use App\Services\Ledger\LedgerService;
use App\Services\Refund\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledgerService;
    private RevenueAllocationService $allocationService;
    private RefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = app(LedgerService::class);
        $this->allocationService = app(RevenueAllocationService::class);
        $this->refundService = app(RefundService::class);
    }

    /**
     * Helper: Create a 3-month subscription with a payment recorded in the ledger.
     */
    private function createSubscriptionWithPayment(
        User $student,
        int $termMonths = 3,
        int $pricePerMonth = 10000
    ): array {
        $totalCents = $termMonths * $pricePerMonth;

        $plan = SubscriptionPlan::create([
            'name' => "{$termMonths}-Month Plan",
            'duration_months' => $termMonths,
            'price_cents' => $totalCents,
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => $termMonths,
            'total_price_cents' => $totalCents,
            'monthly_price_cents' => $pricePerMonth,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-07-01',
            'ends_at' => '2026-09-30',
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'amount_cents' => $totalCents,
            'currency' => 'EGP',
            'external_reference' => 'PAY_' . uniqid(),
            'status' => SubscriptionPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->ledgerService->recordSubscriptionPayment($subscription, $payment);

        return [$subscription, $payment];
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Full refund on subscription with zero recognized months
    // ─────────────────────────────────────────────────────────────────

    public function test_full_refund_when_no_months_recognized_yet(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        [$subscription, $payment] = $this->createSubscriptionWithPayment($student, termMonths: 3, pricePerMonth: 10000);

        // No months recognized yet — full 30,000 cents should be refunded
        $refundedCents = $this->refundService->refund($subscription);

        $this->assertEquals(30000, $refundedCents);

        // Subscription marked refunded
        $this->assertEquals(Subscription::STATUS_REFUNDED, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->canceled_at);

        // Payment marked fully refunded
        $this->assertEquals(SubscriptionPayment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Partial refund — month 1 recognized, months 2-3 refunded
    // ─────────────────────────────────────────────────────────────────

    public function test_partial_refund_after_one_month_recognized(): void
    {
        $instructor = User::factory()->create(['role' => User::ROLE_INSTRUCTOR]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $course = Course::create(['instructor_id' => $instructor->id, 'title' => 'PHP Mastery']);

        [$subscription, $payment] = $this->createSubscriptionWithPayment($student, termMonths: 3, pricePerMonth: 10000);

        // Record watch time for Month 1 only
        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'period_key' => '2026-07',
            'seconds_watched' => 3600,
            'watched_at' => now(),
        ]);

        // Recognize Month 1 revenue (10,000 cents)
        $this->allocationService->allocate($subscription, '2026-07', 30);

        // Instructor has balance: 10000 * 70% = 7000 cents
        $this->assertEquals(7000, $this->ledgerService->getInstructorBalanceCents($instructor));

        // Now student requests refund in month 2 — months 2 & 3 still deferred
        $refundedCents = $this->refundService->refund($subscription);

        // 3 months total = 30,000 cents. Month 1 recognized = 10,000. Refund = 20,000
        $this->assertEquals(20000, $refundedCents);

        // Subscription marked refunded
        $this->assertEquals(Subscription::STATUS_REFUNDED, $subscription->fresh()->status);

        // Payment marked PARTIALLY refunded (not full since 10,000 was kept)
        $this->assertEquals(SubscriptionPayment::STATUS_PARTIALLY_REFUNDED, $payment->fresh()->status);

        // CRITICAL INVARIANT: Instructor balance must NOT be touched — no clawbacks!
        $this->assertEquals(7000, $this->ledgerService->getInstructorBalanceCents($instructor));
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Ledger balances are correct after refund
    // ─────────────────────────────────────────────────────────────────

    public function test_refund_ledger_entries_are_balanced(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        [$subscription] = $this->createSubscriptionWithPayment($student, termMonths: 1, pricePerMonth: 10000);

        // Before refund: deferred revenue = 10,000, gateway = 10,000
        $this->assertEquals(10000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_LIABILITIES_DEFERRED));
        $this->assertEquals(10000, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_ASSETS_GATEWAY));

        $this->refundService->refund($subscription);

        // After full refund:
        // Deferred Revenue should be 0 (debited out)
        $this->assertEquals(0, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_LIABILITIES_DEFERRED));

        // Gateway should be 0 (cash returned to student)
        $this->assertEquals(0, $this->ledgerService->getAccountBalanceCents(LedgerService::CODE_ASSETS_GATEWAY));
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Refund is idempotent — calling twice produces same result
    // ─────────────────────────────────────────────────────────────────

    public function test_refund_is_idempotent_when_called_twice(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        [$subscription] = $this->createSubscriptionWithPayment($student, termMonths: 2, pricePerMonth: 10000);

        // First call: actual refund
        $first = $this->refundService->refund($subscription);
        $this->assertEquals(20000, $first);

        // Second call: subscription already refunded → 0 change, no duplicate ledger entries
        $second = $this->refundService->refund($subscription->fresh());
        $this->assertEquals(0, $second);

        // Still only one refund ledger transaction
        $this->assertEquals(1, \App\Models\LedgerTransaction::where('type', 'refund')->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Refund on fully-recognized subscription → cancel, no refund
    // ─────────────────────────────────────────────────────────────────

    public function test_no_refund_when_all_months_already_recognized(): void
    {
        $instructor = User::factory()->create(['role' => User::ROLE_INSTRUCTOR]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $course = Course::create(['instructor_id' => $instructor->id, 'title' => 'Laravel Advanced']);

        [$subscription] = $this->createSubscriptionWithPayment($student, termMonths: 1, pricePerMonth: 10000);

        // Record watch time and recognize the only month
        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'period_key' => '2026-07',
            'seconds_watched' => 3600,
            'watched_at' => now(),
        ]);
        $this->allocationService->allocate($subscription, '2026-07', 30);

        // Now refund: no deferred remaining
        $refundedCents = $this->refundService->refund($subscription);

        $this->assertEquals(0, $refundedCents);

        // Subscription is canceled (not refunded, since instructors earned everything)
        $this->assertEquals(Subscription::STATUS_CANCELED, $subscription->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Refund on expired subscription throws exception
    // ─────────────────────────────────────────────────────────────────

    public function test_refund_throws_exception_for_expired_subscription(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        [$subscription] = $this->createSubscriptionWithPayment($student, termMonths: 1, pricePerMonth: 10000);

        $subscription->update(['status' => Subscription::STATUS_EXPIRED]);

        $this->expectException(RefundNotAllowedException::class);

        $this->refundService->refund($subscription->fresh());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: calculateRefundableAmountCents returns correct amount
    // ─────────────────────────────────────────────────────────────────

    public function test_calculates_refundable_amount_correctly(): void
    {
        $instructor = User::factory()->create(['role' => User::ROLE_INSTRUCTOR]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $course = Course::create(['instructor_id' => $instructor->id, 'title' => 'Docker Course']);

        [$subscription] = $this->createSubscriptionWithPayment($student, termMonths: 3, pricePerMonth: 10000);

        // Before any recognition: full 30,000 refundable
        $this->assertEquals(30000, $this->refundService->calculateRefundableAmountCents($subscription));

        // Recognize month 1
        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'period_key' => '2026-07',
            'seconds_watched' => 3600,
            'watched_at' => now(),
        ]);
        $this->allocationService->allocate($subscription, '2026-07', 30);

        // After 1 month recognized: 20,000 refundable
        $this->assertEquals(20000, $this->refundService->calculateRefundableAmountCents($subscription->fresh()));
    }
}
