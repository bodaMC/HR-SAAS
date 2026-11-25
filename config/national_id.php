<?php

/*
 | National ID policy (Phase 3.1).
 |
 | Each policy is a named PCRE applied to the NORMALIZED value (Western
 | digits, no whitespace/hyphens). The Egyptian national ID is exactly 14
 | digits. A tenant can be mapped to a different policy through
 | `by_company` (company id => policy name) without code changes; unknown
 | company ids use `default`. Setting a policy to null disables the format
 | check for that policy (uniqueness still applies). National ID is optional.
 */
return [
    'default' => 'egypt',

    'policies' => [
        'egypt' => '/^[0-9]{14}$/',
        'none' => null,
    ],

    'by_company' => [
        // 123 => 'none',
    ],
];
