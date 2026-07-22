<?php

namespace App\Enum;

enum DeckFormat: string
{
    case Standard = 'standard';
    case Nuc = 'nuc';
    case Singleton = 'singleton';
    case SingletonNuc = 'singleton_nuc';
    case Sandbox = 'sandbox';
    case Test = 'test';
    case Frontier = 'frontier';
    case Sealed = 'sealed';

    public static function fromInput(string $value): self
    {
        return match (strtolower($value)) {
            'standard' => self::Standard,
            'nuc', 'no_unique' => self::Nuc,
            'singleton' => self::Singleton,
            'singleton_nuc', 'singleton_no_unique' => self::SingletonNuc,
            'sandbox' => self::Sandbox,
            'test' => self::Test,
            'frontier' => self::Frontier,
            'sealed' => self::Sealed,
            default => throw new \ValueError(sprintf('"%s" is not a valid DeckFormat.', $value)),
        };
    }
}
