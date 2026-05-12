<?php

namespace App\Validator\Format;

use App\Entity\Deck;

interface DeckFormatValidatorInterface
{
    /**
     * The format key this validator handles (e.g. "standard", "nuc").
     */
    public function getFormat(): string;

    /**
     * Validate the deck against format rules.
     *
     * @param  array<string, array> $cardsData  reference => card data from altered-core
     * @return string[]  list of validation error messages (empty = valid)
     */
    public function validate(Deck $deck, array $cardsData): array;

    /**
     * Check only whether the deck's card sets are allowed/not forbidden for this format.
     * Does not require HTTP-fetched card data — uses card references only.
     *
     * @return string[]
     */
    public function validateSets(Deck $deck): array;

    /**
     * Returns a per-rule legality breakdown for this deck.
     * Keys are rule identifiers, values are true (legal) / false (not legal).
     * Always includes a 'global' key with the overall result.
     *
     * @param  array<string, array> $cardsData
     * @return array<string, bool>
     */
    public function computeLegalityDetail(Deck $deck, array $cardsData): array;
}
