<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LeaveAllocation extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'statutory_entitlement_days',
        'pro_rata_factor',
        'allocated_days',
        'carried_over_days',
        'converted_from_compensatory_days',
        'used_days',
        'pending_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'statutory_entitlement_days' => 'decimal:2',
            'pro_rata_factor' => 'decimal:4',
            'allocated_days' => 'decimal:2',
            'carried_over_days' => 'decimal:2',
            'converted_from_compensatory_days' => 'decimal:2',
            'used_days' => 'decimal:2',
            'pending_days' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveAllocation $allocation) {
            if (empty($allocation->uuid)) {
                $allocation->uuid = Str::uuid()->toString();
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * Get remaining available leave days.
     */
    public function getRemainingDaysAttribute(): float
    {
        $total = (float) $this->allocated_days + (float) $this->carried_over_days + (float) $this->converted_from_compensatory_days;
        $deducted = (float) $this->used_days + (float) $this->pending_days;

        return max(0.0, round($total - $deducted, 2));
    }
}
