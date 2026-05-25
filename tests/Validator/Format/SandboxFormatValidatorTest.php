<?php

namespace App\Tests\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\SandboxFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class SandboxFormatValidatorTest extends TestCase
{
    private SandboxFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SandboxFormatValidator();
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
     * Builds 1 hero + 4 distinct commons = minimal valid sandbox deck.
     *
     * @return array{0: array<string, array>, 1: DeckCard[]}
     */
    private function buildMinimalValidDeck(string $faction = 'AX'): array
    {
        $cardsData = [];
        $deckCards = [];

        $heroRef = 'ALT_CORE_B_AX_0_C';
        $deckCards[] = $this->card($heroRef);
        $cardsData[$heroRef] = $this->data($heroRef, 'HERO_MAIN', $faction);

        for ($i = 1; $i <= 4; ++$i) {
            $ref = sprintf('ALT_CORE_B_%s_%d_C', $faction, $i);
            $deckCards[] = $this->card($ref);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', $faction);
        }

        return [$cardsData, $deckCards];
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testValidDeckHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── Banned / suspended allowed ────────────────────────────────────────────

    public function testBannedCardIsAllowed(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_C';
        $cardsData[$ref] = array_merge($cardsData[$ref], ['isBanned' => true]);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertEmpty(array_filter($errors, fn ($e) => str_contains($e, 'banned')));
    }

    public function testSuspendedCardIsAllowed(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_C';
        $cardsData[$ref] = array_merge($cardsData[$ref], ['isSuspended' => true]);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertEmpty(array_filter($errors, fn ($e) => str_contains($e, 'suspended')));
    }

    public function testLegalityDetailReportsBannedAndSuspendedAsLegal(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_C';
        $cardsData[$ref] = array_merge($cardsData[$ref], ['isBanned' => true, 'isSuspended' => true]);

        $detail = $this->validator->computeLegalityDetail($this->deck(...$deckCards), $cardsData);

        self::assertTrue($detail['bannedCards']);
        self::assertTrue($detail['suspendedCards']);
    }

    // ── No faction restriction ────────────────────────────────────────────────

    public function testMultipleFactionsDeckHasNoErrors(): void
    {
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $cardsData = [
            $heroRef => $this->data($heroRef, 'HERO_MAIN', 'AX'),
        ];
        $deckCards = [$this->card($heroRef)];

        foreach (['AX', 'LY', 'MU', 'OR', 'YZ'] as $i => $faction) {
            $ref = sprintf('ALT_CORE_B_%s_%d_C', $faction, $i + 1);
            $deckCards[] = $this->card($ref);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', $faction);
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── No quantity limit ─────────────────────────────────────────────────────

    public function testQuantityAboveThreeHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_5_C';
        $deckCards[] = $this->card($ref, 5);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── No unique limit ───────────────────────────────────────────────────────

    public function testMoreThan3UniquesHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        for ($i = 1; $i <= 5; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[] = $this->card($ref);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── No copies-per-name limit ──────────────────────────────────────────────

    public function testMoreThan3CopiesOfSameNameHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        // Same name "My Card" across C + R1 = 5 copies total
        $refC = 'ALT_CORE_B_AX_5_C';
        $refR1 = 'ALT_CORE_B_AX_5_R1';
        $deckCards[] = $this->card($refC, 3);
        $deckCards[] = $this->card($refR1, 2);
        $cardsData[$refC] = $this->data($refC, 'PERMANENT', 'AX', 'COMMON', 'My Card');
        $cardsData[$refR1] = $this->data($refR1, 'PERMANENT', 'AX', 'RARE', 'My Card');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testLegalityDetailFactionAlwaysTrue(): void
    {
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $cardsData = [
            $heroRef => $this->data($heroRef, 'HERO_MAIN', 'AX'),
        ];
        $deckCards = [$this->card($heroRef)];

        foreach (['AX', 'LY', 'MU', 'OR'] as $i => $faction) {
            $ref = sprintf('ALT_CORE_B_%s_%d_C', $faction, $i + 1);
            $deckCards[] = $this->card($ref);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', $faction);
        }

        $detail = $this->validator->computeLegalityDetail($this->deck(...$deckCards), $cardsData);

        self::assertTrue($detail['faction']);
    }
}
