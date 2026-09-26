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
        Schema::create('instructor_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_allocation_id')->constrained('subscription_period_allocations')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users');
            $table->unsignedInteger('watched_seconds');
            $table->decimal('share_percentage', 7, 4); // e.g. 99.9999%
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->index(['instructor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_allocations');
    }
};
