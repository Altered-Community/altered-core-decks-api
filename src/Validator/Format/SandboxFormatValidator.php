<?php

namespace App\Validator\Format;

use App\Entity\DeckCard;

/**
 * Sandbox format rules:
 * - 4 to 100 cards (excluding hero) + 1 hero
 * - No faction restriction
 * - Suspended and banned cards allowed
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

    protected function allowBannedCards(): bool
    {
        return true;
    }

    protected function allowSuspendedCards(): bool
    {
        return true;
    }

    protected function validateFaction(array $deckCards, array $cardsData, ?DeckCard $hero = null): array
    {
        return [];
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
