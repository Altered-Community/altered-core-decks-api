<?php

namespace App\Enum;

enum BgaEventFormat: string
{
    case Standard = 'STANDARD';
    case NoUnique = 'NO_UNIQUE';
    case Sandbox = 'SANDBOX';
    case Singleton = 'SINGLETON';
    case SingletonNuc = 'SINGLETON_NUC';

    /** @return list<string> */
    public function toDeckFormats(): array
    {
        return match ($this) {
            self::Standard => ['standard', 'nuc'],
            self::NoUnique => ['nuc'],
            self::Sandbox => ['sandbox', 'nuc', 'standard', 'singleton', 'singleton_nuc'],
            self::Singleton => ['singleton', 'singleton_nuc'],
            self::SingletonNuc => ['singleton_nuc'],
        };
    }
}
