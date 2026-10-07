<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApprovalWorkflowStep extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'approval_workflow_id',
        'step_order',
        'role_type',
        'approver_employee_id',
        'status',
        'skipped_reason',
        'actioned_by_user_id',
        'actioned_at',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'actioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApprovalWorkflowStep $step) {
            if (empty($step->uuid)) {
                $step->uuid = Str::uuid()->toString();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function approverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_user_id');
    }
}
