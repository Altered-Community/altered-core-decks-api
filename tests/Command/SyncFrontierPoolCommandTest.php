<?php

namespace App\Tests\Command;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Entity\FrontierPool;
use App\Entity\User;
use App\Enum\DeckFormat;
use App\Repository\FrontierPoolRepository;
use App\Tests\Support\FrontierFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SyncFrontierPoolCommandTest extends KernelTestCase
{
    private const array POOL_A = ['AX-020-U-101', 'BR-003-U-7'];
    private const array POOL_B = ['BR-003-U-7', 'LY-011-U-42'];

    private EntityManagerInterface $em;
    private MockHttpClient $alteredCoreMock;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
        static::getContainer()->get('cache.app')->clear();

        $this->user = (new User())->setKeycloakId('sync-frontier-pool-user');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function servePool(array $slugs, bool $uniqueInFrontier): void
    {
        $this->alteredCoreMock->setResponseFactory(FrontierFixtures::alteredCore(FrontierFixtures::cards($uniqueInFrontier), $slugs));
    }

    /**
     * A deck as it sits in the database, with legality already stored.
     */
    private function createDeck(DeckFormat $format, bool $legal, ?string $frontierPool, bool $isDraft = false): Deck
    {
        $deck = (new Deck())
            ->setName($format->value.' deck')
            ->setFormat($format)
            ->setIsDraft($isDraft)
            ->setUser($this->user)
            ->setLegal($legal)
            ->setLegalityDetail(['global' => $legal])
            ->setFrontierPool($frontierPool);
        foreach (FrontierFixtures::deckCards() as $row) {
            $deck->addDeckCard((new DeckCard())->setCardReference($row['cardReference'])->setQuantity($row['quantity']));
        }
        $this->em->persist($deck);
        $this->em->flush();

        return $deck;
    }

    private function reload(Deck $deck): Deck
    {
        $this->em->clear();

        return $this->em->find(Deck::class, $deck->getId());
    }

    private function runCommand(string $command = 'app:frontier:sync-pool', array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($input);

        return $tester;
    }

    public function testFirstRunRecordsThePoolAndRevalidatesUnstampedFrontierDecks(): void
    {
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $frontier = $this->createDeck(DeckFormat::Frontier, legal: false, frontierPool: null);

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $poolA = FrontierPool::fingerprint(self::POOL_A);
        self::assertSame($poolA, static::getContainer()->get(FrontierPoolRepository::class)->findCurrent()?->getId());

        $frontier = $this->reload($frontier);
        self::assertTrue($frontier->isLegal());
        self::assertTrue($frontier->getLegalityDetail()['frontierUniques']);
        self::assertSame($poolA, $frontier->getFrontierPool());
    }

    public function testPoolSwitchFlipsFrontierDecksWhoseUniqueLeftThePool(): void
    {
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $this->runCommand();
        $deck = $this->createDeck(DeckFormat::Frontier, legal: true, frontierPool: FrontierPool::fingerprint(self::POOL_A));

        $this->servePool(self::POOL_B, uniqueInFrontier: false);
        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Frontier pool changed', $tester->getDisplay());
        $deck = $this->reload($deck);
        self::assertFalse($deck->isLegal());
        self::assertNotEmpty($deck->getFormatErrors());
        self::assertSame(FrontierPool::fingerprint(self::POOL_B), $deck->getFrontierPool());
    }

    public function testSecondRunIsANoOp(): void
    {
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $this->createDeck(DeckFormat::Frontier, legal: false, frontierPool: null);
        $this->runCommand();

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Frontier pool unchanged', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Frontier decks to revalidate: 0/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Revalidated\s+0/', $tester->getDisplay());
    }

    public function testNonFrontierAndDraftDecksAreLeftAlone(): void
    {
        $this->servePool(self::POOL_B, uniqueInFrontier: false);
        // Stored states that a revalidation would change: standard legality flag, draft legal=false.
        $standard = $this->createDeck(DeckFormat::Standard, legal: false, frontierPool: null);
        $draft = $this->createDeck(DeckFormat::Frontier, legal: false, frontierPool: null, isDraft: true);

        $this->runCommand();

        $standard = $this->reload($standard);
        self::assertFalse($standard->isLegal());
        self::assertSame(['global' => false], $standard->getLegalityDetail());
        self::assertNull($standard->getFrontierPool());

        $draft = $this->em->find(Deck::class, $draft->getId());
        self::assertSame(['global' => false], $draft->getLegalityDetail());
        self::assertNull($draft->getFrontierPool());
    }

    public function testUnreachableCardsApiKeepsEverythingAsIs(): void
    {
        $this->alteredCoreMock->setResponseFactory(new MockResponse('down', ['http_code' => 503]));
        $deck = $this->createDeck(DeckFormat::Frontier, legal: true, frontierPool: 'old-pool');

        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull(static::getContainer()->get(FrontierPoolRepository::class)->findCurrent());
        $deck = $this->reload($deck);
        self::assertTrue($deck->isLegal());
        self::assertSame('old-pool', $deck->getFrontierPool());
    }

    public function testEmptyAllowlistIsRefused(): void
    {
        $this->servePool([], uniqueInFrontier: false);
        $deck = $this->createDeck(DeckFormat::Frontier, legal: true, frontierPool: 'old-pool');

        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        self::assertTrue($this->reload($deck)->isLegal());
    }

    public function testDeckWhoseCardsCannotBeFetchedStaysOnItsPoolAndIsRetried(): void
    {
        $allowlist = FrontierFixtures::alteredCore(FrontierFixtures::cards(false), self::POOL_B);
        $this->alteredCoreMock->setResponseFactory(static fn (string $method, string $url, array $options): MockResponse => str_contains($url, '/api/cards/batch')
            ? new MockResponse('down', ['http_code' => 503])
            : $allowlist($method, $url, $options));
        $deck = $this->createDeck(DeckFormat::Frontier, legal: true, frontierPool: 'old-pool');

        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        $deck = $this->reload($deck);
        self::assertTrue($deck->isLegal());
        self::assertSame('old-pool', $deck->getFrontierPool());

        $this->servePool(self::POOL_B, uniqueInFrontier: false);
        $retry = $this->runCommand();

        self::assertSame(0, $retry->getStatusCode());
        self::assertSame(FrontierPool::fingerprint(self::POOL_B), $this->reload($deck)->getFrontierPool());
    }

    public function testCheckSetLegalityStampsFrontierDecksWithTheCurrentPool(): void
    {
        $this->servePool(self::POOL_A, uniqueInFrontier: true);
        $this->runCommand();
        $frontier = $this->createDeck(DeckFormat::Frontier, legal: false, frontierPool: null);
        $standard = $this->createDeck(DeckFormat::Standard, legal: false, frontierPool: null);

        $tester = $this->runCommand('app:deck:check-set-legality');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(FrontierPool::fingerprint(self::POOL_A), $this->reload($frontier)->getFrontierPool());
        $standard = $this->em->find(Deck::class, $standard->getId());
        self::assertTrue($standard->isLegal());
        self::assertNull($standard->getFrontierPool());
    }

    public function testCheckSetLegalitySkipsDecksWhoseCardsCannotBeFetched(): void
    {
        $this->alteredCoreMock->setResponseFactory(new MockResponse('down', ['http_code' => 503]));
        $standard = $this->createDeck(DeckFormat::Standard, legal: true, frontierPool: null);

        $tester = $this->runCommand('app:deck:check-set-legality');

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/Skipped \(card fetch failed\)\s+1/', $tester->getDisplay());
        self::assertTrue($this->reload($standard)->isLegal());
    }
}
