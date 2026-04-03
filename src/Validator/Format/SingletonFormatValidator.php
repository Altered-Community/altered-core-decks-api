<?php

namespace App\Validator\Format;

use App\Entity\DeckCard;

/**
 * Singleton format rules:
 * - 59 to 79 cards (excluding hero) + 1 hero
 * - Exactly 1 copy per rarity of a card with the same name, max 3 total
 * - All cards from the same faction
 * - Unique limit depends on the hero chosen (3, 4 or 5)
 * - No suspended or banned cards
 */
class SingletonFormatValidator extends AbstractDeckFormatValidator
{
    private const UNIQUE_LIMITS = [
        3 => ['teija', 'kojo', 'basira', 'sigismar', 'nevenka', 'fen', 'treyst'],
        4 => ['subhash', 'isaree', 'atsadi', 'arjun', 'kauri', 'rin', 'zhen', 'akesha'],
        5 => ['sierra', 'sol', 'auraq', 'nadir', 'waru', 'gulrang', 'lindiwe', 'afanas', 'moyo'],
    ];

    public function getFormat(): string { return 'singleton'; }

    protected function getMinCards(): int { return 59; }
    protected function getMaxCards(): int { return 79; }

    protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        $errors = [];
        $groups = $this->groupByName($deckCards, $cardsData);

        // Max 1 copy per rarity, max 3 total per name
        foreach ($groups as $name => $rarities) {
            $total = array_sum($rarities);
            if ($total > 3) {
                $errors[] = sprintf('Singleton: "%s" exceeds 3 copies total (got %d).', $name, $total);
            }
            foreach ($rarities as $rarity => $qty) {
                if ($qty > 1) {
                    $errors[] = sprintf('Singleton: "%s" has %d copies of rarity %s (max 1 per rarity).', $name, $qty, $rarity);
                }
            }
        }

        // Unique limit based on hero
        $uniqueLimit = $this->getUniqueLimitForHero($hero, $cardsData);
        $uniqueCount = $this->countUniqueCards($groups);
        if ($uniqueCount > $uniqueLimit) {
            $heroName = $hero ? $this->getCardName($cardsData[$hero->getCardReference()] ?? []) : 'unknown';
            $errors[] = sprintf(
                'Singleton: hero "%s" allows maximum %d Unique cards (found %d).',
                $heroName, $uniqueLimit, $uniqueCount
            );
        }

        return $errors;
    }

    private function getUniqueLimitForHero(?DeckCard $hero, array $cardsData): int
    {
        if (!$hero) {
            return 0;
        }

        $heroData = $cardsData[$hero->getCardReference()] ?? [];
        $heroName = strtolower($this->getCardName($heroData));

        foreach (self::UNIQUE_LIMITS as $limit => $heroes) {
            foreach ($heroes as $name) {
                if (str_contains($heroName, $name)) {
                    return $limit;
                }
            }
        }

        // Default to 3 if hero not found in the list
        return 3;
    }
}
