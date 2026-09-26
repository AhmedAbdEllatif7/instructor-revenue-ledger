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
        Schema::dropIfExists('subscription_period_allocations');

        Schema::create('subscription_period_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('period_key', 7); // e.g. '2026-09'
            $table->unsignedBigInteger('recognized_amount_cents');
            $table->unsignedBigInteger('platform_fee_cents');
            $table->unsignedBigInteger('instructor_pool_cents');
            $table->boolean('is_breakage')->default(false); // True if student watched 0 minutes (100% to platform)
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions');
            $table->timestamps();

            // CRITICAL IDEMPOTENCY INVARIANT:
            // Prevents distributing revenue for the same subscription in the same period more than once
            // Custom short index name to stay under MySQL's 64-char limit
            $table->unique(['subscription_id', 'period_key'], 'sub_period_alloc_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_period_allocations');
    }
};
