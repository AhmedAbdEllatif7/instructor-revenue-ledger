<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPeriodAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'period_key',
        'recognized_amount_cents',
        'platform_fee_cents',
        'instructor_pool_cents',
        'is_breakage',
        'ledger_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'recognized_amount_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'instructor_pool_cents' => 'integer',
            'is_breakage' => 'boolean',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function instructorAllocations(): HasMany
    {
        return $this->hasMany(InstructorAllocation::class, 'period_allocation_id');
    }
}
