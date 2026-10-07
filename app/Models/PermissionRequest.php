<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PermissionRequest extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'type',
        'date',
        'start_time',
        'end_time',
        'duration_hours',
        'is_late_submission',
        'overtime_factor',
        'salary_deduction_hours',
        'reason',
        'status',
        'is_implicit_approval',
        'final_actioned_by_user_id',
        'final_actioned_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'duration_hours' => 'decimal:2',
            'is_late_submission' => 'boolean',
            'overtime_factor' => 'decimal:2',
            'salary_deduction_hours' => 'decimal:2',
            'is_implicit_approval' => 'boolean',
            'final_actioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PermissionRequest $request) {
            if (empty($request->uuid)) {
                $request->uuid = Str::uuid()->toString();
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

    public function finalActionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_actioned_by_user_id');
    }

    public function workflow(): MorphOne
    {
        return $this->morphOne(ApprovalWorkflow::class, 'approvable');
    }
}
