<?php

namespace App\Tests\Command;

use App\Client\AlteredDraftSealedPoolClient;
use App\Client\CardDataProviderFactory;
use App\Command\CheckDeckSetLegalityCommand;
use App\Entity\Deck;
use App\Enum\DeckFormat;
use App\Repository\DeckRepository;
use App\Validator\Format\DeckFormatValidatorFactory;
use App\Validator\Format\SealedFormatValidator;
use App\Validator\Format\StandardFormatValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

class CheckDeckSetLegalityCommandTest extends TestCase
{
    public function testSealedDecksAreSkippedAndKeepTheirLegality(): void
    {
        $sealedDetail = ['global' => true, 'pool' => true];
        $sealedDeck = (new Deck())
            ->setFormat(DeckFormat::Sealed)
            ->setLegal(true)
            ->setLegalityDetail($sealedDetail);

        // Empty standard deck: recomputed offline, ends up illegal.
        $standardDeck = (new Deck())
            ->setFormat(DeckFormat::Standard)
            ->setLegal(true)
            ->setLegalityDetail(['global' => true]);

        $repository = $this->createStub(DeckRepository::class);
        $repository->method('countTotal')->willReturn(2);
        $repository->method('findBatchWithCards')->willReturnOnConsecutiveCalls([$sealedDeck, $standardDeck], []);

        // Without a player request, the pool lookup would fail closed — it must never be reached.
        $poolClient = $this->createMock(AlteredDraftSealedPoolClient::class);
        $poolClient->expects($this->never())->method('getPoolCounts');

        $command = new CheckDeckSetLegalityCommand(
            deckRepository: $repository,
            validatorFactory: new DeckFormatValidatorFactory([
                new SealedFormatValidator($poolClient),
                new StandardFormatValidator(),
            ]),
            cardDataProviderFactory: new CardDataProviderFactory([], 'none'),
            em: $this->createStub(EntityManagerInterface::class),
            logger: new NullLogger(),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertTrue($sealedDeck->isLegal());
        $this->assertSame($sealedDetail, $sealedDeck->getLegalityDetail());
        $this->assertFalse($standardDeck->isLegal());
        $this->assertMatchesRegularExpression('/Skipped \(requires player context\)\s+1/', $tester->getDisplay());
    }
}
