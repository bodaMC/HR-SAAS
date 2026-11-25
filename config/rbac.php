<?php

/*
|--------------------------------------------------------------------------
| Permission registry (Phase 3.2)
|--------------------------------------------------------------------------
| Stable permission identifiers grouped by functional area.
|
| - groups:   permissions that exist today and can be enforced.
| - protected: administration permissions. They are NOT assignable through the
|   tenant role API; no tenant administrator can delegate them. They are held ONLY
|   through the protected System Super Admin system role (provisioned by the trusted
|   operator command), never through custom roles and never implicitly.
| - reserved: identifiers reserved for future financial modules. They are
|   documented so the model can accommodate them, but no endpoint enforces
|   them yet and the API rejects assigning them. Nothing is implemented.
*/
return [
    'groups' => [
        'employees' => [
            'employees.view',
            'employees.create',
            'employees.update',
            'employees.delete',
            'employees.view_personal_data',
        ],
        'departments' => [
            'departments.view',
            'departments.create',
            'departments.update',
            'departments.delete',
        ],
        'job_titles' => [
            'job_titles.view',
            'job_titles.create',
            'job_titles.update',
            'job_titles.delete',
        ],
        'roles' => [
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign',
            'permissions.view',
            'permissions.manage',
        ],
    ],

    'protected' => [
        'audit_logs.view',
        'company_settings.manage',
        'work_schedules.manage',
    ],

    'reserved' => [
        'payroll.view',
        'payroll.manage',
        'payroll.approve',
        'overtime.review',
        'overtime_factors.view',
        'overtime_factors.manage',
        'attendance_settlement.manage',
        'rest_compensation.adjust',
    ],

    // FINAL-V3: there is NO implicit authority for legacy Owner/Admin account types. Every
    // permission comes from an explicit tenant role assignment.

    // A user who holds ALL of these is a "permission administrator". The last
    // active one in a tenant cannot be removed.
    'permission_administrator' => ['roles.assign', 'permissions.manage'],

    'system_roles' => [
        'hr_administrator' => [
            'name' => 'HR Administrator',
            'name_ar' => 'مسؤول الموارد البشرية',
            'description' => 'Manages employees, departments, job titles and tenant roles and permissions.',
            // Explicit delegation boundary: what holders of THIS role may hand out to
            // others. Holding a permission never implies the right to delegate it.
            // Role administration and platform/financial permissions are deliberately
            // outside it, so HR cannot create further permission administrators.
            'delegates' => [
                'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.view_personal_data',
                'departments.view', 'departments.create', 'departments.update', 'departments.delete',
                'job_titles.view', 'job_titles.create', 'job_titles.update', 'job_titles.delete',
                'roles.view', 'permissions.view',
            ],
            'permissions' => [
                'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.view_personal_data',
                'departments.view', 'departments.create', 'departments.update', 'departments.delete',
                'job_titles.view', 'job_titles.create', 'job_titles.update', 'job_titles.delete',
                'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign',
                'permissions.view', 'permissions.manage',
            ],
        ],
        'system_super_admin' => [
            'name' => 'System Super Admin',
            'name_ar' => 'مدير النظام الأعلى',
            'description' => 'Highest application administrator of ONE tenant. Protected, singleton, provisioned only by the trusted operator command. Never platform authority.',
            // Exclusive: at most one holder per tenant (database-enforced).
            'exclusive' => true,
            // May delegate every assignable tenant permission (including HR administration).
            // Protected permissions and this role itself are never delegable.
            'delegates' => 'assignable',
            // HR Administrator permissions (superset) plus the protected tenant administration permissions.
            'permissions' => 'hr_plus_protected',
        ],
    ],
];
