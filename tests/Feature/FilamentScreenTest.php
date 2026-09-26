<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payout;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $instructor;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'System Admin',
            'email' => 'admin@career180.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->instructor = User::factory()->create([
            'name' => 'Ahmed Abdelgawad',
            'email' => 'ahmed@instructor.com',
            'role' => User::ROLE_INSTRUCTOR,
        ]);

        $this->student = User::factory()->create([
            'name' => 'Student User',
            'email' => 'student@student.com',
            'role' => User::ROLE_STUDENT,
        ]);

        Course::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Advanced Microservices Architecture',
        ]);
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/instructor-balances')->assertRedirect('/admin/login');
        $this->get('/admin/payouts')->assertRedirect('/admin/login');
    }

    public function test_admin_can_access_dashboard_and_view_ledger_stats_widget(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Deferred Revenue')
            ->assertSee('Instructor Payables')
            ->assertSee('Platform Commission')
            ->assertSee('Disbursed Payouts');
    }

    public function test_instructor_balances_screen_lists_only_instructors_with_accurate_calculated_balances(): void
    {
        $ledgerService = app(LedgerService::class);

        // Credit instructor with 500.00 EGP (50000 cents)
        $payableAccount = $ledgerService->instructorPayableAccount($this->instructor);
        $gateway = $ledgerService->gatewayAccount();

        $ledgerService->postTransaction(
            referenceNumber: 'TEST_CREDIT_INSTRUCTOR_1',
            type: 'test_recognition',
            description: 'Test earnings credit',
            periodKey: '2026-09',
            entries: [
                ['ledger_account_id' => $gateway->id, 'direction' => 'debit', 'amount_cents' => 50000],
                ['ledger_account_id' => $payableAccount->id, 'direction' => 'credit', 'amount_cents' => 50000],
            ]
        );

        $response = $this->actingAs($this->admin)
            ->get('/admin/instructor-balances');

        $response->assertSuccessful();
        $response->assertSee('Ahmed Abdelgawad');
        $response->assertSee('ahmed@instructor.com');
        $response->assertSee('500.00 EGP');

        // Students must not be listed in instructor balances
        $response->assertDontSee('Student User');
    }

    public function test_admin_can_view_instructor_detail_infolist(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/instructor-balances/{$this->instructor->id}")
            ->assertSuccessful()
            ->assertSee('Ahmed Abdelgawad')
            ->assertSee('ahmed@instructor.com')
            ->assertSee('Available Withdrawable Balance')
            ->assertSee('Lifetime Platform Earnings');
    }

    public function test_payout_history_screen_displays_payouts_with_status_badges_and_amounts(): void
    {
        $payout = Payout::create([
            'instructor_id' => $this->instructor->id,
            'period_key' => '2026-09',
            'amount_cents' => 35000,
            'status' => Payout::STATUS_COMPLETED,
            'idempotency_key' => 'test-idemp-payout-101',
            'provider_transfer_id' => 'TRF-TEST-8849',
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/payouts');

        $response->assertSuccessful();
        $response->assertSee('Ahmed Abdelgawad');
        $response->assertSee('350.00 EGP');
        $response->assertSee('2026-09');
        $response->assertSee('TRF-TEST-8849');
    }

    public function test_admin_can_view_payout_detail_page_with_diagnostics_and_provider_details(): void
    {
        $payout = Payout::create([
            'instructor_id' => $this->instructor->id,
            'period_key' => '2026-09',
            'amount_cents' => 12500,
            'status' => Payout::STATUS_FAILED,
            'idempotency_key' => 'test-idemp-failed-99',
            'failure_reason' => 'Beneficiary bank account closed',
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/payouts/{$payout->id}")
            ->assertSuccessful()
            ->assertSee('Beneficiary bank account closed')
            ->assertSee('test-idemp-failed-99')
            ->assertSee('125.00 EGP');
    }

    public function test_screens_are_strictly_read_only_with_no_create_or_edit_routes(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/instructor-balances/create')
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get("/admin/instructor-balances/{$this->instructor->id}/edit")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get('/admin/payouts/create')
            ->assertNotFound();

        $payout = Payout::create([
            'instructor_id' => $this->instructor->id,
            'period_key' => '2026-09',
            'amount_cents' => 10000,
            'status' => Payout::STATUS_COMPLETED,
            'idempotency_key' => 'test-payout-ro',
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/payouts/{$payout->id}/edit")
            ->assertNotFound();
    }
}
