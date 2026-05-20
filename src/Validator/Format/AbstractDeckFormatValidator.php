<?php

namespace App\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;

abstract class AbstractDeckFormatValidator implements DeckFormatValidatorInterface
{
    public const array VALID_SETS = [
        'CORE',
        'COREKS',
        'BISE',
        'ALIZE',
        'CYCLONE',
        'DUSTER',
        'DUSTEROP',
        'DUSTERTOP',
        'DUSTERCB',
        'TCS3',
        'WCQ25',
        'WCS26',
        'WCS25',
        'JUDGE',
        'WCF25',
        'MUSUBI',
    ];

    public const FORBIDDEN_SETS = ['FUGUE', 'EOLE'];

    public function validate(Deck $deck, array $cardsData): array
    {
        $errors = [];

        [$hero, $deckCards] = $this->splitHeroAndCards($deck, $cardsData);

        $errors = array_merge($errors, $this->validateAllowedSets($deck, $cardsData));
        $errors = array_merge($errors, $this->validateHero($hero));
        $errors = array_merge($errors, $this->validateDeckSize($deckCards));
        $errors = array_merge($errors, $this->validateFaction($deckCards, $cardsData));
        $errors = array_merge($errors, $this->validateNoSuspendedOrBanned($deck, $cardsData));
        $errors = array_merge($errors, $this->validateFormatRules($deckCards, $cardsData, $hero));

        return $errors;
    }

    /**
     * Format-specific rules (rarity limits, unique limits, etc.).
     */
    abstract protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array;

    abstract protected function getMinCards(): int;

    abstract protected function getMaxCards(): int;

    /**
     * Per-rule legality breakdown for this format.
     * Keys are rule identifiers, values are true (legal) / false (not legal).
     *
     * @return array<string, bool>
     */
    abstract protected function computeFormatRulesDetail(array $deckCards, array $cardsData, ?DeckCard $hero): array;

    public function validateSets(Deck $deck): array
    {
        return $this->validateAllowedSets($deck, []);
    }

    /**
     * Returns a per-rule legality breakdown for this deck.
     *
     * @param array<string, array> $cardsData
     *
     * @return array<string, bool>
     */
    public function computeLegalityDetail(Deck $deck, array $cardsData): array
    {
        [$hero, $deckCards] = $this->splitHeroAndCards($deck, $cardsData);

        $detail = [
            'hero' => [] === $this->validateHero($hero),
            'deckSize' => [] === $this->validateDeckSize($deckCards),
            'faction' => [] === $this->validateFaction($deckCards, $cardsData),
            'sets' => [] === $this->validateAllowedSets($deck, $cardsData),
            'bannedCards' => $this->allowBannedCards() || [] === $this->validateNoBanned($deck, $cardsData),
            'suspendedCards' => $this->allowSuspendedCards() || [] === $this->validateNoSuspended($deck, $cardsData),
        ];

        $detail = array_merge($detail, $this->computeFormatRulesDetail($deckCards, $cardsData, $hero));

        $detail['global'] = !in_array(false, $detail, true);

        return $detail;
    }

    /** Sets explicitly allowed in this format. Defaults to all globally valid sets. */
    protected function getAllowedSets(): array
    {
        return self::VALID_SETS;
    }

