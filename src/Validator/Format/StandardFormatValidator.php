<?php

namespace App\Validator\Format;

use App\Entity\DeckCard;

/**
 * Standard format rules:
 * Same as NUC + max 3 Unique cards allowed.
 */
class StandardFormatValidator extends AbstractDeckFormatValidator
{
    public function getFormat(): string { return 'standard'; }

    protected function getMinCards(): int { return 39; }
    protected function getMaxCards(): int { return 59; }

    protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        $errors = [];
        $groups = $this->groupByName($deckCards, $cardsData);

        $errors = array_merge($errors, $this->validateMaxCopiesPerName($groups, 3));

        $uniqueCount = $this->countUniqueCards($groups);
        if ($uniqueCount > 3) {
            $errors[] = sprintf('Standard format allows maximum 3 Unique cards (found %d).', $uniqueCount);
        }

        $rareCount = $this->countByRarity($groups, 'R1');
        if ($rareCount > 15) {
            $errors[] = sprintf('Standard format allows maximum 15 rare cards (found %d).', $rareCount);
        }

        $exaltedCount = $this->countByRarity($groups, 'R2');
        if ($exaltedCount > 3) {
            $errors[] = sprintf('Standard format allows maximum 3 exalted cards (found %d).', $exaltedCount);
        }

        return $errors;
    }
}
