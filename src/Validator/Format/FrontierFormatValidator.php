<?php

namespace App\Validator\Format;

use App\Client\UniquesSearchApiClient;
use App\Entity\Deck;

/**
 * Frontier format rules:
 * Same as Standard, plus every Unique card in the deck must be part of the
 * Frontier allowlist, verified against the uniques search API.
 * If that service is unreachable, the deck is considered invalid (fail-closed).
 */
class FrontierFormatValidator extends StandardFormatValidator
{
    public function __construct(
        private readonly UniquesSearchApiClient $uniquesSearchApiClient,
    ) {
    }

    public function getFormat(): string
    {
        return 'frontier';
    }

    public function validate(Deck $deck, array $cardsData): array
    {
        return array_merge(parent::validate($deck, $cardsData), $this->validateFrontierUniques($deck, $cardsData));
    }

    public function computeLegalityDetail(Deck $deck, array $cardsData): array
    {
        $detail = parent::computeLegalityDetail($deck, $cardsData);
        $detail['frontierUniques'] = [] === $this->validateFrontierUniques($deck, $cardsData);
        $detail['global'] = $detail['global'] && $detail['frontierUniques'];

        return $detail;
    }

    /**
     * @return string[]
     */
    private function validateFrontierUniques(Deck $deck, array $cardsData): array
    {
        $uniqueRefs = [];
        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if ('U' === $this->getRarityCode($data)) {
                $uniqueRefs[] = $deckCard->getCardReference();
            }
        }

        if (empty($uniqueRefs)) {
            return [];
        }

        try {
            $legalRefs = $this->uniquesSearchApiClient->findLegalReferences($uniqueRefs, 'frontier');
        } catch (\Throwable) {
            return ['Unable to verify Frontier format legality: uniques search service is unavailable.'];
        }

        $illegalRefs = array_diff($uniqueRefs, $legalRefs);
        if (empty($illegalRefs)) {
            return [];
        }

        $errors = [];
        foreach ($illegalRefs as $ref) {
            $name = $this->getCardName($cardsData[$ref] ?? []) ?: $ref;
            $errors[] = sprintf('Unique card "%s" is not part of the Frontier format allowlist.', $name);
        }

        return $errors;
    }
}
