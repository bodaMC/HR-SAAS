<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class CompensatoryLog extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'employee_id',
        'type',
        'hours',
        'notes',
        'reference_type',
        'reference_id',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompensatoryLog $log) {
            if (empty($log->uuid)) {
                $log->uuid = Str::uuid()->toString();
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
