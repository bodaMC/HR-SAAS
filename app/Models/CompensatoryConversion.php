<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CompensatoryConversion extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'employee_id',
        'hours_converted',
        'leave_days_added',
        'leave_allocation_id',
        'daily_work_hours_snapshot',
        'actioned_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hours_converted' => 'decimal:2',
            'leave_days_added' => 'decimal:2',
            'daily_work_hours_snapshot' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompensatoryConversion $conversion) {
            if (empty($conversion->uuid)) {
                $conversion->uuid = Str::uuid()->toString();
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

    public function leaveAllocation(): BelongsTo
    {
        return $this->belongsTo(LeaveAllocation::class);
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_user_id');
    }
}
