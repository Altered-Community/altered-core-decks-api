<?php

namespace App\Enum;

enum DeckFormat: string
{
    case Standard = 'standard';
    case Nuc = 'nuc';
    case Singleton = 'singleton';
    case Sandbox = 'sandbox';

    public static function fromInput(string $value): self
    {
        return match (strtolower($value)) {
            'standard' => self::Standard,
            'nuc', 'no_unique' => self::Nuc,
            'singleton' => self::Singleton,
            'sandbox' => self::Sandbox,
            default => throw new \ValueError(sprintf('"%s" is not a valid DeckFormat.', $value)),
        };
    }
}
