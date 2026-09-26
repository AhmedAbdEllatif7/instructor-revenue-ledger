<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchLog;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $ledgerService = app(LedgerService::class);

        // 1. Create System Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@career180.com'],
            [
                'name' => 'Career 180 Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
            ]
        );

        // 2. Create Instructors
        $instructor1 = User::firstOrCreate(
            ['email' => 'tarek@instructor.com'],
            ['name' => 'Tarek Ali', 'password' => Hash::make('password'), 'role' => User::ROLE_INSTRUCTOR]
        );

        $instructor2 = User::firstOrCreate(
            ['email' => 'mona@instructor.com'],
            ['name' => 'Mona El-Sayed', 'password' => Hash::make('password'), 'role' => User::ROLE_INSTRUCTOR]
        );

        $instructor3 = User::firstOrCreate(
            ['email' => 'youssef@instructor.com'],
            ['name' => 'Youssef Hassan', 'password' => Hash::make('password'), 'role' => User::ROLE_INSTRUCTOR]
        );

        // 3. Create Courses
        $course1 = Course::firstOrCreate(['instructor_id' => $instructor1->id, 'title' => 'Domain-Driven Design with Laravel 11']);
        $course2 = Course::firstOrCreate(['instructor_id' => $instructor2->id, 'title' => 'High-Throughput MySQL & Microservices']);
        $course3 = Course::firstOrCreate(['instructor_id' => $instructor3->id, 'title' => 'Financial Ledger & Double-Entry Systems']);

        // 4. Create Subscription Plans
        $planAnnual = SubscriptionPlan::firstOrCreate(
            ['name' => 'Annual Pro Plan'],
            ['duration_months' => 12, 'price_cents' => 120000] // 1,200.00 EGP
        );

        $planMonthly = SubscriptionPlan::firstOrCreate(
            ['name' => 'Monthly Standard Plan'],
            ['duration_months' => 1, 'price_cents' => 15000] // 150.00 EGP
        );

        // 5. Create Students
        $student1 = User::firstOrCreate(
            ['email' => 'omar@student.com'],
            ['name' => 'Omar Farouk', 'password' => Hash::make('password'), 'role' => User::ROLE_STUDENT]
        );

        $student2 = User::firstOrCreate(
            ['email' => 'sara@student.com'],
            ['name' => 'Sara Nabil', 'password' => Hash::make('password'), 'role' => User::ROLE_STUDENT]
        );

        // 6. Subscriptions and Initial Deferred Revenue Payments
        $sub1 = Subscription::firstOrCreate(
            ['student_id' => $student1->id, 'plan_id' => $planAnnual->id],
            [
                'term_months' => 12,
                'total_price_cents' => 120000,
                'monthly_price_cents' => 10000, // 100.00 EGP per month
                'starts_at' => now()->subMonths(2),
                'ends_at' => now()->addMonths(10),
                'status' => Subscription::STATUS_ACTIVE,
            ]
        );

        $payment1 = SubscriptionPayment::firstOrCreate(
            ['external_reference' => 'PAY-SEED-ANNUAL-001'],
            [
                'subscription_id' => $sub1->id,
                'amount_cents' => 120000,
                'status' => 'paid',
                'paid_at' => now()->subMonths(2),
            ]
        );

        $ledgerService->recordSubscriptionPayment($sub1, $payment1);

        // 7. Seed Instructor Balances via Double-Entry Ledger
        $gateway = $ledgerService->gatewayAccount();
        $payable1 = $ledgerService->instructorPayableAccount($instructor1);
        $payable2 = $ledgerService->instructorPayableAccount($instructor2);

        $ledgerService->postTransaction(
            referenceNumber: 'TX-SEED-INSTRUCTOR-1',
            type: 'monthly_revenue_recognition',
            description: 'Seed monthly revenue allocation for Tarek Ali',
            periodKey: '2026-08',
            entries: [
                ['ledger_account_id' => $gateway->id, 'direction' => 'debit', 'amount_cents' => 45000],
                ['ledger_account_id' => $payable1->id, 'direction' => 'credit', 'amount_cents' => 45000],
            ]
        );

        $ledgerService->postTransaction(
            referenceNumber: 'TX-SEED-INSTRUCTOR-2',
            type: 'monthly_revenue_recognition',
            description: 'Seed monthly revenue allocation for Mona El-Sayed',
            periodKey: '2026-08',
            entries: [
                ['ledger_account_id' => $gateway->id, 'direction' => 'debit', 'amount_cents' => 28000],
                ['ledger_account_id' => $payable2->id, 'direction' => 'credit', 'amount_cents' => 28000],
            ]
        );

        // 8. Seed Sample Payouts
        Payout::firstOrCreate(
            ['idempotency_key' => 'SEED-PAYOUT-COMPLETED-01'],
            [
                'instructor_id' => $instructor1->id,
                'period_key' => '2026-07',
                'amount_cents' => 30000,
                'status' => Payout::STATUS_COMPLETED,
                'provider_transfer_id' => 'TRF-SEED-99120',
                'processed_at' => now()->subMonth(),
            ]
        );

        Payout::firstOrCreate(
            ['idempotency_key' => 'SEED-PAYOUT-TIMEOUT-02'],
            [
                'instructor_id' => $instructor2->id,
                'period_key' => '2026-07',
                'amount_cents' => 15000,
                'status' => Payout::STATUS_PENDING_RECONCILIATION,
                'failure_reason' => 'Connection timed out without response from payment provider.',
                'processed_at' => now()->subDays(2),
            ]
        );

        Payout::firstOrCreate(
            ['idempotency_key' => 'SEED-PAYOUT-FAILED-03'],
            [
                'instructor_id' => $instructor3->id,
                'period_key' => '2026-07',
                'amount_cents' => 8500,
                'status' => Payout::STATUS_FAILED,
                'failure_reason' => 'Beneficiary IBAN format invalid',
                'processed_at' => now()->subDays(5),
            ]
        );
    }
}
