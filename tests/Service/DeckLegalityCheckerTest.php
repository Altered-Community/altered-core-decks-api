<?php

namespace App\Tests\Service;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Enum\DeckFormat;
use App\Service\DeckLegalityChecker;
use App\Tests\Support\FrontierFixtures;
use App\Validator\Format\DeckFormatValidatorFactory;
use App\Validator\Format\FrontierFormatValidator;
use App\Validator\Format\StandardFormatValidator;
use PHPUnit\Framework\TestCase;

class DeckLegalityCheckerTest extends TestCase
{
    private DeckLegalityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new DeckLegalityChecker(
            new DeckFormatValidatorFactory([new StandardFormatValidator(), new FrontierFormatValidator()]),
        );
    }

    private function deck(?DeckFormat $format, bool $isDraft = false): Deck
    {
        $deck = (new Deck())->setName('Deck')->setFormat($format)->setIsDraft($isDraft);
        foreach (FrontierFixtures::deckCards() as $row) {
            $deck->addDeckCard((new DeckCard())->setCardReference($row['cardReference'])->setQuantity($row['quantity']));
        }

        return $deck;
    }

    /** @return array<string, array> */
    private function cardsData(bool $uniqueInFrontier): array
    {
        return array_column(FrontierFixtures::cards($uniqueInFrontier), null, 'reference');
    }

    public function testFrontierDeckIsStampedWithThePoolItWasCheckedAgainst(): void
    {
        $deck = $this->deck(DeckFormat::Frontier);

        $this->checker->check($deck, $this->cardsData(uniqueInFrontier: true), 'pool-a');

        self::assertTrue($deck->isLegal());
        self::assertNull($deck->getFormatErrors());
        self::assertTrue($deck->getLegalityDetail()['frontierUniques']);
        self::assertSame('pool-a', $deck->getFrontierPool());
    }

    public function testFrontierDeckWithUniqueOutOfPoolIsIllegalAndStillStamped(): void
    {
        $deck = $this->deck(DeckFormat::Frontier);

        $this->checker->check($deck, $this->cardsData(uniqueInFrontier: false), 'pool-b');

        self::assertFalse($deck->isLegal());
        self::assertFalse($deck->getLegalityDetail()['frontierUniques']);
        self::assertNotEmpty($deck->getFormatErrors());
        self::assertSame('pool-b', $deck->getFrontierPool());
    }

    public function testFrontierDeckWithoutKnownPoolIsStampedNull(): void
    {
        $deck = $this->deck(DeckFormat::Frontier);

        $this->checker->check($deck, $this->cardsData(uniqueInFrontier: true), null);

        self::assertTrue($deck->isLegal());
        self::assertNull($deck->getFrontierPool());
    }

    public function testNonFrontierDeckNeverCarriesAPool(): void
    {
        $deck = $this->deck(DeckFormat::Standard)->setFrontierPool('pool-a');

        $this->checker->check($deck, $this->cardsData(uniqueInFrontier: false), 'pool-a');

        self::assertTrue($deck->isLegal());
        self::assertNull($deck->getFrontierPool());
    }

    public function testDraftIsClearedWhateverItsFormat(): void
    {
        $deck = $this->deck(DeckFormat::Frontier, isDraft: true)->setFrontierPool('pool-a')->setLegal(true);

        $this->checker->check($deck, $this->cardsData(uniqueInFrontier: true), 'pool-a');

        self::assertFalse($deck->isLegal());
        self::assertNull($deck->getLegalityDetail());
        self::assertNull($deck->getFormatErrors());
        self::assertNull($deck->getFrontierPool());
    }

    public function testDeckWithoutFormatIsCleared(): void
    {
        $deck = $this->deck(null);

        $this->checker->check($deck, $this->cardsData(uniqueInFrontier: true), 'pool-a');

        self::assertFalse($deck->isLegal());
        self::assertNull($deck->getLegalityDetail());
        self::assertNull($deck->getFrontierPool());
    }
}
