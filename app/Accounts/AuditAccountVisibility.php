<?php

namespace App\Accounts;

use App\Models\User;
use App\Services\Audit\AuditVisibility;

/** Account events about an administratively protected account are restricted to full auditors. */
final class AuditAccountVisibility
{
    public static function for(string $action, User $target): string
    {
        return AccountGuard::isProtectedAccount($target) ? AuditVisibility::RESTRICTED : AuditVisibility::for($action);
    }
}
