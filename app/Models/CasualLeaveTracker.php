<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CasualLeaveTracker extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'employee_id',
        'year',
        'max_quota_days',
        'used_quota_days',
        'pending_quota_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'max_quota_days' => 'decimal:2',
            'used_quota_days' => 'decimal:2',
            'pending_quota_days' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CasualLeaveTracker $tracker) {
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

    public function getRemainingQuotaAttribute(): float
    {
        $total = (float) $this->max_quota_days;
        $used = (float) $this->used_quota_days + (float) $this->pending_quota_days;

        return max(0.0, round($total - $used, 2));
    }
}
