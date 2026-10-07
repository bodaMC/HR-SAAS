<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Tenancy\Traits\BelongsToCompany;
use Carbon\Carbon;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * Note: uuid and company_id are excluded from fillable.
     * - uuid is auto-generated in the creating hook below.
     * - company_id is strictly derived from TenantContext via BelongsToCompany.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'national_id',
        'hire_date',
        'termination_date',
        'employment_status',
        'department_id',
        'job_title_id',
        'manager_id',
        'user_id',
        'team_leader_id',
        'is_disabled',
        'insurance_years',
        'is_hod',
        'is_team_leader',
        'is_project_engineer',
        'is_ceo',
    ];

    /**
     * Auto-generate a UUID for new Employee records.
     */
    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            if (empty($employee->uuid)) {
                $employee->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employment_status' => EmploymentStatus::Active,
        'is_disabled' => false,
        'insurance_years' => 0.00,
        'is_hod' => false,
        'is_team_leader' => false,
        'is_project_engineer' => false,
        'is_ceo' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'employment_status' => EmploymentStatus::class,
            'gender' => Gender::class,
            'is_disabled' => 'boolean',
            'insurance_years' => 'decimal:2',
            'is_hod' => 'boolean',
            'is_team_leader' => 'boolean',
            'is_project_engineer' => 'boolean',
            'is_ceo' => 'boolean',
        ];
    }

    /**
     * Get the full name of the employee.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the company that owns the employee.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the department associated with the employee.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the job title associated with the employee.
     */
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    /**
     * Get the direct manager of the employee.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Get the direct reports of the employee.
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    /**
     * Get the linked user account for the employee, if any.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this employee is managed directly or indirectly by the given manager user or employee.
     */
    public function isManagedBy(User|Employee|null $manager): bool
    {
        if (! $manager) {
            return false;
        }

        $managerEmployeeId = $manager instanceof User
            ? $manager->employee?->id
            : $manager->id;

        if (! $managerEmployeeId) {
            return false;
        }

        $currentManager = $this->manager;
        $visited = [$this->id];
        $maxDepth = 50;

        while ($currentManager && $maxDepth > 0) {
            if ($currentManager->id === $managerEmployeeId) {
                return true;
            }

            if (in_array($currentManager->id, $visited, true)) {
                break; // Prevent cycles
            }

            $visited[] = $currentManager->id;
            $currentManager = $currentManager->manager;
            $maxDepth--;
        }

        return false;
    }

    /**
     * Check if this employee is a direct or indirect manager of the given employee.
     */
    public function isManagerOf(Employee $employee): bool
    {
        return $employee->isManagedBy($this);
    }

    /**
     * Get all direct and indirect subordinate employee IDs.
     *
     * @return list<int>
     */
    public function getAllSubordinateIds(): array
    {
        $subordinateIds = [];
        $queue = $this->directReports()->pluck('id')->all();

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            if (! in_array($currentId, $subordinateIds, true)) {
                $subordinateIds[] = $currentId;
                $directReportIds = Employee::where('manager_id', $currentId)->pluck('id')->all();
                foreach ($directReportIds as $reportId) {
                    if (! in_array($reportId, $subordinateIds, true)) {
                        $queue[] = $reportId;
                    }
                }
            }
        }

        return $subordinateIds;
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_leader_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'employee_projects')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function leaveAllocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class);
    }

    public function casualLeaveTrackers(): HasMany
    {
        return $this->hasMany(CasualLeaveTracker::class);
    }

    public function sickLeaveTrackers(): HasMany
    {
        return $this->hasMany(SickLeaveTracker::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function permissionMonthlyBalances(): HasMany
    {
        return $this->hasMany(PermissionMonthlyBalance::class);
    }

    public function permissionRequests(): HasMany
    {
        return $this->hasMany(PermissionRequest::class);
    }

    public function compensatoryBalance(): HasOne
    {
        return $this->hasOne(CompensatoryBalance::class);
    }

    public function compensatoryLogs(): HasMany
    {
        return $this->hasMany(CompensatoryLog::class);
    }

    public function getAgeAt(Carbon|string $date): int
    {
        if (! $this->date_of_birth) {
            return 0;
        }

        $carbonDate = is_string($date) ? Carbon::parse($date) : $date;

        return (int) $this->date_of_birth->diffInYears($carbonDate);
    }

    public function getTenureYearsAt(Carbon|string $date): float
    {
        if (! $this->hire_date) {
            return 0.0;
        }

        $carbonDate = is_string($date) ? Carbon::parse($date) : $date;

        return (float) round($this->hire_date->diffInDays($carbonDate) / 365.25, 2);
    }

    public function isAbsentOn(Carbon|string $date): bool
    {
        $carbonDate = is_string($date) ? Carbon::parse($date)->format('Y-m-d') : $date->format('Y-m-d');

        return $this->leaveRequests()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $carbonDate)
            ->whereDate('end_date', '>=', $carbonDate)
            ->exists();
    }
}
