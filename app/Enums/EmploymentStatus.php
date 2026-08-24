<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /**
     * Get a human-readable label for the employment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnLeave => 'On Leave',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        };
    }
}
