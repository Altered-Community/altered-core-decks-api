<?php

namespace App\Tests\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\NucFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class NucFormatValidatorTest extends TestCase
{
    private NucFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new NucFormatValidator();
    }

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

    private function data(string $ref, string $typeRef = 'PERMANENT', string $faction = 'AX', string $rarityRef = 'COMMON', string $name = ''): array
    {
        return [
            'reference' => $ref,
            'cardType' => ['reference' => $typeRef],
            'faction' => ['code' => $faction],
            'rarity' => ['reference' => $rarityRef],
            'name' => $name ?: $ref,
        ];
    }

    /** @return array{0: array<string, array>, 1: DeckCard[]} */
    private function buildMinimalValidNucDeck(): array
    {
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $cardsData = [$heroRef => $this->data($heroRef, 'HERO_MAIN')];
        $deckCards = [$this->card($heroRef, 1)];

        for ($i = 1; $i <= 13; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_C', $i);
            $deckCards[] = $this->card($ref, 3);
            $cardsData[$ref] = $this->data($ref);
        }

        return [$cardsData, $deckCards];
    }

    public function testValidNucDeckHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidNucDeck();

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testAnyUniqueCardReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidNucDeck();

        $ref = 'ALT_CORE_B_AX_99_U';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'UNIQUE', 'Unique Card');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'NUC format does not allow Unique cards')));
    }

    public function testMoreThan15RaresReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidNucDeck();

        for ($i = 1; $i <= 6; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_R1', $i);
            $deckCards[] = $this->card($ref, 3);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'RARE', "Rare $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'NUC format allows maximum 15 rare cards')));
    }

    public function testMoreThan3ExaltedReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidNucDeck();

        for ($i = 1; $i <= 4; ++$i) {
            $ref = sprintf('ALT_CORE_B_AX_%d_E', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'EXALTED', "Exalted $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'NUC format allows maximum 3 exalted cards')));
    }

    public function testForbiddenSetStillAppliesInNuc(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidNucDeck();

        $ref = 'ALT_FUGUE_B_AX_1_C';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref);

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'FUGUE') && str_contains($e, 'forbidden')));
    }
}
