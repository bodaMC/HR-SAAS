<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class ApprovalWorkflow extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'status',
        'current_step_order',
        'total_steps',
    ];

    protected function casts(): array
    {
        return [
            'current_step_order' => 'integer',
            'total_steps' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApprovalWorkflow $workflow) {
            if (empty($workflow->uuid)) {
                $workflow->uuid = Str::uuid()->toString();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStep::class)->orderBy('step_order');
    }

    public function currentStep(): ?ApprovalWorkflowStep
    {
        return $this->steps()->where('step_order', $this->current_step_order)->first();
    }
}
