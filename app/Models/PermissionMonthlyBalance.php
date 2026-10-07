<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PermissionMonthlyBalance extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'year',
        'month',
        'normal_allocated_hours',
        'normal_used_hours',
        'normal_pending_hours',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'normal_allocated_hours' => 'decimal:2',
            'normal_used_hours' => 'decimal:2',
            'normal_pending_hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PermissionMonthlyBalance $balance) {
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

    public function getRemainingNormalHoursAttribute(): float
    {
        $allocated = (float) $this->normal_allocated_hours;
        $used = (float) $this->normal_used_hours + (float) $this->normal_pending_hours;

        return max(0.0, round($allocated - $used, 2));
    }
}
