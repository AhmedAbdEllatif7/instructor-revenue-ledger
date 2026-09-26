<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    public const ROLE_STUDENT = 'student';
    public const ROLE_INSTRUCTOR = 'instructor';
    public const ROLE_ADMIN = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isInstructor(): bool
    {
        return $this->role === self::ROLE_INSTRUCTOR;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() || app()->environment('local', 'testing');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    public function studentWatchLogs(): HasMany
    {
        return $this->hasMany(WatchLog::class, 'student_id');
    }

    public function instructorWatchLogs(): HasMany
    {
        return $this->hasMany(WatchLog::class, 'instructor_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'student_id');
    }

    public function instructorAllocations(): HasMany
    {
        return $this->hasMany(InstructorAllocation::class, 'instructor_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'instructor_id');
    }

    public function ledgerAccount(): MorphOne
    {
        return $this->morphOne(LedgerAccount::class, 'holder');
    }
}
