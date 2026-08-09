<?php

namespace App\Tests\Serializer;

use App\Client\CardDataProviderFactory;
use App\Client\CardDataProviderInterface;
use App\Entity\Deck;
use App\Serializer\DeckNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Regression test for the 2026-08-08 production incident: the first-ever
 * `POST /api/decks` for the brand-new EOLE sealed tournament (altered-draft's
 * "Set 6 preview") 500'd with API Platform's generic problem+json envelope.
 *
 * Root cause: DeckStateProcessor::fetchCardsData() wraps its
 * getCardsByReferences() call in try/catch(\Throwable), so a card-data-provider
 * failure (e.g. altered-core's /api/cards/batch choking on the freshly-instanced
 * Unique ALT_EOLE_B_BR_110_U_2526) never blocks the write — the Deck persists
 * fine. But DeckNormalizer::normalize() independently re-fetches the SAME card
 * data while serializing the HTTP response, with NO try/catch — so the second,
 * identical call throws again, this time uncaught, and API Platform turns it
 * into a top-level 500. The client never sees the created deck's id.
 */
class DeckNormalizerTest extends TestCase
{
    private const UNIQUE_REF = 'ALT_EOLE_B_BR_110_U_2526';

    private function throwingProviderFactory(): CardDataProviderFactory
    {
        $provider = $this->createStub(CardDataProviderInterface::class);
        $provider->method('getCardsByReferences')
            ->willThrowException(new \RuntimeException('altered-core /api/cards/batch failed for EOLE Uniques'));

        $factory = $this->createStub(CardDataProviderFactory::class);
        $factory->method('getProvider')->willReturn($provider);

        return $factory;
    }

    private function requestStack(): RequestStack
    {
        $stack = $this->createStub(RequestStack::class);
        $stack->method('getCurrentRequest')->willReturn(null);

        return $stack;
    }

    /** The exact shape DeckStateProcessor's inner normalizer would hand back for the incident's payload. */
    private function innerNormalizedData(): array
    {
        return [
            'id' => 'a1b2c3d4-0000-0000-0000-000000000000',
            'name' => 'EOLE sealed · 8/8/2026',
            'format' => 'sealed',
            'isDraft' => true,
            'stats' => ['totalCards' => 29, 'hero' => null, 'byRarity' => ['C' => 0, 'R' => 0, 'U' => 0, 'E' => 0]],
            'formatErrors' => null,
            'deckCards' => [
                ['cardReference' => 'ALT_EOLE_B_AX_65_C', 'quantity' => 1],
                ['cardReference' => self::UNIQUE_REF, 'quantity' => 1],
            ],
        ];
    }

    private function normalizerWithInnerData(array $innerData, CardDataProviderFactory $factory): DeckNormalizer
    {
        $inner = $this->createStub(NormalizerInterface::class);
        $inner->method('normalize')->willReturn($innerData);

        $normalizer = new DeckNormalizer($factory, $this->requestStack());
        $normalizer->setNormalizer($inner);

        return $normalizer;
    }

    public function testCurrentlyThrowsWhenCardDataProviderFailsDuringResponseSerialization(): void
    {
        $normalizer = $this->normalizerWithInnerData($this->innerNormalizedData(), $this->throwingProviderFactory());
        $deck = $this->createStub(Deck::class);

        // This is the bug: normalize() has no try/catch around getCardsByReferences(),
        // unlike DeckStateProcessor::fetchCardsData(). Once fixed, this call must NOT throw.
        $this->expectException(\RuntimeException::class);
        $normalizer->normalize($deck, 'json', []);
    }
}
