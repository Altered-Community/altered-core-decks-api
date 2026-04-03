<?php

namespace App\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;

abstract class AbstractDeckFormatValidator implements DeckFormatValidatorInterface
{
    public function validate(Deck $deck, array $cardsData): array
    {
        $errors = [];

        [$hero, $deckCards] = $this->splitHeroAndCards($deck, $cardsData);

        $errors = array_merge($errors, $this->validateHero($hero));
        $errors = array_merge($errors, $this->validateDeckSize($deckCards));
        $errors = array_merge($errors, $this->validateFaction($deckCards, $cardsData));
        $errors = array_merge($errors, $this->validateNoSuspendedOrBanned($deck, $cardsData));
        $errors = array_merge($errors, $this->validateFormatRules($deckCards, $cardsData, $hero));

        return $errors;
    }

    /**
     * Format-specific rules (rarity limits, unique limits, etc.)
     */
    abstract protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array;

    abstract protected function getMinCards(): int;
    abstract protected function getMaxCards(): int;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Splits deck cards into [hero, non-hero cards].
     *
     * @return array{0: DeckCard|null, 1: DeckCard[]}
     */
    protected function splitHeroAndCards(Deck $deck, array $cardsData): array
    {
        $hero      = null;
        $deckCards = [];

        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if ($this->isHero($data)) {
                $hero = $deckCard;
            } else {
                $deckCards[] = $deckCard;
            }
        }

        return [$hero, $deckCards];
    }

    protected function isHero(array $cardData): bool
    {
        $typeRef = $cardData['cardGroup']['cardType']['reference'] ?? '';
        return stripos($typeRef, 'HERO') !== false;
    }

    protected function getRarityCode(array $cardData): string
    {
        $ref = $cardData['cardRarity']['reference'] ?? '';
        if (str_contains($ref, '_U'))  return 'U';
        if (str_contains($ref, '_R2')) return 'R2';
        if (str_contains($ref, '_R1')) return 'R1';
        return 'C';
    }

    protected function getCardName(array $cardData): string
    {
        return $cardData['cardGroup']['name'] ?? $cardData['reference'] ?? '';
    }

    /**
     * Groups DeckCards by card name, returns ['name' => ['rarity' => totalQty, ...], ...]
     *
     * @param DeckCard[] $deckCards
     * @return array<string, array<string, int>>
     */
    protected function groupByName(array $deckCards, array $cardsData): array
    {
        $groups = [];
        foreach ($deckCards as $deckCard) {
            $data    = $cardsData[$deckCard->getCardReference()] ?? [];
            $name    = $this->getCardName($data);
            $rarity  = $this->getRarityCode($data);
            $groups[$name][$rarity] = ($groups[$name][$rarity] ?? 0) + $deckCard->getQuantity();
        }
        return $groups;
    }

    // ── Common validations ────────────────────────────────────────────────────

    protected function validateHero(?DeckCard $hero): array
    {
        if ($hero === null) {
            return ['Deck must contain exactly 1 hero card.'];
        }
        if ($hero->getQuantity() !== 1) {
            return ['Deck must contain exactly 1 hero card.'];
        }
        return [];
    }

    /** @param DeckCard[] $deckCards */
    protected function validateDeckSize(array $deckCards): array
    {
        $total = array_sum(array_map(fn (DeckCard $dc) => $dc->getQuantity(), $deckCards));
        $min   = $this->getMinCards();
        $max   = $this->getMaxCards();

        if ($total < $min || $total > $max) {
            return [sprintf('Deck must contain between %d and %d cards (hero excluded), got %d.', $min, $max, $total)];
        }
        return [];
    }

    /** @param DeckCard[] $deckCards */
    protected function validateFaction(array $deckCards, array $cardsData): array
    {
        $factions = [];
        foreach ($deckCards as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            $code = $data['cardGroup']['faction']['code'] ?? null;
            if ($code && $code !== 'NE') {
                $factions[$code] = true;
            }
        }

        if (count($factions) > 1) {
            return [sprintf('Deck contains cards from multiple factions: %s.', implode(', ', array_keys($factions)))];
        }
        return [];
    }

    protected function validateNoSuspendedOrBanned(Deck $deck, array $cardsData): array
    {
        $errors = [];
        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            $name = $this->getCardName($data);
            if (!empty($data['cardGroup']['isBanned'])) {
                $errors[] = sprintf('Card "%s" is banned.', $name);
            }
            if (!empty($data['cardGroup']['isSuspended'])) {
                $errors[] = sprintf('Card "%s" is suspended.', $name);
            }
        }
        return $errors;
    }

    /**
     * Validates max N copies of cards sharing the same name (all rarities combined).
     *
     * @param array<string, array<string, int>> $groups
     */
    protected function validateMaxCopiesPerName(array $groups, int $max): array
    {
        $errors = [];
        foreach ($groups as $name => $rarities) {
            $total = array_sum($rarities);
            if ($total > $max) {
                $errors[] = sprintf('Card "%s" exceeds %d copies (got %d across all rarities).', $name, $max, $total);
            }
        }
        return $errors;
    }

    /**
     * @param array<string, array<string, int>> $groups
     */
    protected function countUniqueCards(array $groups): int
    {
        $total = 0;
        foreach ($groups as $rarities) {
            $total += $rarities['U'] ?? 0;
        }
        return $total;
    }

    /**
     * @param array<string, array<string, int>> $groups
     */
    protected function countByRarity(array $groups, string $rarity): int
    {
        return array_sum(array_map(fn ($r) => $r[$rarity] ?? 0, $groups));
    }
}
