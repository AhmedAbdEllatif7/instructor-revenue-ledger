<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerAccount extends Model
{
    use HasFactory;

    public const TYPE_ASSET = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_EQUITY = 'equity';
    public const TYPE_REVENUE = 'revenue';
    public const TYPE_EXPENSE = 'expense';

    protected $fillable = [
        'code',
        'name',
        'type',
        'holder_type',
        'holder_id',
        'currency',
    ];

    public function holder(): MorphTo
    {
        return $this->morphTo();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_account_id');
    }

    /**
     * Compute current balance in cents based on normal account direction.
     * Assets & Expenses: Debit - Credit
     * Liabilities, Equity, Revenues: Credit - Debit
     */
    public function currentBalanceCents(): int
    {
        $debits = (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount_cents');
        $credits = (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount_cents');

        if (in_array($this->type, [self::TYPE_ASSET, self::TYPE_EXPENSE], true)) {
            return $debits - $credits;
        }

        return $credits - $debits;
    }

    public function debitsSumCents(): int
    {
        return (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount_cents');
    }

    public function creditsSumCents(): int
    {
        return (int) $this->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount_cents');
    }
}
