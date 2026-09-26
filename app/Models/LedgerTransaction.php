<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    use HasFactory;

    public const TYPE_SUBSCRIPTION_PAYMENT = 'subscription_payment';
    public const TYPE_MONTHLY_REVENUE_RECOGNITION = 'monthly_revenue_recognition';
    public const TYPE_PAYOUT_HOLD = 'payout_hold';
    public const TYPE_PAYOUT_COMPLETED = 'payout_completed';
    public const TYPE_PAYOUT_FAILED_RELEASE = 'payout_failed_release';
    public const TYPE_REFUND = 'refund';

    protected $fillable = [
        'reference_number',
        'type',
        'description',
        'period_key',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_transaction_id');
    }

    public function totalDebitCents(): int
    {
        return (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount_cents');
    }

    public function totalCreditCents(): int
    {
        return (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount_cents');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebitCents() === $this->totalCreditCents();
    }
}
