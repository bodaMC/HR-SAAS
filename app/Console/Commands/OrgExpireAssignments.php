<?php

namespace App\Console\Commands;

use App\Models\OrgPositionAssignment;
use App\Models\User;
use App\Organization\OrgService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Natural expiry of organization assignments. An assignment whose end date has passed stays "active" in the table until something touches it.
 * Reads already ignore it (they filter by date). This command makes the stored state match: status ended, cause expired, placement flag cleared,
 * history kept, one audit row each. Safe to run repeatedly. Also runs implicitly, per employee, before any placement-sensitive write.
 */
class OrgExpireAssignments extends Command
{
    protected $signature = 'org:expire-assignments {--company= : Company id}';

    protected $description = 'Mark assignments whose end date has passed as ended (history kept, placement released). Idempotent.';

    public function handle(OrgService $org): int
    {
        $total = 0;
        $pairs = OrgPositionAssignment::withoutGlobalScopes()->where('status', 'active')->whereNotNull('ends_on')
            ->when($this->option('company'), fn ($q, $c) => $q->where('company_id', (int) $c))
            ->select('company_id', 'employee_id', 'ends_on')->get()->groupBy(fn ($r) => $r->company_id.':'.$r->employee_id);
        foreach ($pairs as $key => $_) {
            [$cid, $eid] = array_map('intval', explode(':', $key));
            $actor = new User;
            $actor->company_id = $cid;
            $total += DB::transaction(function () use ($org, $actor, $cid, $eid) {
                DB::table('companies')->where('id', $cid)->lockForUpdate()->first();

                return $org->expireForEmployee($actor, $eid);
            });
        }
        $this->info("Expired assignments: $total");

        return self::SUCCESS;
    }
}
