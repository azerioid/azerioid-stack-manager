<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

/** Shared stdin coercion for the mail action handlers. */
final class MailInput
{
    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function optionalDomain(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : \AzerioidPanel\Broker\Validator::domain($value);
    }
}
