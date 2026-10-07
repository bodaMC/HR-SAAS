<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SickLeaveTracker extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'employee_id',
        'year',
        'policy_threshold_days',
        'used_sick_days',
        'excess_sick_days',
        'total_penalty_deduction_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'policy_threshold_days' => 'decimal:2',
            'used_sick_days' => 'decimal:2',
            'excess_sick_days' => 'decimal:2',
            'total_penalty_deduction_days' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SickLeaveTracker $tracker) {
            if (empty($tracker->uuid)) {
                $tracker->uuid = Str::uuid()->toString();
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
}
