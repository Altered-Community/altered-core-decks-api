<?php

namespace App\Validator\Format;

use App\Entity\DeckCard;

/**
 * Sandbox format rules:
 * - 39 to 59 cards (excluding hero) + 1 hero
 * - Max 3 copies of cards with the same name (all rarities combined)
 * - All cards from the same faction
 * - Max 15 rares (R1), max 3 exalted (R2), 0 Unique
 * - No suspended or banned cards
 * - Only cards from the supported set whitelist
 */
class SandboxFormatValidator extends AbstractDeckFormatValidator
{
    public function getFormat(): string
    {
        return 'sandbox';
    }

    protected function getMinCards(): int
    {
        return 4;
    }

    protected function getMaxCards(): int
    {
        return 100;
    }

    protected function computeFormatRulesDetail(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        return [];
    }

    protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        return [];
    }
}
