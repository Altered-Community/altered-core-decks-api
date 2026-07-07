<?php

namespace App\Validator\Format;

use App\Entity\Deck;

/**
 * Frontier format rules:
 * Same as Standard, plus every Unique card in the deck must carry the
 * "frontier" gameplay format key. That key is synced onto CardGroup by
 * altered-core-cards-api from the Altered Reunion formats manifest and
 * exposed on card data as `gameplayFormat` (string[]).
 */
class FrontierFormatValidator extends StandardFormatValidator
{
    private const string GAMEPLAY_FORMAT_KEY = 'frontier';

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
        $errors = [];
        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if ('U' !== $this->getRarityCode($data)) {
                continue;
            }

            $gameplayFormats = $data['gameplayFormat'] ?? [];
            if (!in_array(self::GAMEPLAY_FORMAT_KEY, $gameplayFormats, true)) {
                $errors[] = sprintf('Unique card "%s" is not part of the Frontier format allowlist.', $this->getCardName($data));
            }
        }

        return $errors;
    }
}
