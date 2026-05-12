<?php

namespace App\Tests\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\SingletonFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class SingletonFormatValidatorTest extends TestCase
{
    private SingletonFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SingletonFormatValidator();
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

    private function data(string $ref, string $typeRef = 'PERMANENT', string $faction = 'AX', string $rarityRef = 'CORAX_C', string $name = ''): array
    {
        return [
            'reference'  => $ref,
            'cardType'   => ['reference' => $typeRef],
            'faction'    => ['code' => $faction],
            'cardRarity' => ['reference' => $rarityRef],
            'name'       => $name ?: $ref,
        ];
    }

    /**
     * Builds a valid singleton deck: 1 hero + 20 distinct names × (C + R1 + R2) = 60 non-hero cards.
     * Each name has exactly 1 copy per rarity → passes singleton rule.
     *
     * @return array{0: array<string, array>, 1: DeckCard[]}
     */
    private function buildMinimalValidSingletonDeck(string $heroName = 'Sierra'): array
    {
        $heroRef             = 'ALT_CORE_B_AX_0_C';
        $cardsData           = [$heroRef => $this->data($heroRef, 'HERO_MAIN', 'AX', 'CORAX_C', $heroName)];
        $deckCards           = [$this->card($heroRef, 1)];

        // 20 card names, each with 1 common + 1 rare + 1 exalted = 3 per name = 60 total
        for ($i = 1; $i <= 20; $i++) {
            $name = "Card $i";

            $refC             = sprintf('ALT_CORE_B_AX_%d_C', $i);
            $deckCards[]      = $this->card($refC, 1);
            $cardsData[$refC] = $this->data($refC, 'PERMANENT', 'AX', 'CORAX_C', $name);

            $refR1             = sprintf('ALT_CORE_B_AX_%d_R1', $i);
            $deckCards[]       = $this->card($refR1, 1);
            $cardsData[$refR1] = $this->data($refR1, 'PERMANENT', 'AX', 'CORAX_R1', $name);

            $refR2             = sprintf('ALT_CORE_B_AX_%d_R2', $i);
            $deckCards[]       = $this->card($refR2, 1);
            $cardsData[$refR2] = $this->data($refR2, 'PERMANENT', 'AX', 'CORAX_R2', $name);
        }

        return [$cardsData, $deckCards];
    }

    public function testValidSingletonDeckHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck();

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testMoreThan1CopyPerRarityReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck();

        // Replace first common card with qty 2 (same rarity, same name)
        $ref             = 'ALT_CORE_B_AX_1_C';
        $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'CORAX_C', 'Card 1');

        foreach ($deckCards as &$dc) {
            if ($dc->getCardReference() === $ref) {
                $dc->setQuantity(2);
                break;
            }
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'max 1 per rarity')));
    }

    public function testMoreThan3TotalCopiesOfSameNameReturnsError(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck();

        // Add a unique copy of "Card 1" → total becomes 4 (C + R1 + R2 + U)
        $refU             = 'ALT_CORE_B_AX_1_U';
        $deckCards[]      = $this->card($refU, 1);
        $cardsData[$refU] = $this->data($refU, 'PERMANENT', 'AX', 'CORAX_U', 'Card 1');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'exceeds 3 copies total')));
    }

    public function testUniqueLimitRespectedForSierraHero(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck('Sierra');

        // Add 5 unique cards → Sierra allows 5
        for ($i = 1; $i <= 5; $i++) {
            $ref             = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[]     = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'CORAX_U', "Unique-only $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }

    public function testUniqueLimitExceededForTeijaHero(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck('Teija');

        // Add 4 unique cards → Teija allows only 3
        for ($i = 1; $i <= 4; $i++) {
            $ref             = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[]     = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'CORAX_U', "Unique-only $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }

    public function testUniqueLimitDefaultsTo3ForUnknownHero(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck('UnknownHero');

        // 4 unique cards → default limit is 3
        for ($i = 1; $i <= 4; $i++) {
            $ref             = sprintf('ALT_CORE_B_AX_%d_U', $i);
            $deckCards[]     = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', 'AX', 'CORAX_U', "Unique-only $i");
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }
}
