<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\InstructorAllocation;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPeriodAllocation;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainModelDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_users_with_roles_and_courses(): void
    {
        $instructor = User::create([
            'name' => 'Dr. Ahmed',
            'email' => 'ahmed@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $this->assertTrue($instructor->isInstructor());

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'title' => 'Advanced Docker & Laravel',
            'description' => 'Comprehensive masterclass',
            'is_active' => true,
        ]);

        $this->assertEquals($instructor->id, $course->instructor->id);
        $this->assertCount(1, $instructor->courses);
    }

    public function test_watch_logs_record_student_consumption_accurately(): void
    {
        $instructor = User::create([
            'name' => 'Instructor Joe',
            'email' => 'joe@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $student = User::create([
            'name' => 'Student Sarah',
            'email' => 'sarah@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $course = Course::create([
            'instructor_id' => $instructor->id,
            'title' => 'Database Design',
            'is_active' => true,
        ]);

        $watchLog = WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'period_key' => '2026-09',
            'seconds_watched' => 3600,
            'watched_at' => now(),
        ]);

        $this->assertEquals(3600, $watchLog->seconds_watched);
        $this->assertEquals($student->id, $watchLog->student->id);
        $this->assertEquals($instructor->id, $watchLog->instructor->id);
    }

    public function test_subscriptions_and_payments_lifecycle(): void
    {
        $student = User::create([
            'name' => 'Omar',
            'email' => 'omar@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Annual Pass',
            'duration_months' => 12,
            'price_cents' => 120000, // 1200.00 EGP in minor units
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 12,
            'total_price_cents' => 120000,
            'monthly_price_cents' => 10000, // 100.00 EGP/month
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
        ]);

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'amount_cents' => 120000,
            'currency' => 'EGP',
            'external_reference' => 'PAY_ORDER_998811',
            'status' => SubscriptionPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertTrue($subscription->isActive());
        $this->assertCount(1, $subscription->payments);
        $this->assertEquals('PAY_ORDER_998811', $payment->external_reference);
    }

    public function test_unique_constraint_prevents_duplicate_revenue_allocation_for_same_period(): void
    {
        $student = User::create([
            'name' => 'Ali',
            'email' => 'ali@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly',
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
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        // First allocation succeeds
        SubscriptionPeriodAllocation::create([
            'subscription_id' => $subscription->id,
            'period_key' => '2026-09',
            'recognized_amount_cents' => 10000,
            'platform_fee_cents' => 3000,
            'instructor_pool_cents' => 7000,
            'is_breakage' => false,
        ]);

        // Second allocation for the EXACT SAME subscription and period MUST fail at database level
        $this->expectException(QueryException::class);

        SubscriptionPeriodAllocation::create([
            'subscription_id' => $subscription->id,
            'period_key' => '2026-09',
            'recognized_amount_cents' => 10000,
            'platform_fee_cents' => 3000,
            'instructor_pool_cents' => 7000,
            'is_breakage' => false,
        ]);
    }

    public function test_double_entry_ledger_balances_and_calculates_instructor_balance(): void
    {
        $instructor = User::create([
            'name' => 'Prof. Khaled',
            'email' => 'khaled@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        // Accounts
        $gatewayAccount = LedgerAccount::create([
            'code' => 'assets:gateway',
            'name' => 'Payment Gateway Transit',
            'type' => LedgerAccount::TYPE_ASSET,
        ]);

        $instructorPayableAccount = LedgerAccount::create([
            'code' => "liabilities:instructor:payable:{$instructor->id}",
            'name' => "Payable to {$instructor->name}",
            'type' => LedgerAccount::TYPE_LIABILITY,
            'holder_type' => User::class,
            'holder_id' => $instructor->id,
        ]);

        $platformRevenueAccount = LedgerAccount::create([
            'code' => 'revenues:platform:commission',
            'name' => 'Platform Commission',
            'type' => LedgerAccount::TYPE_REVENUE,
        ]);

        // Post a balanced transaction:
        // Gateway Asset receives 100.00 EGP (Debit 10000)
        // Instructor Payable increases by 70.00 EGP (Credit 7000)
        // Platform Commission increases by 30.00 EGP (Credit 3000)
        $tx = LedgerTransaction::create([
            'reference_number' => 'TX_REC_202609_001',
            'type' => LedgerTransaction::TYPE_MONTHLY_REVENUE_RECOGNITION,
            'description' => 'September Revenue Recognition',
            'period_key' => '2026-09',
            'posted_at' => now(),
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $tx->id,
            'ledger_account_id' => $gatewayAccount->id,
            'direction' => LedgerEntry::DIRECTION_DEBIT,
            'amount_cents' => 10000,
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $tx->id,
            'ledger_account_id' => $instructorPayableAccount->id,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount_cents' => 7000,
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $tx->id,
            'ledger_account_id' => $platformRevenueAccount->id,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount_cents' => 3000,
        ]);

        // Assert transaction is strictly balanced
        $this->assertTrue($tx->isBalanced());
        $this->assertEquals(10000, $tx->totalDebitCents());
        $this->assertEquals(10000, $tx->totalCreditCents());

        // Assert instructor balance is exactly 70.00 EGP (7000 cents)
        $this->assertEquals(7000, $instructorPayableAccount->currentBalanceCents());
    }

    public function test_payout_unique_constraints_prevent_double_payout_for_same_period(): void
    {
        $instructor = User::create([
            'name' => 'Prof. Mona',
            'email' => 'mona@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        // First payout for 2026-09
        Payout::create([
            'instructor_id' => $instructor->id,
            'period_key' => '2026-09',
            'amount_cents' => 50000,
            'status' => Payout::STATUS_DRAFT,
            'idempotency_key' => 'PAYOUT_MONA_2026_09_A',
        ]);

        // Attempting to create a second payout for the SAME instructor and period MUST throw QueryException
        $this->expectException(QueryException::class);

        Payout::create([
            'instructor_id' => $instructor->id,
            'period_key' => '2026-09',
            'amount_cents' => 50000,
            'status' => Payout::STATUS_DRAFT,
            'idempotency_key' => 'PAYOUT_MONA_2026_09_B',
        ]);
    }
}
