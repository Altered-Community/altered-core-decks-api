<?php

namespace App\Tests\Validator\Format;

use App\Client\AlteredDraftSealedPoolClient;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Validator\Format\SealedFormatValidator;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * Reproduces the 422 reported on GET /api/bga/decks/{id} for a real user decklist
 * (EOLE sealed prerelease). Card data below (cardType/faction/rarity) comes straight
 * from https://cards.alteredcore.org/api/cards/batch — NOT parsed from the reference
 * string — because several "R2" rares and one Unique in this list are out-of-faction
 * (OOF): their reference keeps the faction of the card they were printed from, but
 * their *real* faction.code (what SealedFormatValidator::validateFaction actually
 * reads) is different. E.g. ALT_EOLE_B_YZ_106_R2 looks like Yzmir from its reference
 * but is faction LY in the live card data; ALT_EOLE_B_MU_112_U_2439 looks like Muna
 * but is faction AX. Once real faction data is used, this 28-line decklist only spans
 * 2 factions (AX, LY), well under the sealed cap of 3 — so a naive "count factions by
 * parsing the reference" reading would have wrongly flagged 5 factions and it's not
 * what the code does.
 */
class SealedFormatValidatorUserDeckTest extends TestCase
{
    private function validator(?array $poolCounts): SealedFormatValidator
    {
        $poolClient = $this->createStub(AlteredDraftSealedPoolClient::class);
        $poolClient->method('getPoolCounts')->willReturn($poolCounts);

        return new SealedFormatValidator($poolClient);
    }

    private function card(string $ref, int $qty): DeckCard
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

    private function data(string $ref, string $typeRef, string $faction, string $rarityRef): array
    {
        return [
            'reference' => $ref,
            'cardType' => ['reference' => $typeRef],
            'faction' => ['code' => $faction],
            'rarity' => ['reference' => $rarityRef],
            'name' => $ref,
            'isBanned' => false,
            'isSuspended' => false,
        ];
    }

    /** @return array{0: array<string, array>, 1: DeckCard[]} */
    private function buildUserDeck(): array
    {
        // [reference, quantity, cardType, real faction.code, rarity]
        $rows = [
            ['ALT_EOLE_B_AX_65_C', 1, 'HERO', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_106_R2', 1, 'CHARACTER', 'LY', 'RARE'],   // OOF: ref says AX, real faction LY
            ['ALT_EOLE_B_YZ_106_R2', 1, 'CHARACTER', 'LY', 'RARE'],   // OOF: ref says YZ, real faction LY
            ['ALT_EOLE_B_AX_106_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_LY_119_C', 1, 'CHARACTER', 'LY', 'COMMON'],
            ['ALT_EOLE_B_MU_107_R2', 1, 'CHARACTER', 'LY', 'RARE'],   // OOF: ref says MU, real faction LY
            ['ALT_EOLE_B_LY_107_R1', 1, 'CHARACTER', 'LY', 'RARE'],
            ['ALT_EOLE_B_LY_107_C', 2, 'CHARACTER', 'LY', 'COMMON'],
            ['ALT_EOLE_B_LY_113_C', 1, 'CHARACTER', 'LY', 'COMMON'],
            ['ALT_EOLE_B_LY_108_C', 1, 'CHARACTER', 'LY', 'COMMON'],
            ['ALT_EOLE_B_BR_112_R2', 1, 'CHARACTER', 'LY', 'RARE'],   // OOF: ref says BR, real faction LY
            ['ALT_EOLE_B_AX_110_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_107_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_122_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_112_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_114_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_MU_112_U_2439', 1, 'CHARACTER', 'AX', 'UNIQUE'], // OOF: ref says MU, real faction AX
            ['ALT_EOLE_B_MU_120_R2', 1, 'CHARACTER', 'LY', 'RARE'],   // OOF: ref says MU, real faction LY
            ['ALT_EOLE_B_LY_106_U_261', 1, 'CHARACTER', 'LY', 'UNIQUE'],
            ['ALT_EOLE_B_AX_115_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_116_C', 1, 'CHARACTER', 'AX', 'COMMON'],
            ['ALT_EOLE_B_AX_115_R1', 1, 'CHARACTER', 'AX', 'RARE'],
            ['ALT_EOLE_B_LY_114_R1', 1, 'CHARACTER', 'LY', 'RARE'],
            ['ALT_EOLE_B_LY_114_C', 1, 'CHARACTER', 'LY', 'COMMON'],
            ['ALT_EOLE_B_LY_117_E', 1, 'CHARACTER', 'LY', 'EXALTED'],
            ['ALT_EOLE_B_AX_119_C', 2, 'SPELL', 'AX', 'COMMON'],
            ['ALT_EOLE_B_YZ_121_R2', 1, 'LANDMARK_PERMANENT', 'AX', 'RARE'], // OOF: ref says YZ, real faction AX
            ['ALT_EOLE_B_AX_121_C', 2, 'LANDMARK_PERMANENT', 'AX', 'COMMON'],
        ];

        $cardsData = [];
        $deckCards = [];
        foreach ($rows as [$ref, $qty, $type, $faction, $rarity]) {
            $cardsData[$ref] = $this->data($ref, $type, $faction, $rarity);
            $deckCards[] = $this->card($ref, $qty);
        }

        return [$cardsData, $deckCards];
    }

    public function testStructurallyLegalOnlySpansTwoRealFactions(): void
    {
        [$cardsData, $deckCards] = $this->buildUserDeck();
        $poolCounts = [];
        foreach ($deckCards as $deckCard) {
            $poolCounts[$deckCard->getCardReference()] = $deckCard->getQuantity();
        }

        $errors = $this->validator($poolCounts)->validate($this->deck(...$deckCards), $cardsData);

        self::assertSame([], $errors, 'Hero present, 30 non-hero cards, and only 2 real factions (AX, LY) — this deck is legal once every card\'s pool count matches.');
    }

    public function testFailsWithUnverifiablePoolWhenDeckHasNoLinkedTournamentPool(): void
    {
        [$cardsData, $deckCards] = $this->buildUserDeck();

        // getPoolCounts() returns null when there's no pool linked to this deck id on
        // altered-draft's side yet — e.g. a deck still in draft that was never synced
        // through altered-draft's own frontend flow. This is the most likely real
        // cause of the reported 422, independent of the faction question above.
        $errors = $this->validator(null)->validate($this->deck(...$deckCards), $cardsData);

        self::assertNotEmpty(array_filter(
            $errors,
            fn ($e) => str_contains($e, 'Could not verify your sealed pool'),
        ));
    }
}
