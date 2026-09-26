<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users');
            $table->string('period_key', 7); // Billing month, e.g. '2026-09'
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 35)->default('draft'); // draft, processing, completed, failed, pending_reconciliation
            $table->string('idempotency_key')->unique();
            $table->string('provider_transfer_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            // CRITICAL INVARIANT:
            // An instructor can NEVER receive more than one payout for the same accounting period
            $table->unique(['instructor_id', 'period_key']);
            $table->index(['status', 'period_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
