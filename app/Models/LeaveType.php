<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LeaveType extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'default_days_per_year',
        'is_paid',
        'deducts_from_annual_balance',
        'max_days_per_year',
        'max_consecutive_days',
        'requires_medical_certificate',
        'carries_forward',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_days_per_year' => 'decimal:2',
            'is_paid' => 'boolean',
            'deducts_from_annual_balance' => 'boolean',
            'max_days_per_year' => 'decimal:2',
            'max_consecutive_days' => 'integer',
            'requires_medical_certificate' => 'boolean',
            'carries_forward' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveType $leaveType) {
            if (empty($leaveType->uuid)) {
                $leaveType->uuid = Str::uuid()->toString();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
