<?php

namespace App\Tests\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\LivingLegendsFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class LivingLegendsFormatValidatorTest extends TestCase
{
    private LivingLegendsFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LivingLegendsFormatValidator();
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
        array $gameplayFormat = [],
    ): array {
        return [
            'reference' => $ref,
            'cardType' => ['reference' => $typeRef],
            'faction' => ['code' => $faction],
            'rarity' => ['reference' => $rarityRef],
            'name' => $name ?: $ref,
            'gameplayFormat' => $gameplayFormat,
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

    public function testDeckWithNoUniqueIsValid(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testAllUniquesFlaggedLivingLegendsIsValid(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        for ($i = 1; $i <= 3; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i", ['living_legends']);
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testUppercaseGameplayFormatKeyIsValid(): void
    {
        // altered-core-cards-api's admin UI stores gameplayFormat keys uppercased.
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_U';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', 'Unique 1', ['LIVING_LEGENDS']);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── Living Legends allowlist ─────────────────────────────────────────────

    public function testUniqueNotFlaggedLivingLegendsReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $allowedRef = 'ALT_CORE_B_AX_1_U';
        $rejectedRef = 'ALT_CORE_B_AX_2_U';
        $deckCards[] = $this->card($allowedRef, 1);
        $deckCards[] = $this->card($rejectedRef, 1);
        $cardsData[$allowedRef] = $this->data($allowedRef, 'PERMANENT', 'AX', 'UNIQUE', 'Allowed Unique', ['living_legends']);
        $cardsData[$rejectedRef] = $this->data($rejectedRef, 'PERMANENT', 'AX', 'UNIQUE', 'Rejected Unique', []);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Rejected Unique') && str_contains($e, 'Living Legends')));
    }

    public function testUniqueNotFlaggedLivingLegendsMarksLegalityDetailFalse(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $rejectedRef = 'ALT_CORE_B_AX_1_U';
        $deckCards[] = $this->card($rejectedRef, 1);
        $cardsData[$rejectedRef] = $this->data($rejectedRef, 'PERMANENT', 'AX', 'UNIQUE', 'Rejected Unique', []);

        $detail = $this->validator->computeLegalityDetail($this->deck(...$deckCards), $cardsData);

        self::assertFalse($detail['livingLegendsUniques']);
        self::assertFalse($detail['global']);
    }

    // ── Inherited Standard rules still apply ─────────────────────────────────

    public function testInheritsStandardMaxUniqueLimit(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        for ($i = 1; $i <= 4; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i", ['living_legends']);
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }
}
