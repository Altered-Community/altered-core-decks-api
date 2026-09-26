<?php

namespace App\Command;

use App\Client\CardDataProviderFactory;
use App\Entity\DeckCard;
use App\Repository\DeckRepository;
use App\Service\DeckLegalityChecker;
use App\Service\FrontierPoolSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant to run on a schedule (e.g. every 15 minutes). Idempotent: when the pool has not
 * changed and every Frontier deck is already stamped with it, it only reads the allowlist
 * and runs one empty query.
 */
#[AsCommand(
    name: 'app:frontier:sync-pool',
    description: 'Syncs the current Frontier pool from altered-core and revalidates Frontier decks not validated against it',
)]
final class SyncFrontierPoolCommand extends Command
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly FrontierPoolSynchronizer $synchronizer,
        private readonly DeckRepository $deckRepository,
        private readonly DeckLegalityChecker $legalityChecker,
        private readonly CardDataProviderFactory $cardDataProviderFactory,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->synchronizer->sync();
        } catch (\Throwable $e) {
            $this->logger->error('SyncFrontierPool: could not read the Frontier allowlist', ['error' => $e->getMessage()]);
            $io->error(sprintf('Could not read the Frontier allowlist: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $poolId = $result->current->getId();

        if ($result->hasChanged()) {
            $this->logger->notice('SyncFrontierPool: Frontier pool changed', [
                'previous' => $result->previous?->getId(),
                'current' => $poolId,
                'cardCount' => $result->current->getCardCount(),
            ]);
            $io->writeln(sprintf(
                'Frontier pool changed: <comment>%s</comment> → <info>%s</info> (%d card groups)',
                $result->previous?->getId() ?? 'none',
                $poolId,
                $result->current->getCardCount(),
            ));
        } else {
            $io->writeln(sprintf('Frontier pool unchanged: <info>%s</info>', $poolId));
        }

        $io->writeln(sprintf('Frontier decks to revalidate: <info>%d</info>', $this->deckRepository->countFrontierDecksNotOnPool($poolId)));

        $revalidated = 0;
        $flipped = 0;
        $failed = 0;
        $afterId = null;

        while ([] !== $decks = $this->deckRepository->findFrontierDecksNotOnPool($poolId, $afterId, self::BATCH_SIZE)) {
            foreach ($decks as $deck) {
                $afterId = $deck->getId();
                $references = array_map(
                    static fn (DeckCard $deckCard): string => $deckCard->getCardReference(),
                    $deck->getDeckCards()->toArray(),
                );

                try {
                    $cardsData = [] === $references ? [] : $this->cardDataProviderFactory->getProvider()->getCardsByReferences($references);
                } catch (\Throwable $e) {
                    // Leave the deck on its old pool: the next run picks it up again.
                    $this->logger->warning('SyncFrontierPool: could not fetch cards for deck {id}', [
                        'id' => (string) $deck->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    ++$failed;
                    continue;
                }

                $wasLegal = $deck->isLegal();
                $this->legalityChecker->check($deck, $cardsData, $poolId);
                ++$revalidated;
                if ($wasLegal !== $deck->isLegal()) {
                    ++$flipped;
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->table(['Status', 'Count'], [
            ['Revalidated', $revalidated],
            ['Legality changed', $flipped],
            ['Failed (retried next run)', $failed],
        ]);

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
