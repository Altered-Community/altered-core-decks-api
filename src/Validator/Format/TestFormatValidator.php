<?php

namespace App\Validator\Format;

/**
 * Test format rules:
 * Same as Sandbox but without the set 7 (FUGUE) restriction:
 * - 4 to 100 cards (excluding hero) + 1 hero
 * - No faction restriction
 * - Suspended and banned cards allowed
 * - Forbidden set (FUGUE) is allowed
 */
final class TestFormatValidator extends SandboxFormatValidator
{
    public function getFormat(): string
    {
        return 'test';
    }

    protected function getAllowedSets(): array
    {
        return [...self::VALID_SETS, ...self::FORBIDDEN_SETS];
    }

    protected function getForbiddenSets(): array
    {
        return [];
    }
}
