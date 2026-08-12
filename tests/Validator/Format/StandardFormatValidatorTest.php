<?php

namespace App\Tests\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\StandardFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class StandardFormatValidatorTest extends TestCase
{
    private StandardFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new StandardFormatValidator();
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
     * Returns [$cardsData, $deckCards].
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

    public function testValidDeckHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    // ── Hero ──────────────────────────────────────────────────────────────────

    public function testMissingHeroReturnsError(): void
    {
        $ref = 'ALT_CORE_B_AX_1_C';
        $deck = $this->deck($this->card($ref, 3));

        $errors = $this->validator->validate($deck, [$ref => $this->data($ref)]);

        self::assertContains('Deck must contain exactly 1 hero card.', $errors);
    }

    public function testHeroQuantityAboveOneReturnsError(): void
    {
        $ref = 'ALT_CORE_B_AX_0_C';
        $deck = $this->deck($this->card($ref, 2));

        $errors = $this->validator->validate($deck, [$ref => $this->data($ref, 'HERO_MAIN')]);

        self::assertContains('Deck must contain exactly 1 hero card.', $errors);
    }

    // ── Deck size ─────────────────────────────────────────────────────────────

    public function testDeckTooSmallReturnsError(): void
    {
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $ref = 'ALT_CORE_B_AX_1_C';
        $deck = $this->deck($this->card($heroRef, 1), $this->card($ref, 1));
        $cardsData = [
            $heroRef => $this->data($heroRef, 'HERO_MAIN'),
            $ref => $this->data($ref),
        ];

        $errors = $this->validator->validate($deck, $cardsData);

        self::assertContains('Deck must contain between 39 and 59 cards (hero excluded), got 1.', $errors);
    }

    public function testDeckTooLargeReturnsError(): void
    {
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $cardsData = [$heroRef => $this->data($heroRef, 'HERO_MAIN')];
        $deckCards = [$this->card($heroRef, 1)];

        // 20 distinct cards × qty 3 = 60 non-hero cards (max is 59)
        for ($i = 1; $i <= 20; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_C', $i);
            $deckCards[] = $this->card($ref, 3);
            $cardsData[$ref] = $this->data($ref);
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertContains('Deck must contain between 39 and 59 cards (hero excluded), got 60.', $errors);
    }

    // ── Faction ───────────────────────────────────────────────────────────────

    public function testMultipleFactionsReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck('AX');

        // Replace last card with a card from a different faction
        array_pop($deckCards);
        $brRef = 'ALT_CORE_B_BR_99_C';
        $deckCards[] = $this->card($brRef, 3);
        $cardsData[$brRef] = $this->data($brRef, 'PERMANENT', 'BR');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'multiple factions')));
    }

    public function testHeroFactionDifferentFromDeckReturnsError(): void
    {
        // Body is uniformly AX, but hero is BR.
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck('AX');
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $cardsData[$heroRef] = $this->data($heroRef, 'HERO_MAIN', 'BR');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'multiple factions')));
    }

    // ── Sets ──────────────────────────────────────────────────────────────────

    public function testForbiddenSetFugueReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_FUGUE_B_AX_1_C';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'COMMON', 'Fugue Card');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'FUGUE') && str_contains($e, 'forbidden')));
    }

    public function testEoleSetIsAllowed(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_EOLE_B_AX_1_C';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'COMMON', 'Eole Card');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertEmpty(array_filter($errors, fn ($e) => str_contains($e, 'EOLE')));
    }

    public function testUnknownSetReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_NEWSET_B_AX_1_C';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'NEWSET') && str_contains($e, 'not allowed')));
    }

    // ── Banned / suspended ────────────────────────────────────────────────────

    public function testBannedCardReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_C';
        $cardsData[$ref] = array_merge($cardsData[$ref], ['isBanned' => true]);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'banned')));
    }

    public function testSuspendedCardReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_C';
        $cardsData[$ref] = array_merge($cardsData[$ref], ['isSuspended' => true]);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'suspended')));
    }

    // ── Standard-specific: copies per name ───────────────────────────────────

    public function testMoreThan3CopiesOfSameNameReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        // Add a R1 variant of card 1 with qty 2 → same name = 3(C) + 2(R1) = 5 copies
        $refR1 = 'ALT_CORE_B_AX_1_R1';
        $deckCards[] = $this->card($refR1, 2);
        $cardsData[$refR1] = $this->data($refR1, 'PERMANENT', 'AX', 'RARE', 'ALT_CORE_B_AX_1_C');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'exceeds 3 copies')));
    }

    // ── Standard-specific: unique limit ──────────────────────────────────────

    public function testMoreThan3UniquesReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        for ($i = 1; $i <= 4; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }

    public function testExactly3UniquesIsValid(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        for ($i = 1; $i <= 3; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', "Unique $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }

    // ── Standard-specific: unique quantity per reference ─────────────────────

    public function testUniqueCardWithQuantity2ReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $ref = 'ALT_CORE_B_AX_1_U_001';
        $deckCards[] = $this->card($ref, 2);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', 'My Unique');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, '1 copy')));
    }

    public function testTwoDifferentUniqueRefsWithSameNameIsValid(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        // Two alteration variants of the same Unique — different refs, same name, qty=1 each
        $ref1 = 'ALT_CORE_B_AX_1_U_001';
        $ref2 = 'ALT_CORE_B_AX_1_U_185';
        $deckCards[] = $this->card($ref1, 1);
        $deckCards[] = $this->card($ref2, 1);
        $cardsData[$ref1] = $this->data($ref1, 'PERMANENT', 'AX', 'UNIQUE', 'My Unique');
        $cardsData[$ref2] = $this->data($ref2, 'PERMANENT', 'AX', 'UNIQUE', 'My Unique');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertEmpty(array_filter($errors, fn ($e) => str_contains($e, '1 copy')));
    }

    // ── Standard-specific: rare limit ────────────────────────────────────────

    public function testMoreThan15RaresReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        // 6 distinct rare cards × qty 3 = 18 rares
        for ($i = 1; $i <= 6; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_R1', $i);
            $deckCards[] = $this->card($ref, 3);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'RARE', "Rare $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'rare cards')));
    }

    // ── Standard-specific: exalted limit ─────────────────────────────────────

    public function testMoreThan3ExaltedReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        // 4 distinct exalted cards (EXALTED rarity)
        for ($i = 1; $i <= 4; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_E', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'EXALTED', "Exalted $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'exalted cards')));
    }

    // ── validateSets (no cardsData needed) ───────────────────────────────────

    public function testValidateSetsOnlyChecksSetMembership(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        // No cardsData passed — references alone are enough
        $errors = $this->validator->validateSets($this->deck(...$deckCards));

        self::assertSame([], $errors);
    }

    public function testValidateSetsDetectsFugue(): void
    {
        $deckCards = [$this->card('ALT_FUGUE_B_AX_1_C', 1)];

        $errors = $this->validator->validateSets($this->deck(...$deckCards));

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'FUGUE')));
    }
}
