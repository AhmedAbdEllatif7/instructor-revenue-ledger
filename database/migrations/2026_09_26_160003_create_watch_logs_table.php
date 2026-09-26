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
        Schema::create('watch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->string('period_key', 7); // e.g. '2026-09'
            $table->unsignedInteger('seconds_watched');
            $table->timestamp('watched_at');
            $table->timestamps();

            // Composite indexes optimized for monthly aggregation across millions of rows
            $table->index(['period_key', 'student_id']);
            $table->index(['period_key', 'instructor_id']);
            $table->index(['period_key', 'student_id', 'instructor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watch_logs');
    }
};
