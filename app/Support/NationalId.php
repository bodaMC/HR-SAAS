<?php

namespace App\Support;

/**
 * Canonical form of an employee national ID.
 *
 * - Arabic-Indic (U+0660-0669) and Extended Arabic-Indic (U+06F0-06F9)
 *   digits become Western digits.
 * - Whitespace (incl. NBSP and zero-width/bidi marks) and hyphens are removed.
 * - An empty result is NULL (multiple NULLs are allowed by the DB index).
 */
final class NationalId
{
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $v = strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $v = preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\-]+/u', '', $v) ?? $v;

        return $v === '' ? null : $v;
    }

    /** Masked form for logs and reports: never expose a full ID. */
    public static function mask(?string $id): string
    {
        if ($id === null || $id === '') {
            return '(empty)';
        }
        $len = mb_strlen($id);

        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4).mb_substr($id, -4);
    }

    /** Regex for the tenant's policy, or null when no format is enforced. */
    public static function formatFor(?int $companyId): ?string
    {
        $policy = config('national_id.by_company.'.$companyId) ?? config('national_id.default');

        return config('national_id.policies.'.$policy);
    }
}
