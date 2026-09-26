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
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique(); // Idempotency Key / UUID
            $table->string('type', 50); // subscription_payment, monthly_revenue_recognition, payout_hold, payout_completed, payout_failed_release, refund
            $table->string('description');
            $table->string('period_key', 7)->nullable(); // e.g. '2026-09'
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['type', 'period_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
