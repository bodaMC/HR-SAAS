<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    /**
     * Get a human-readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            UserRole::Owner => 'Owner',
            UserRole::Admin => 'Admin',
            UserRole::Manager => 'Manager',
            UserRole::Employee => 'Employee',
        };
    }
}
