<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LeaveRequest extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'project_id',
        'start_date',
        'end_date',
        'calendar_days_count',
        'deducted_leave_days',
        'reason',
        'is_late_submission',
        'status',
        'is_implicit_approval',
        'implicit_approval_reason',
        'medical_certificate_path',
        'medical_doctor_name',
        'medical_examination_date',
        'final_actioned_by_user_id',
        'final_actioned_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'calendar_days_count' => 'integer',
            'deducted_leave_days' => 'decimal:2',
            'is_late_submission' => 'boolean',
            'is_implicit_approval' => 'boolean',
            'medical_examination_date' => 'date',
            'final_actioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveRequest $request) {
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
