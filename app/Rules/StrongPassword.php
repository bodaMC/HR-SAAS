<?php

namespace App\Rules;

use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Phase 3.3 password policy with localized EN/AR messages (min length, mixed case, a number). */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $v = is_string($value) ? $value : '';
        if (mb_strlen($v) < PasswordPolicy::MIN_LENGTH) {
            $fail(__('accounts.password_min', ['min' => PasswordPolicy::MIN_LENGTH]));
        } elseif (! preg_match('/\p{Lu}/u', $v) || ! preg_match('/\p{Ll}/u', $v)) {
            $fail(__('accounts.password_letters'));
        } elseif (! preg_match('/\d/', $v)) {
            $fail(__('accounts.password_numbers'));
        }
        if (mb_strlen($v) > 128) {
            $fail(__('accounts.password_min', ['min' => PasswordPolicy::MIN_LENGTH]));
        }
    }
}
