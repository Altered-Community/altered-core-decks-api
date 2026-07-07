<?php

namespace App\Client;

interface CardDataProviderInterface
{
    /**
     * The provider key used to select this implementation via CARD_DATA_PROVIDER (e.g. "altered_core").
     */
    public function getName(): string;

    /**
     * Fetch card data for a list of references.
     *
     * @param string[] $references
     *
     * @return array<string, array> reference => card data
     */
    public function getCardsByReferences(array $references, string $locale = 'fr'): array;

    /**
     * Fetch card data for a single reference.
     *
     * @return array<string, mixed>
     */
    public function getCardByReferences(string $reference, string $locale = 'en'): array;
}
