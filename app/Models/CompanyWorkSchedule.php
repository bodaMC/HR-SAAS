<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CompanyWorkSchedule extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'work_days',
        'work_start_time',
        'work_end_time',
        'standard_daily_hours',
        'flexible_arrival_window_minutes',
        'ramadan_start_date',
        'ramadan_end_date',
        'ramadan_work_start_time',
        'ramadan_work_end_time',
        'ramadan_daily_hours',
        'permission_submission_deadline',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'standard_daily_hours' => 'decimal:2',
            'flexible_arrival_window_minutes' => 'integer',
            'ramadan_start_date' => 'date',
            'ramadan_end_date' => 'date',
            'ramadan_daily_hours' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyWorkSchedule $schedule) {
            if (empty($schedule->uuid)) {
                $schedule->uuid = Str::uuid()->toString();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get applicable daily working hours for a given date.
     */
    public function getDailyHoursForDate(Carbon|string $date): float
    {
        $carbonDate = is_string($date) ? Carbon::parse($date) : $date;

        if ($this->isRamadanDate($carbonDate) && $this->ramadan_daily_hours !== null) {
            return (float) $this->ramadan_daily_hours;
        }

        return (float) $this->standard_daily_hours;
    }

    /**
     * Check if a date falls within the configured Ramadan period.
     */
    public function isRamadanDate(Carbon|string $date): bool
    {
        if (! $this->ramadan_start_date || ! $this->ramadan_end_date) {
            return false;
        }

        $carbonDate = is_string($date) ? Carbon::parse($date) : $date;

        return $carbonDate->betweenIncluded($this->ramadan_start_date, $this->ramadan_end_date);
    }
}
