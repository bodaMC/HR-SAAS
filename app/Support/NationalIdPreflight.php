<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Read-only duplicate detection for employees.national_id.
 *
 * Includes soft-deleted and terminated employees, compares the NORMALIZED
 * value inside each tenant, and exposes only masked IDs. It issues SELECT
 * statements only: it never updates, deletes or merges a row.
 */
final class NationalIdPreflight
{
    /**
     * @return array{total_rows:int, with_id:int, duplicate_groups:list<array<string,mixed>>, non_canonical:int, format_mismatch:int}
     */
    public static function run(): array
    {
        $groups = [];
        $total = 0;
        $withId = 0;
        $nonCanonical = 0;
        $formatMismatch = 0;

        DB::table('employees')
            ->select(['id', 'company_id', 'national_id', 'deleted_at', 'employment_status'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$groups, &$total, &$withId, &$nonCanonical, &$formatMismatch) {
                foreach ($rows as $r) {
                    $total++;
                    if ($r->national_id === null) {
                        continue;
                    }
                    $withId++;
                    $norm = NationalId::normalize($r->national_id);
                    if ($norm !== $r->national_id) {
                        $nonCanonical++;
                    }
                    if ($norm === null) {
                        continue;
                    }
                    $format = NationalId::formatFor((int) $r->company_id);
                    if ($format !== null && ! preg_match($format, $norm)) {
                        $formatMismatch++;
                    }
                    $groups[$r->company_id.'|'.$norm][] = $r;
                }
            });

        $dups = [];
        foreach ($groups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            [$company, $norm] = explode('|', $key, 2);
            $dups[] = [
                'company_id' => (int) $company,
                'national_id_masked' => NationalId::mask($norm),
                'count' => count($rows),
                'employees' => array_map(fn ($r) => [
                    'id' => (int) $r->id,
                    'state' => $r->deleted_at ? 'soft_deleted' : (string) $r->employment_status,
                ], $rows),
            ];
        }

        return [
            'total_rows' => $total,
            'with_id' => $withId,
            'duplicate_groups' => $dups,
            'non_canonical' => $nonCanonical,
            'format_mismatch' => $formatMismatch,
        ];
    }

    public static function remediation(): string
    {
        return "Remediation (manual, reviewed by a person; nothing is changed automatically):\n"
            ."  1. Run `php artisan employees:national-id-preflight` to list the affected employee IDs (national IDs are masked).\n"
            ."  2. For each duplicate group decide, with HR, which record holds the correct ID; correct or clear (set to NULL) the others through the application or a reviewed SQL script.\n"
            ."  3. Rows whose stored value is not canonical (Arabic digits, spaces, hyphens, empty string) must be normalized the same way before the unique index is created.\n"
            ."  4. Re-run the preflight until it is clean, then run `php artisan migrate`.\n"
            .'  Do not delete or merge employees to make the check pass.';
    }
}
