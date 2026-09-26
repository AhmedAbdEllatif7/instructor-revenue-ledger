<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_allocation_id',
        'instructor_id',
        'watched_seconds',
        'share_percentage',
        'amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'watched_seconds' => 'integer',
            'share_percentage' => 'float',
            'amount_cents' => 'integer',
        ];
    }

    public function periodAllocation(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriodAllocation::class, 'period_allocation_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
