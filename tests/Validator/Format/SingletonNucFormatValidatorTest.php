<?php

namespace App\Tests\Validator\Format;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\SingletonNucFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class SingletonNucFormatValidatorTest extends TestCase
{
    private SingletonNucFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SingletonNucFormatValidator();
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
            'reference' => $ref,
            'cardType' => ['reference' => $typeRef],
            'faction' => ['code' => $faction],
            'cardRarity' => ['reference' => $rarityRef],
            'name' => $name ?: $ref,
        ];
    }

    /**
     * Builds a valid singleton deck: 1 hero + 20 distinct names × (C + R1 + R2) = 60 non-hero cards.
     *
     * @return array{0: array<string, array>, 1: DeckCard[]}
     */
    private function buildMinimalValidSingletonDeck(string $heroName = 'Sierra'): array
    {
        $heroRef = 'ALT_CORE_B_AX_0_C';
        $cardsData = [$heroRef => $this->data($heroRef, 'HERO_MAIN', 'AX', 'CORAX_C', $heroName)];
        $deckCards = [$this->card($heroRef, 1)];

        for ($i = 1; $i <= 20; ++$i) {
            $name = "Card $i";

            $refC = sprintf('ALT_CORE_B_AX_%d_C', $i);
            $deckCards[] = $this->card($refC, 1);
            $cardsData[$refC] = $this->data($refC, 'PERMANENT', 'AX', 'CORAX_C', $name);

            $refR1 = sprintf('ALT_CORE_B_AX_%d_R1', $i);
            $deckCards[] = $this->card($refR1, 1);
            $cardsData[$refR1] = $this->data($refR1, 'PERMANENT', 'AX', 'CORAX_R1', $name);

            $refR2 = sprintf('ALT_CORE_B_AX_%d_R2', $i);
            $deckCards[] = $this->card($refR2, 1);
            $cardsData[$refR2] = $this->data($refR2, 'PERMANENT', 'AX', 'CORAX_R2', $name);
        }

        return [$cardsData, $deckCards];
    }

    public function testFormatCode(): void
    {
        self::assertSame('singleton_nuc', $this->validator->getFormat());
    }

    public function testValidSingletonNucDeckHasNoErrors(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck();

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testAnyUniqueCardReturnsError(): void
    {
        // Sierra normally allows 5 uniques in singleton — must still be 0 here.
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck('Sierra');

        $refU = 'ALT_CORE_B_AX_99_U';
        $deckCards[] = $this->card($refU, 1);
        $cardsData[$refU] = $this->data($refU, 'PERMANENT', 'AX', 'CORAX_U', 'Unique-only 1');

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Unique cards')));
    }

    public function testSingletonRulesStillEnforced(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidSingletonDeck();

        // Same rarity duplicated → still violates singleton rule.
        $ref = 'ALT_CORE_B_AX_1_C';
        foreach ($deckCards as &$dc) {
            if ($dc->getCardReference() === $ref) {
                $dc->setQuantity(2);
                break;
            }
        }

        $errors = $this->validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'max 1 per rarity')));
    }
}
