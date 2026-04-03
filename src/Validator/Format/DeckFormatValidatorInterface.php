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
}
