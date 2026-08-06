<?php

namespace App\Tests\Validator\Format;

use App\Client\AlteredDraftSealedPoolClient;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\SealedFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class SealedFormatValidatorTest extends TestCase
{
    private const HERO_REF = 'ALT_EOLE_B_AX_0_C';

    private function validator(array $poolCounts): SealedFormatValidator
    {
        $poolClient = $this->createStub(AlteredDraftSealedPoolClient::class);
        $poolClient->method('getPoolCounts')->willReturn($poolCounts);

        return new SealedFormatValidator($poolClient);
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

    private function data(string $ref, string $typeRef = 'PERMANENT', string $faction = 'AX', string $rarityRef = 'COMMON', string $name = '', bool $isBanned = false, bool $isSuspended = false): array
    {
        return [
            'reference' => $ref,
            'cardType' => ['reference' => $typeRef],
            'faction' => ['code' => $faction],
            'rarity' => ['reference' => $rarityRef],
            'name' => $name ?: $ref,
            'isBanned' => $isBanned,
            'isSuspended' => $isSuspended,
        ];
    }

    /** @return array{0: array<string, array>, 1: DeckCard[], 2: array<string, int>} */
    private function buildMinimalValidDeck(): array
    {
        $cardsData = [self::HERO_REF => $this->data(self::HERO_REF, 'HERO_MAIN')];
        $deckCards = [$this->card(self::HERO_REF, 1)];
        // The hero is just another pool ref (altered-draft's heroesInPool:false event
        // config guarantees every hero of the tournament's set is in the returned pool)
        // — no special exemption.
        $poolCounts = [self::HERO_REF => 1];

        for ($i = 1; $i <= 29; ++$i) {
            $ref = sprintf('ALT_EOLE_B_AX_%d_C', $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref);
            $poolCounts[$ref] = 1;
        }

        return [$cardsData, $deckCards, $poolCounts];
    }

    public function testValidDeckHasNoErrors(): void
    {
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testMissingHeroReturnsError(): void
    {
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();
        array_shift($deckCards); // drop the hero
        unset($cardsData[self::HERO_REF]);

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'exactly 1 hero')));
    }

    public function testHeroNotInPoolReturnsError(): void
    {
        // No special "any hero from the tournament's set is legal" exemption — a hero
        // not in the pool is rejected exactly like any other card would be.
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();
        $poolCounts = []; // hero deliberately absent
        for ($i = 1; $i <= 29; ++$i) {
            $poolCounts[sprintf('ALT_EOLE_B_AX_%d_C', $i)] = 1;
        }

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'not in your sealed pool')));
    }

    public function testCardFromAnotherSetIsRejectedByPoolMembership(): void
    {
        // No sets restriction is hardcoded in this format — a card from a different
        // set is still blocked, just because it can never actually be in the
        // tournament's pool, not because of a dedicated "allowed sets" rule.
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();
        $ref = 'ALT_CORE_B_AX_1_C';
        $deckCards[] = $this->card($ref, 1);
        $cardsData[$ref] = $this->data($ref);
        // deliberately NOT added to $poolCounts

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'not in your sealed pool')));
    }

    public function testMoreThan3FactionsReturnsError(): void
    {
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();

        foreach (['BR', 'LY', 'MU'] as $i => $faction) {
            $ref = sprintf('ALT_EOLE_B_%s_%d_C', $faction, 90 + $i);
            $deckCards[] = $this->card($ref, 1);
            $cardsData[$ref] = $this->data($ref, 'PERMANENT', $faction);
            $poolCounts[$ref] = 1;
        }

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'factions')));
    }

    public function testFewerThan29NonHeroCardsReturnsError(): void
    {
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();
        array_splice($deckCards, 1, 5); // drop 5 non-hero cards, leaving 24

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'between')));
    }

    public function testCardNotInPoolReturnsError(): void
    {
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();
        $outsideRef = 'ALT_EOLE_B_AX_999_C';
        $deckCards[] = $this->card($outsideRef, 1);
        $cardsData[$outsideRef] = $this->data($outsideRef);
        // deliberately NOT added to $poolCounts

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'not in your sealed pool')));
    }

    public function testQuantityExceedingPoolReturnsError(): void
    {
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();
        $ref = 'ALT_EOLE_B_AX_1_C';
        $deckCards[1] = $this->card($ref, 3); // pool only has 1
        $poolCounts[$ref] = 1;

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'only 1 in your pool')));
    }

    public function testBannedOrSuspendedCardInPoolIsAllowed(): void
    {
        // Pool membership is the sole legality gate for sealed — a card that's since
        // been banned/suspended shouldn't block an otherwise-legal deck just because
        // it's still sitting in the player's pool.
        [$cardsData, $deckCards, $poolCounts] = $this->buildMinimalValidDeck();
        $ref = 'ALT_EOLE_B_AX_1_C';
        $cardsData[$ref] = $this->data($ref, isBanned: true, isSuspended: true);

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors);
    }

    public function testNoActiveEventFailsClosed(): void
    {
        [$cardsData, $deckCards] = $this->buildMinimalValidDeck();

        $errors = $this->validator([])->validate($this->deck(...$deckCards), $cardsData);
        // Empty pool (simulating null coalesced away) still rejects every card;
        // explicitly verify the null (no active event / unreachable) case separately below.
        self::assertNotEmpty($errors);

        $poolClient = $this->createStub(AlteredDraftSealedPoolClient::class);
        $poolClient->method('getPoolCounts')->willReturn(null);
        $validator = new SealedFormatValidator($poolClient);

        $errors = $validator->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'Could not verify your sealed pool')));
    }
}
