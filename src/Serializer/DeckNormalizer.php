<?php

namespace App\Serializer;

use App\Client\AlteredCoreClient;
use App\Entity\Deck;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class DeckNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'DECK_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private readonly AlteredCoreClient $alteredCoreClient,
        private readonly RequestStack      $requestStack,
    ) {}

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Deck
            && !($context[self::ALREADY_CALLED] ?? false);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Deck::class => false];
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        /** @var Deck $object */
        $data = $this->normalizer->normalize($object, $format, $context);

        // Only enrich on detail view (deckCards present)
        if (empty($data['deckCards'])) {
            return $data;
        }

        $locale     = $this->requestStack->getCurrentRequest()?->query->get('locale', 'fr') ?? 'fr';
        $references = array_column($data['deckCards'], 'cardReference');
        $cardsData  = $this->alteredCoreClient->getCardsByReferences($references, $locale);

        foreach ($data['deckCards'] as &$deckCard) {
            $ref = $deckCard['cardReference'] ?? null;
            if ($ref && isset($cardsData[$ref])) {
                $deckCard['card'] = $cardsData[$ref];
            }
        }
        unset($deckCard);

        return $data;
    }
}
