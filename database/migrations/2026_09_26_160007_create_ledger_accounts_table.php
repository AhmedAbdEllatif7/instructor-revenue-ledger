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
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g., 'assets:gateway', 'liabilities:instructor:payable:12'
            $table->string('name');
            $table->string('type', 20); // asset, liability, equity, revenue, expense
            $table->nullableMorphs('holder'); // holder_type, holder_id (e.g. User for instructor)
            $table->string('currency', 3)->default('EGP');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