    /** Sets explicitly forbidden in this format. Defaults to globally forbidden sets. */
    protected function getForbiddenSets(): array
    {
        return self::FORBIDDEN_SETS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Splits deck cards into [hero, non-hero cards].
     *
     * @return array{0: DeckCard|null, 1: DeckCard[]}
     */
    protected function splitHeroAndCards(Deck $deck, array $cardsData): array
    {
        $hero = null;
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
        $typeRef = $cardData['cardType']['reference'] ?? '';

        return false !== stripos($typeRef, 'HERO');
    }

    protected function getRarityCode(array $cardData): string
    {
        $ref = $cardData['cardRarity']['reference'] ?? '';
        if (str_contains($ref, '_U')) {
            return 'U';
        }
        if (str_contains($ref, '_R2')) {
            return 'R2';
        }
        if (str_contains($ref, '_R1')) {
            return 'R1';
        }

        return 'C';
    }

    protected function getCardName(array $cardData): string
    {
        return $cardData['name'] ?? $cardData['reference'] ?? '';
    }

    /**
     * Groups DeckCards by card name, returns ['name' => ['rarity' => totalQty, ...], ...].
     *
     * @param DeckCard[] $deckCards
     *
     * @return array<string, array<string, int>>
     */
    protected function groupByName(array $deckCards, array $cardsData): array
    {
        $groups = [];
        foreach ($deckCards as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            $name = $this->getCardName($data);
            $rarity = $this->getRarityCode($data);
            $groups[$name][$rarity] = ($groups[$name][$rarity] ?? 0) + $deckCard->getQuantity();
        }

        return $groups;
    }

    // ── Common validations ────────────────────────────────────────────────────

    protected function validateHero(?DeckCard $hero): array
    {
        if (null === $hero) {
            return ['Deck must contain exactly 1 hero card.'];
        }
        if (1 !== $hero->getQuantity()) {
            return ['Deck must contain exactly 1 hero card.'];
        }

        return [];
    }

    /** @param DeckCard[] $deckCards */
    protected function validateDeckSize(array $deckCards): array
    {
        $total = array_sum(array_map(fn (DeckCard $dc) => $dc->getQuantity(), $deckCards));
        $min = $this->getMinCards();
        $max = $this->getMaxCards();

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
            $code = $data['faction']['code'] ?? null;
            if ($code && 'NE' !== $code) {
                $factions[$code] = true;
            }
        }

        if (count($factions) > 1) {
            return [sprintf('Deck contains cards from multiple factions: %s.', implode(', ', array_keys($factions)))];
        }

        return [];
    }

    protected function validateAllowedSets(Deck $deck, array $cardsData): array
    {
        $errors = [];
        $allowedSets = $this->getAllowedSets();
        $forbiddenSets = $this->getForbiddenSets();

        foreach ($deck->getDeckCards() as $deckCard) {
            $ref = $deckCard->getCardReference();
            $set = explode('_', $ref)[1] ?? '';
            $name = $this->getCardName($cardsData[$ref] ?? []) ?: $ref;

            if (in_array($set, $forbiddenSets, true)) {
                $errors[] = sprintf('Card "%s" belongs to set "%s" which is forbidden.', $name, $set);
            } elseif (!in_array($set, $allowedSets, true)) {
                $errors[] = sprintf('Card "%s" belongs to set "%s" which is not allowed in this format.', $name, $set);
            }
        }

        return $errors;
    }

    protected function allowBannedCards(): bool
    {
        return false;
    }

    protected function allowSuspendedCards(): bool
    {
        return false;
    }

    protected function validateNoSuspendedOrBanned(Deck $deck, array $cardsData): array
    {
        return array_merge(
            $this->allowBannedCards() ? [] : $this->validateNoBanned($deck, $cardsData),
            $this->allowSuspendedCards() ? [] : $this->validateNoSuspended($deck, $cardsData),
        );
    }

    protected function validateNoBanned(Deck $deck, array $cardsData): array
    {
        $errors = [];
        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if (!empty($data['isBanned'])) {
                $errors[] = sprintf('Card "%s" is banned.', $this->getCardName($data));
            }
        }

        return $errors;
    }

    protected function validateNoSuspended(Deck $deck, array $cardsData): array
    {
        $errors = [];
        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if (!empty($data['isSuspended'])) {
                $errors[] = sprintf('Card "%s" is suspended.', $this->getCardName($data));
            }
        }

        return $errors;
    }

    /**
     * Validates that each individual Unique card (rarity U) has quantity = 1.
     * Multiple different Unique references sharing the same name are allowed (alteration variants).
     *
     * @param DeckCard[] $deckCards
     */
    protected function validateUniqueQuantity(array $deckCards, array $cardsData): array
    {
        $errors = [];
        foreach ($deckCards as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if ('U' === $this->getRarityCode($data) && $deckCard->getQuantity() > 1) {
                $errors[] = sprintf(
                    'Unique card "%s" can only have 1 copy (got %d).',
                    $this->getCardName($data),
                    $deckCard->getQuantity(),
                );
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
