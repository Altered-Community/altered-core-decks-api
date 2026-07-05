<?php

namespace App\Tests\Validator\Format;

use App\Client\UniquesSearchApiClient;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\FrontierFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class FrontierFormatValidatorTest extends TestCase
{
    private UniquesSearchApiClient $uniquesSearchApiClient;
    private FrontierFormatValidator $validator;

    protected function setUp(): void
    {
        $this->uniquesSearchApiClient = $this->createStub(UniquesSearchApiClient::class);
        $this->validator = new FrontierFormatValidator($this->uniquesSearchApiClient);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function card(string $ref, int $qty = 1): DeckCard
    {
        $card = new DeckCard();
        $card->setCardReference($ref);
        $card->setQuantity($qty);

        return $card;
    }

    private function deck(DeckCard ...$cards): Deck
    {
        $deck = $this->createStub(Deck::class);
        $deck->method('getDeckCards')->willReturn(new ArrayCollection($cards));

        return $deck;
    }

    private function data(
        string $ref,
        string $typeRef = 'PERMANENT',
        string $faction = 'AX',
        string $rarityRef = 'COMMON',
        string $name = '',
    ): array {
        return [
            'reference' => $ref,
            'cardType' => ['reference' => $typeRef],
            'faction' => ['code' => $faction],
            'rarity' => ['reference' => $rarityRef],
            'name' => $name ?: $ref,
        ];
    }

    /**
     * Builds 1 hero + 13 distinct commons × qty 3 = 39 non-hero cards.
     *
     * @return array{0: array<string, array>, 1: DeckCard[]}
     */
    private function buildMinimalValidDeck(string $faction = 'AX'): array
    {
        $cardsData = [];
        $deckCards = [];

        $heroRef = 'ALT_CORE_B_AX_0_C';
        $deckCards[] = $this->card($heroRef, 1);
        $cardsData[$heroRef] = $this->data($heroRef, 'HERO_MAIN', $faction);

        for ($i = 1; $i <= 13; ++$i) {
            $ref = sprintf('ALT_CORE_B_%s_%d_C', $faction, $i);
            $deckCards[] = $this->card($ref, 3);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', $faction);
        }

        return [$cardsData, $deckCards];
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testDeckWithNoUniqueDoesNotCallSearchApi(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $client = $this->createMock(UniquesSearchApiClient::class);
        $client->expects(self::never())->method('findLegalReferences');
        $validator = new FrontierFormatValidator($client);

        $errors = $validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testAllUniquesAllowedByFrontierIsValid(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $uniqueRefs = [];
        for ($i = 1; $i <= 3; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $uniqueRefs[] = $ref;
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i");
        }

        $client = $this->createMock(UniquesSearchApiClient::class);
        $client->expects(self::once())
            ->method('findLegalReferences')
            ->with($uniqueRefs, 'frontier')
            ->willReturn($uniqueRefs);
        $validator = new FrontierFormatValidator($client);

        $errors = $validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── Frontier allowlist ────────────────────────────────────────────────────

    public function testUniqueNotInFrontierAllowlistReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $allowedRef = 'ALT_CORE_B_AX_1_U';
        $rejectedRef = 'ALT_CORE_B_AX_2_U';
        $deckCards[] = $this->card($allowedRef, 1);
        $deckCards[] = $this->card($rejectedRef, 1);
        $cardsData[$allowedRef] = $this->data($allowedRef, 'PERMANENT', 'AX', 'UNIQUE', 'Allowed Unique');
        $cardsData[$rejectedRef] = $this->data($rejectedRef, 'PERMANENT', 'AX', 'UNIQUE', 'Rejected Unique');

        $this->uniquesSearchApiClient
            ->method('findLegalReferences')
            ->willReturn([$allowedRef]);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Rejected Unique') && str_contains($e, 'Frontier')));
    }

    public function testUniqueNotInFrontierAllowlistMarksLegalityDetailFalse(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $rejectedRef = 'ALT_CORE_B_AX_1_U';
        $deckCards[] = $this->card($rejectedRef, 1);
        $cardsData[$rejectedRef] = $this->data($rejectedRef, 'PERMANENT', 'AX', 'UNIQUE', 'Rejected Unique');

        $this->uniquesSearchApiClient
            ->method('findLegalReferences')
            ->willReturn([]);

        $detail = $this->validator->computeLegalityDetail($this->deck(...$deckCards), $cardsData);

        self::assertFalse($detail['frontierUniques']);
        self::assertFalse($detail['global']);
    }

    // ── Fail-closed on service failure ───────────────────────────────────────

    public function testSearchApiFailureMakesDeckInvalid(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_U';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', 'My Unique');

        $this->uniquesSearchApiClient
            ->method('findLegalReferences')
            ->willThrowException(new \RuntimeException('service unavailable'));

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);
        $detail = $this->validator->computeLegalityDetail($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'unavailable')));
        self::assertFalse($detail['frontierUniques']);
        self::assertFalse($detail['global']);
    }

    // ── Inherited Standard rules still apply ─────────────────────────────────

    public function testInheritsStandardMaxUniqueLimit(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $uniqueRefs = [];
        for ($i = 1; $i <= 4; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $uniqueRefs[] = $ref;
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i");
        }

        $this->uniquesSearchApiClient
            ->method('findLegalReferences')
            ->willReturn($uniqueRefs);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }
}
