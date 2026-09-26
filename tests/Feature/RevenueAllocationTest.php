<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\InstructorAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPeriodAllocation;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchLog;
use App\Services\Allocation\RevenueAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueAllocationTest extends TestCase
{
    use RefreshDatabase;

    private RevenueAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RevenueAllocationService::class);
    }

    public function test_allocates_monthly_revenue_proportionally_to_watch_time(): void
    {
        $student = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $instructorA = User::create([
            'name' => 'Instructor Alpha',
            'email' => 'alpha@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $instructorB = User::create([
            'name' => 'Instructor Beta',
            'email' => 'beta@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $courseA = Course::create(['instructor_id' => $instructorA->id, 'title' => 'Course A']);
        $courseB = Course::create(['instructor_id' => $instructorB->id, 'title' => 'Course B']);

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Tier',
            'duration_months' => 1,
            'price_cents' => 10000, // 100.00 EGP
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 1,
            'total_price_cents' => 10000,
            'monthly_price_cents' => 10000,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-09-30 23:59:59',
        ]);

        // 60 minutes for Instructor A (3600s), 40 minutes for Instructor B (2400s)
        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $courseA->id,
            'instructor_id' => $instructorA->id,
            'period_key' => '2026-09',
            'seconds_watched' => 3600,
            'watched_at' => '2026-09-10 12:00:00',
        ]);

        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $courseB->id,
            'instructor_id' => $instructorB->id,
            'period_key' => '2026-09',
            'seconds_watched' => 2400,
            'watched_at' => '2026-09-12 12:00:00',
        ]);

        $allocation = $this->service->allocate($subscription, '2026-09', 30);

        // Assertions
        $this->assertEquals(10000, $allocation->recognized_amount_cents);
        $this->assertEquals(3000, $allocation->platform_fee_cents); // 30%
        $this->assertEquals(7000, $allocation->instructor_pool_cents); // 70%
        $this->assertFalse($allocation->is_breakage);

        $this->assertCount(2, $allocation->instructorAllocations);

        $shareA = $allocation->instructorAllocations->firstWhere('instructor_id', $instructorA->id);
        $shareB = $allocation->instructorAllocations->firstWhere('instructor_id', $instructorB->id);

        $this->assertEquals(4200, $shareA->amount_cents); // 60% of 7000 = 4200
        $this->assertEquals(2800, $shareB->amount_cents); // 40% of 7000 = 2800

        // Strict Financial Invariant check: Total = Platform + Instructors
        $this->assertEquals(
            $allocation->recognized_amount_cents,
            $allocation->platform_fee_cents + $shareA->amount_cents + $shareB->amount_cents
        );
    }

    public function test_handles_rounding_fractions_by_floor_and_platform_remainder_absorption(): void
    {
        $student = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        // 3 instructors with identical watch time -> 7000 cents / 3 = 2333.3333 cents
        $instructors = [];
        for ($i = 1; $i <= 3; $i++) {
            $inst = User::create([
                'name' => "Instructor $i",
                'email' => "inst$i@example.com",
                'password' => bcrypt('password'),
                'role' => User::ROLE_INSTRUCTOR,
            ]);
            $course = Course::create(['instructor_id' => $inst->id, 'title' => "Course $i"]);
            WatchLog::create([
                'student_id' => $student->id,
                'course_id' => $course->id,
                'instructor_id' => $inst->id,
                'period_key' => '2026-09',
                'seconds_watched' => 1000,
                'watched_at' => now(),
            ]);
            $instructors[] = $inst;
        }

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Tier',
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

        $allocation = $this->service->allocate($subscription, '2026-09', 30);

        // Floor rounding: floor(7000 / 3) = 2333 cents each
        // 2333 * 3 = 6999 cents. Leftover = 1 cent absorbed by platform!
        // Platform fee = 10000 - 6999 = 3001 cents.
        $this->assertEquals(3001, $allocation->platform_fee_cents);

        $allocatedSum = 0;
        foreach ($instructors as $inst) {
            $share = $allocation->instructorAllocations->firstWhere('instructor_id', $inst->id);
            $this->assertEquals(2333, $share->amount_cents);
            $allocatedSum += $share->amount_cents;
        }

        $this->assertEquals(6999, $allocatedSum);
        $this->assertEquals(10000, $allocation->platform_fee_cents + $allocatedSum);
    }

    public function test_handles_breakage_when_student_has_zero_watch_time(): void
    {
        $student = User::create([
            'name' => 'Inactive Student',
            'email' => 'inactive@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Tier',
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

        // Student watched 0 seconds
        $allocation = $this->service->allocate($subscription, '2026-09', 30);

        $this->assertTrue($allocation->is_breakage);
        $this->assertEquals(10000, $allocation->recognized_amount_cents);
        $this->assertEquals(10000, $allocation->platform_fee_cents); // 100% retained by platform
        $this->assertEquals(0, $allocation->instructor_pool_cents);
        $this->assertCount(0, $allocation->instructorAllocations);
    }

    public function test_allocation_is_idempotent_when_called_multiple_times(): void
    {
        $student = User::create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STUDENT,
        ]);

        $instructor = User::create([
            'name' => 'Prof. X',
            'email' => 'profx@example.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $course = Course::create(['instructor_id' => $instructor->id, 'title' => 'Mutant Genetics']);

        WatchLog::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'period_key' => '2026-09',
            'seconds_watched' => 1800,
            'watched_at' => now(),
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly',
            'duration_months' => 1,
            'price_cents' => 5000,
        ]);

        $subscription = Subscription::create([
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'term_months' => 1,
            'total_price_cents' => 5000,
            'monthly_price_cents' => 5000,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-09-30',
        ]);

        // Call 1
        $firstAllocation = $this->service->allocate($subscription, '2026-09');

        // Call 2 (Duplicate execution)
        $secondAllocation = $this->service->allocate($subscription, '2026-09');

        // Assert they return the exact same database row
        $this->assertEquals($firstAllocation->id, $secondAllocation->id);
        $this->assertCount(1, SubscriptionPeriodAllocation::all());
        $this->assertCount(1, InstructorAllocation::all());
    }

    public function test_batch_period_allocation_processes_active_subscriptions(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Tier',
            'duration_months' => 1,
            'price_cents' => 10000,
        ]);

        // Create 3 active subscriptions
        for ($i = 1; $i <= 3; $i++) {
            $student = User::create([
                'name' => "Student $i",
                'email' => "s$i@example.com",
                'password' => bcrypt('password'),
                'role' => User::ROLE_STUDENT,
            ]);

            Subscription::create([
                'student_id' => $student->id,
                'plan_id' => $plan->id,
                'term_months' => 1,
                'total_price_cents' => 10000,
                'monthly_price_cents' => 10000,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => '2026-09-01',
                'ends_at' => '2026-09-30',
            ]);
        }

        $processed = $this->service->allocatePeriod('2026-09');

        $this->assertEquals(3, $processed);
        $this->assertEquals(3, SubscriptionPeriodAllocation::where('period_key', '2026-09')->count());
    }
}
