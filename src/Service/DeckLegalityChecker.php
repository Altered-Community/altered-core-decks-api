<?php

namespace App\Service;

use App\Entity\Deck;
use App\Enum\DeckFormat;
use App\Validator\Format\DeckFormatValidatorFactory;

/**
 * Writes a deck's formatErrors, legalityDetail and legal from its card data, and records
 * which Frontier pool that result was computed against.
 */
final readonly class DeckLegalityChecker
{
    public function __construct(
        private DeckFormatValidatorFactory $validatorFactory,
    ) {
    }

    /**
     * @param array<string, array> $cardsData      reference => card data
     * @param string|null          $frontierPoolId current FrontierPool id; only stored on Frontier decks
     */
    public function check(Deck $deck, array $cardsData, ?string $frontierPoolId): void
    {
        $format = $deck->getFormat()?->value;

        if ($deck->getIsDraft() || null === $format || !$this->validatorFactory->supports($format)) {
            $deck->setFormatErrors(null)
                ->setLegalityDetail(null)
                ->setLegal(false)
                ->setFrontierPool(null);

            return;
        }

        $validator = $this->validatorFactory->getValidator($format);
        $errors = $validator->validate($deck, $cardsData);
        $detail = $validator->computeLegalityDetail($deck, $cardsData);

        $deck->setFormatErrors(empty($errors) ? null : $errors)
            ->setLegalityDetail($detail)
            ->setLegal($detail['global'])
            ->setFrontierPool(DeckFormat::Frontier === $deck->getFormat() ? $frontierPoolId : null);
    }
}
