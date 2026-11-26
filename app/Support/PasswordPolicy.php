<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** One password policy for every Phase 3.3 password entry point, plus a secure generator. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->letters()->mixedCase()->numbers();
    }

    /** Cryptographically secure temporary password: always 16 chars with all four character classes. */
    public static function generate(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_+='];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < 16) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
