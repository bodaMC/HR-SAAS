<?php

namespace App\Rules;

use App\Models\Employee;
use App\Support\NationalId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Format + per-tenant uniqueness for an already-normalized national ID.
 * Uniqueness counts soft-deleted and terminated employees. The DB unique
 * index remains the final authority for concurrent writes.
 */
class ValidNationalId implements ValidationRule
{
    public function __construct(
        private readonly ?int $companyId,
        private readonly ?int $ignoreEmployeeId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = NationalId::normalize($value);
        if ($id === null) {
            return;
        }

        $format = NationalId::formatFor($this->companyId);
        // An employee who keeps a legacy stored value (entered before the format
        // policy existed) must still be able to save other changes.
        $unchangedLegacy = $this->ignoreEmployeeId !== null
            && Employee::withoutGlobalScopes()->withTrashed()
                ->where('id', $this->ignoreEmployeeId)
                ->where('company_id', $this->companyId)
                ->where('national_id', $id)
                ->exists();

        if ($format !== null && ! $unchangedLegacy && ! preg_match($format, $id)) {
            $fail(__('national_id.invalid'));

            return;
        }

        $q = Employee::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $this->companyId)
            ->where('national_id', $id);
        if ($this->ignoreEmployeeId !== null) {
            $q->where('id', '!=', $this->ignoreEmployeeId);
        }
        if ($q->exists()) {
            $fail(__('national_id.duplicate'));
        }
    }
}
