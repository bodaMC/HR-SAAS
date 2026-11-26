<?php

namespace App\Enums;

/** Contract classification. Nullable on the employee ("not specified"); no payroll, attendance or leave effect. */
enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
