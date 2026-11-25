<?php

namespace App\Console\Commands;

use App\Support\NationalIdPreflight;
use Illuminate\Console\Command;

class EmployeeNationalIdPreflight extends Command
{
    protected $signature = 'employees:national-id-preflight {--json : Machine-readable output}';

    protected $description = 'Read-only report of duplicate or non-canonical employee national IDs (soft-deleted included). Masks IDs; changes nothing.';

    public function handle(): int
    {
        $r = NationalIdPreflight::run();

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info("Employees scanned (incl. soft-deleted): {$r['total_rows']}; with a national ID: {$r['with_id']}");
            $this->line("Non-canonical stored values: {$r['non_canonical']}");
            $this->line("Values not matching the tenant format policy (informational, not blocking): {$r['format_mismatch']}");
            $this->line('Duplicate groups (same tenant, same normalized ID): '.count($r['duplicate_groups']));
            foreach ($r['duplicate_groups'] as $g) {
                $ids = collect($g['employees'])->map(fn ($e) => "#{$e['id']}({$e['state']})")->implode(', ');
                $this->warn("  company {$g['company_id']} id {$g['national_id_masked']} x{$g['count']}: {$ids}");
            }
        }

        $blocked = count($r['duplicate_groups']) > 0 || $r['non_canonical'] > 0;
        if ($blocked) {
            $this->error('NOT SAFE to add the unique index yet.');
            $this->line(NationalIdPreflight::remediation());

            return self::FAILURE;
        }
        $this->info('Safe: no duplicates and no non-canonical values.');

        return self::SUCCESS;
    }
}
