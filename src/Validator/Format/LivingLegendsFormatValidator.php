<?php

namespace App\Validator\Format;

use App\Entity\Deck;

/**
 * Living Legends format rules:
 * Same as Standard, plus every Unique card in the deck must carry the
 * "living_legends" gameplay format key. That key is synced onto CardGroup by
 * altered-core-cards-api from the Altered Reunion formats manifest and
 * exposed on card data as `gameplayFormat` (string[]).
 */
class LivingLegendsFormatValidator extends StandardFormatValidator
{
    private const string GAMEPLAY_FORMAT_KEY = 'living_legends';

    public function getFormat(): string
    {
        return 'living_legends';
    }

    public function validate(Deck $deck, array $cardsData): array
    {
        return array_merge(parent::validate($deck, $cardsData), $this->validateLivingLegendsUniques($deck, $cardsData));
    }

    public function computeLegalityDetail(Deck $deck, array $cardsData): array
    {
        $detail = parent::computeLegalityDetail($deck, $cardsData);
        $detail['livingLegendsUniques'] = [] === $this->validateLivingLegendsUniques($deck, $cardsData);
        $detail['global'] = $detail['global'] && $detail['livingLegendsUniques'];

        return $detail;
    }

    /**
     * @return string[]
     */
    private function validateLivingLegendsUniques(Deck $deck, array $cardsData): array
    {
        $errors = [];
        foreach ($deck->getDeckCards() as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            if ('U' !== $this->getRarityCode($data)) {
                continue;
            }

            // altered-core-cards-api's admin UI uppercases gameplayFormat keys on save,
            // so compare case-insensitively rather than assuming the stored casing.
            $gameplayFormats = array_map('strtolower', $data['gameplayFormat'] ?? []);
            if (!in_array(self::GAMEPLAY_FORMAT_KEY, $gameplayFormats, true)) {
                $errors[] = sprintf('Unique card "%s" is not part of the Living Legends format allowlist.', $this->getCardName($data));
            }
        }

        return $errors;
    }
}
