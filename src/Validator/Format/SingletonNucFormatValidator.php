<?php

namespace App\Validator\Format;

use App\Entity\DeckCard;

/**
 * Singleton NUC format rules:
 * Same rules as Singleton but no Unique cards allowed (limit = 0), regardless of hero.
 */
final class SingletonNucFormatValidator extends SingletonFormatValidator
{
    public function getFormat(): string
    {
        return 'singleton_nuc';
    }

    protected function getUniqueLimitForHero(?DeckCard $hero, array $cardsData): int
    {
        return 0;
    }
}
