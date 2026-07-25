<?php

namespace App\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;

/**
 * Standard format rules:
 * Same as NUC + max 3 Unique cards allowed.
 */
class StandardFormatValidator extends AbstractDeckFormatValidator
{
    public function getFormat(): string
    {
        return 'standard';
    }

    protected function getMinCards(): int
    {
        return 39;
    }

    protected function getMaxCards(): int
    {
        return 59;
    }

    protected function validateFormatRules(Deck $deck, array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        $errors = [];
        $groups = $this->groupByName($deckCards, $cardsData);

        $errors = array_merge($errors, $this->validateMaxCopiesPerName($groups, 3));
        $errors = array_merge($errors, $this->validateUniqueQuantity($deckCards, $cardsData));

        $uniqueCount = $this->countUniqueCards($groups);
        if ($uniqueCount > 3) {
            $errors[] = sprintf('Standard format allows maximum 3 Unique cards (found %d).', $uniqueCount);
        }

        $rareCount = $this->countByRarity($groups, 'R1') + $this->countByRarity($groups, 'R2');
        if ($rareCount > 15) {
            $errors[] = sprintf('Standard format allows maximum 15 rare cards (found %d).', $rareCount);
        }

        $exaltedCount = $this->countByRarity($groups, 'E');
        if ($exaltedCount > 3) {
            $errors[] = sprintf('Standard format allows maximum 3 exalted cards (found %d).', $exaltedCount);
        }

        return $errors;
    }

    protected function computeFormatRulesDetail(Deck $deck, array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        $groups = $this->groupByName($deckCards, $cardsData);

        return [
            'copies' => [] === $this->validateMaxCopiesPerName($groups, 3) && [] === $this->validateUniqueQuantity($deckCards, $cardsData),
            'uniqueQuantity' => $this->countUniqueCards($groups) <= 3,
            'rareQuantity' => ($this->countByRarity($groups, 'R1') + $this->countByRarity($groups, 'R2')) <= 15,
            'exaltedQuantity' => $this->countByRarity($groups, 'E') <= 3,
        ];
    }
}
