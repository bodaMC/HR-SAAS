<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Tenancy\Traits\BelongsToCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
