<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CompensatoryBalance extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'total_earned_hours',
        'used_as_permission_hours',
        'converted_to_leave_hours',
    ];

    protected function casts(): array
    {
        return [
            'total_earned_hours' => 'decimal:2',
            'used_as_permission_hours' => 'decimal:2',
            'converted_to_leave_hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompensatoryBalance $balance) {
            if (empty($balance->uuid)) {
                $balance->uuid = Str::uuid()->toString();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getAvailableHoursAttribute(): float
    {
        $earned = (float) $this->total_earned_hours;
        $used = (float) $this->used_as_permission_hours + (float) $this->converted_to_leave_hours;

        return max(0.0, round($earned - $used, 2));
    }
}
