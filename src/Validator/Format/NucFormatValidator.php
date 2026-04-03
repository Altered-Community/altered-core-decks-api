<?php

namespace App\Validator\Format;

use App\Entity\DeckCard;

/**
 * NUC format rules:
 * - 39 to 59 cards (excluding hero) + 1 hero
 * - Max 3 copies of cards with the same name (all rarities combined)
 * - All cards from the same faction
 * - Max 15 rares (R1), max 3 exalted (R2), 0 Unique
 * - No suspended or banned cards
 */
class NucFormatValidator extends AbstractDeckFormatValidator
{
    public function getFormat(): string { return 'nuc'; }

    protected function getMinCards(): int { return 39; }
    protected function getMaxCards(): int { return 59; }

    protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        $errors = [];
        $groups = $this->groupByName($deckCards, $cardsData);

        $errors = array_merge($errors, $this->validateMaxCopiesPerName($groups, 3));

        $uniqueCount = $this->countUniqueCards($groups);
        if ($uniqueCount > 0) {
            $errors[] = sprintf('NUC format does not allow Unique cards (found %d).', $uniqueCount);
        }

        $rareCount = $this->countByRarity($groups, 'R1');
        if ($rareCount > 15) {
            $errors[] = sprintf('NUC format allows maximum 15 rare cards (found %d).', $rareCount);
        }

        $exaltedCount = $this->countByRarity($groups, 'R2');
        if ($exaltedCount > 3) {
            $errors[] = sprintf('NUC format allows maximum 3 exalted cards (found %d).', $exaltedCount);
        }

        return $errors;
    }
}
