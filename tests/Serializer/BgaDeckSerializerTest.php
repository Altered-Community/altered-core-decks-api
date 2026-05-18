<?php

namespace App\Tests\Serializer;

use App\Entity\Deck;
use App\Enum\DeckFormat;
use App\Serializer\BgaDeckSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

class BgaDeckSerializerTest extends TestCase
{
    private BgaDeckSerializer $serializer;
    private NormalizerInterface $symfonySerializer;

    protected function setUp(): void
    {
        $this->symfonySerializer = $this->createStub(NormalizerInterface::class);
        $this->serializer = new BgaDeckSerializer($this->symfonySerializer);
    }

    public function testCollectionEntryWithHero(): void
    {
        $deck = $this->deckWithStats([
            'hero' => ['reference' => 'ALT_CORE_B_AX_1_C'],
        ]);

        $result = $this->serializer->collectionEntry($deck);

        self::assertSame('ALT_CORE_B_AX_1_C', $result['hero']);
        self::assertSame('AX', $result['faction']);
        self::assertSame('deck-name', $result['deckName']);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $result['apiId']);
        self::assertSame(0, $result['cardQuantity']);
    }

    public function testCollectionEntryWithoutHero(): void
    {
        $deck = $this->deckWithStats([]);

        $result = $this->serializer->collectionEntry($deck);

        self::assertNull($result['hero']);
        self::assertNull($result['faction']);
        self::assertSame(0, $result['cardQuantity']);
    }

    public function testCollectionEntryFactionFromReferenceParts(): void
    {
        $deck = $this->deckWithStats([
            'hero' => ['reference' => 'ALT_CORE_B_MU_42_R1'],
        ]);

        $result = $this->serializer->collectionEntry($deck);

        self::assertSame('MU', $result['faction']);
    }

    public function testAdminRowWithHero(): void
    {
        $deck = $this->deckWithStats([
            'hero' => ['reference' => 'ALT_CORE_B_LY_7_U'],
            'totalCards' => 30,
        ]);

        $result = $this->serializer->adminRow($deck);

        self::assertSame('ALT_CORE_B_LY_7_U', $result['heroRef']);
        self::assertSame('LY', $result['faction']);
        self::assertSame('deck-name', $result['name']);
        self::assertSame('standard', $result['format']);
        self::assertSame(30, $result['totalCards']);
    }

    public function testAdminRowWithoutHero(): void
    {
        $deck = $this->deckWithStats([]);

        $result = $this->serializer->adminRow($deck);

        self::assertNull($result['heroRef']);
        self::assertNull($result['faction']);
        self::assertNull($result['totalCards']);
    }

    public function testNormalizeItemDelegatesToSymfonySerializer(): void
    {
        $deck = $this->createStub(Deck::class);
        $expected = ['foo' => 'bar'];

        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->expects(self::once())
            ->method('normalize')
            ->with(
                $deck,
                'json',
                ['groups' => ['deck:read', 'deck:read:detail'], 'view' => 'bga']
            )
            ->willReturn($expected);

        $result = (new BgaDeckSerializer($normalizer))->normalizeItem($deck);

        self::assertSame($expected, $result);
    }

    public function testNormalizeCollection(): void
    {
        $deck1 = $this->deckWithStats(['hero' => ['reference' => 'ALT_CORE_B_AX_1_C']], 'AX Deck');
        $deck2 = $this->deckWithStats(['hero' => ['reference' => 'ALT_CORE_B_BR_2_R1']], 'BR Deck');

        $result = $this->serializer->normalizeCollection([$deck1, $deck2]);

        self::assertCount(2, $result);
        self::assertSame('ALT_CORE_B_AX_1_C', $result[0]['hero']);
        self::assertSame('AX', $result[0]['faction']);
        self::assertSame('AX Deck', $result[0]['deckName']);
        self::assertSame('ALT_CORE_B_BR_2_R1', $result[1]['hero']);
        self::assertSame('BR', $result[1]['faction']);
        self::assertSame('BR Deck', $result[1]['deckName']);
    }

    private function deckWithStats(array $stats, string $name = 'deck-name'): Deck
    {
        $deck = $this->createStub(Deck::class);
        $uuid = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');

        $deck->method('getId')->willReturn($uuid);
        $deck->method('getName')->willReturn($name);
        $deck->method('getFormat')->willReturn(DeckFormat::Standard);
        $deck->method('getStats')->willReturn($stats);

        return $deck;
    }
}
