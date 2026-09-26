<?php

namespace App\Security;

/**
 * A username is publicly exposed (e.g. as a deck author), so it must never be an email address.
 * Any value containing "@" is rejected, which is stricter than email validation on purpose.
 */
final class SafeUsername
{
    public static function sanitize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ('' === $value || str_contains($value, '@')) {
            return null;
        }

        return $value;
    }
}
