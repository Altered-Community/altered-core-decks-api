<?php

namespace App\Tests\Serializer;

use App\Client\AlteredCoreClient;
use App\Entity\Deck;
use App\Serializer\DeckNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class DeckNormalizerBgaTest extends TestCase
{
    private const HERO_REF  = 'ALT_CORE_B_AX_1_C';
    private const DECK_ID   = '550e8400-e29b-41d4-a716-446655440000';
    private const DECK_NAME = 'Test Deck';

    private DeckNormalizer $normalizer;
    private NormalizerInterface $inner;
    private AlteredCoreClient $coreClient;

    protected function setUp(): void
    {
        $this->inner      = $this->createStub(NormalizerInterface::class);
        $this->coreClient = $this->createStub(AlteredCoreClient::class);

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn(Request::create('/'));

        $this->normalizer = new DeckNormalizer($this->coreClient, $requestStack);
        $this->normalizer->setNormalizer($this->inner);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string,array> $cardsByRef keys are card references */
    private function normalize(array $deckCards, array $cardsByRef, array $formatErrors = []): array
    {
        $this->inner->method('normalize')->willReturn($this->buildDeckData($deckCards, $formatErrors));
        $this->coreClient->method('getCardsByReferences')->willReturn($cardsByRef);

        $deck = $this->createStub(Deck::class);

        return $this->normalizer->normalize($deck, 'json', ['view' => 'bga']);
    }

    private function buildDeckData(array $deckCards, array $formatErrors = []): array
    {
        $legal          = $formatErrors === [];
        $legalityDetail = ['hero' => $legal, 'deckSize' => $legal, 'global' => $legal];

        return [
            'name'           => self::DECK_NAME,
            'id'             => self::DECK_ID,
            'stats'          => ['hero' => ['reference' => self::HERO_REF], 'totalCards' => count($deckCards)],
            'formatErrors'   => $formatErrors,
            'legal'          => $legal,
            'legalityDetail' => $legalityDetail,
            'deckCards'      => $deckCards,
        ];
    }

    private function card(string $typeRef = 'character', array $subTypes = [], array $overrides = []): array
    {
        return array_merge([
            'name'          => ['fr' => 'Carte Test', 'en' => 'Test Card'],
            'cardType'      => ['reference' => $typeRef, 'name' => ucfirst($typeRef)],
            'cardSubTypes'  => $subTypes,
            'faction'       => ['code' => 'AX'],
            'mainCost'      => 3,
            'recallCost'    => 2,
            'forestPower'   => 1,
            'mountainPower' => 2,
            'oceanPower'    => 3,
            'artists'       => [['name' => 'John Doe']],
        ], $overrides);
    }

    private function deckCard(string $ref, int $qty = 1): array
    {
        return ['cardReference' => $ref, 'quantity' => $qty];
    }

    // ── Top-level output shape ─────────────────────────────────────────────────

    public function testOutputHasRequiredTopLevelKeys(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('id', $result);
        self::assertArrayHasKey('faction', $result);
        self::assertArrayHasKey('deckLegality', $result);
        self::assertArrayHasKey('alterator', $result);
        self::assertArrayHasKey('cardQuantity', $result);
        self::assertArrayHasKey('deckCardsByType', $result);
    }

    public function testNameAndIdPassedThrough(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        self::assertSame(self::DECK_NAME, $result['name']);
        self::assertSame(self::DECK_ID, $result['id']);
        self::assertSame(1, $result['cardQuantity']);
    }

    // ── faction & alterator ───────────────────────────────────────────────────

    public function testFactionReferenceComesFromLastCardFactionCode(): void
    {
        $ref    = 'ALT_CORE_B_BR_5_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card('character', [], ['faction' => ['code' => 'BR']])]);

        self::assertSame(['reference' => 'BR'], $result['faction']);
    }

    public function testAlteratorReferenceIsHeroFromStats(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        self::assertSame(['reference' => self::HERO_REF], $result['alterator']);
    }

    // ── deckLegality shape ────────────────────────────────────────────────────

    public function testDeckLegalityShape(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        self::assertTrue($result['deckLegality']['resume']['globalValidity']);
        self::assertArrayHasKey('hero', $result['deckLegality']['resume']);
        self::assertArrayHasKey('deckSize', $result['deckLegality']['resume']);
    }

    // ── Card grouping by type ─────────────────────────────────────────────────

    public function testCardGroupedByTypeReference(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref, 2)], [$ref => $this->card('character')]);

        self::assertArrayHasKey('character', $result['deckCardsByType']);
        self::assertArrayHasKey('deckUserListCard', $result['deckCardsByType']['character']);
        self::assertCount(1, $result['deckCardsByType']['character']['deckUserListCard']);
        self::assertSame(2, $result['deckCardsByType']['character']['deckUserListCard'][0]['quantity']);
    }

    public function testExpeditionPermanentGroupedAsPermanent(): void
    {
        $ref    = 'ALT_CORE_B_AX_3_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card('expedition_permanent')]);

        self::assertArrayHasKey('permanent', $result['deckCardsByType']);
        self::assertArrayNotHasKey('expedition_permanent', $result['deckCardsByType']);
    }

    public function testLandmarkPermanentGroupedAsPermanent(): void
    {
        $ref    = 'ALT_CORE_B_AX_4_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card('landmark_permanent')]);

        self::assertArrayHasKey('permanent', $result['deckCardsByType']);
        self::assertArrayNotHasKey('landmark_permanent', $result['deckCardsByType']);
    }

    public function testMultipleCardsOfSameTypeAreGroupedTogether(): void
    {
        $ref1 = 'ALT_CORE_B_AX_2_C';
        $ref2 = 'ALT_CORE_B_AX_3_C';
        $result = $this->normalize(
            [$this->deckCard($ref1), $this->deckCard($ref2)],
            [$ref1 => $this->card('character'), $ref2 => $this->card('character')],
        );

        self::assertCount(2, $result['deckCardsByType']['character']['deckUserListCard']);
    }

    public function testCardsOfDifferentTypesAreInSeparateGroups(): void
    {
        $ref1 = 'ALT_CORE_B_AX_2_C';
        $ref2 = 'ALT_CORE_B_AX_3_C';
        $result = $this->normalize(
            [$this->deckCard($ref1), $this->deckCard($ref2)],
            [$ref1 => $this->card('character'), $ref2 => $this->card('spell')],
        );

        self::assertArrayHasKey('character', $result['deckCardsByType']);
        self::assertArrayHasKey('spell', $result['deckCardsByType']);
    }

    // ── Card shape ────────────────────────────────────────────────────────────

    public function testCardEntryShape(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $entry = $result['deckCardsByType']['character']['deckUserListCard'][0];

        self::assertArrayHasKey('quantity', $entry);
        self::assertArrayHasKey('card', $entry);

        $card = $entry['card'];
        self::assertArrayHasKey('reference', $card);
        self::assertArrayHasKey('name', $card);
        self::assertArrayHasKey('cardType', $card);
        self::assertArrayHasKey('subTypes', $card);
        self::assertArrayHasKey('typeline', $card);
        self::assertArrayHasKey('mainFaction', $card);
        self::assertArrayHasKey('illustrator', $card);
        self::assertArrayHasKey('elements', $card);
    }

    public function testCardReferenceIsPreserved(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $card = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertSame($ref, $card['reference']);
    }

    public function testCardNameLocalized(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $card = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertSame('Carte Test', $card['name']);
    }

    public function testCardMainFactionShape(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $card = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertSame(['reference' => 'AX'], $card['mainFaction']);
    }

    public function testCardIllustratorShape(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $card = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertSame(['nickName' => 'John Doe'], $card['illustrator']);
    }

    public function testCardTypeShape(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card('character')]);

        $card = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertSame(['reference' => 'character'], $card['cardType']);
    }

    // ── Elements shape ────────────────────────────────────────────────────────

    public function testElementsHasAllFiveKeys(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $elements = $result['deckCardsByType']['character']['deckUserListCard'][0]['card']['elements'];
        self::assertArrayHasKey('MAIN_COST', $elements);
        self::assertArrayHasKey('RECALL_COST', $elements);
        self::assertArrayHasKey('FOREST_POWER', $elements);
        self::assertArrayHasKey('MOUNTAIN_POWER', $elements);
        self::assertArrayHasKey('OCEAN_POWER', $elements);
    }

    public function testElementValuesFromCardData(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $elements = $result['deckCardsByType']['character']['deckUserListCard'][0]['card']['elements'];
        self::assertSame(3, $elements['MAIN_COST']);
        self::assertSame(2, $elements['RECALL_COST']);
        self::assertSame(1, $elements['FOREST_POWER']);
        self::assertSame(2, $elements['MOUNTAIN_POWER']);
        self::assertSame(3, $elements['OCEAN_POWER']);
    }

    // ── Typeline ──────────────────────────────────────────────────────────────

    public function testTypelineBuiltFromCardTypeAndSubTypes(): void
    {
        $ref  = 'ALT_CORE_B_AX_2_C';
        $card = $this->card('character', [
            ['reference' => 'warrior', 'name' => 'Warrior'],
            ['reference' => 'mage', 'name' => 'Mage'],
        ]);
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $card]);

        $typeline = $result['deckCardsByType']['character']['deckUserListCard'][0]['card']['typeline'];
        self::assertSame('(Character - Warrior - Mage)', $typeline);
    }

    public function testTypelineWithNoSubTypes(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card('hero')]);

        $typeline = $result['deckCardsByType']['hero']['deckUserListCard'][0]['card']['typeline'];
        self::assertSame('(Hero)', $typeline);
    }

    public function testSubTypesAsArrayOfReferences(): void
    {
        $ref  = 'ALT_CORE_B_AX_2_C';
        $card = $this->card('character', [
            ['reference' => 'warrior', 'name' => 'Warrior'],
        ]);
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $card]);

        $subTypes = $result['deckCardsByType']['character']['deckUserListCard'][0]['card']['subTypes'];
        self::assertSame(['warrior'], $subTypes);
    }

    // ── Unique card handling ──────────────────────────────────────────────────

    public function testNonUniqueCardHasNoUniqueReducedField(): void
    {
        $ref    = 'ALT_CORE_B_AX_2_C';
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $this->card()]);

        $card = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertArrayNotHasKey('uniqueReduced', $card);
    }

    public function testUniqueCardHasUniqueReducedField(): void
    {
        // Reference must contain '_U_' for str_contains detection
        $ref  = 'ALT_CORE_B_AX_1_U_1';
        $card = $this->card('character', [], [
            'effect1' => [
                'abilityTrigger'   => ['alteredId' => 'trigger-id'],
                'abilityCondition' => ['alteredId' => 'condition-id'],
                'abilityEffect'    => ['alteredId' => 'effect-id'],
            ],
        ]);
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $card]);

        $cardEntry = $result['deckCardsByType']['character']['deckUserListCard'][0]['card'];
        self::assertArrayHasKey('uniqueReduced', $cardEntry);
    }

    public function testUniqueReducedContainsEffectIds(): void
    {
        $ref  = 'ALT_CORE_B_AX_1_U_1';
        $card = $this->card('character', [], [
            'effect1' => [
                'abilityTrigger'   => ['alteredId' => 'trigger-id'],
                'abilityCondition' => ['alteredId' => 'condition-id'],
                'abilityEffect'    => ['alteredId' => 'effect-id'],
            ],
        ]);
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $card]);

        $uniqueReduced = $result['deckCardsByType']['character']['deckUserListCard'][0]['card']['uniqueReduced'];
        self::assertCount(1, $uniqueReduced);
        self::assertSame([['trigger-id', 'condition-id', 'effect-id']], $uniqueReduced[0]['effects']);
    }

    public function testUniqueCardWithMultipleEffects(): void
    {
        $ref  = 'ALT_CORE_B_AX_1_U_1';
        $card = $this->card('character', [], [
            'effect1' => [
                'abilityTrigger'   => ['alteredId' => 't1'],
                'abilityCondition' => ['alteredId' => 'c1'],
                'abilityEffect'    => ['alteredId' => 'e1'],
            ],
            'effect2' => [
                'abilityTrigger'   => ['alteredId' => 't2'],
                'abilityCondition' => ['alteredId' => 'c2'],
                'abilityEffect'    => ['alteredId' => 'e2'],
            ],
        ]);
        $result = $this->normalize([$this->deckCard($ref)], [$ref => $card]);

        $uniqueReduced = $result['deckCardsByType']['character']['deckUserListCard'][0]['card']['uniqueReduced'];
        self::assertCount(2, $uniqueReduced);
    }
}
