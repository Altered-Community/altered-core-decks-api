<?php

namespace App\Command;

use App\Client\AlteredCoreClient;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Repository\DeckRepository;
use App\Validator\Format\DeckFormatValidatorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:deck:check-set-legality',
    description: 'Updates legal and legalityDetail on all decks',
)]
final class CheckDeckSetLegalityCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly DeckRepository $deckRepository,
        private readonly DeckFormatValidatorFactory $validatorFactory,
        private readonly AlteredCoreClient $alteredCoreClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be written.');
        }

        $total = $this->deckRepository->countTotal();
        $io->writeln(sprintf('Processing <info>%d</info> deck(s)…', $total));

        $progress = new ProgressBar($output, $total);
        $progress->start();

        $offset = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        while (true) {
            $decks = $this->deckRepository->findBatchWithCards($offset, self::BATCH_SIZE);

            if (empty($decks)) {
                break;
            }

            foreach ($decks as $deck) {
                $format = $deck->getFormat()?->value;

                if (null === $format || !$this->validatorFactory->supports($format)) {
                    ++$skipped;
                    $progress->advance();
                    continue;
                }

                $validator = $this->validatorFactory->getValidator($format);
                $cardsData = $this->fetchCardsData($deck);
                $detail = $validator->computeLegalityDetail($deck, $cardsData);
                $legal = $detail['global'];

                if ($deck->isLegal() !== $legal || $deck->getLegalityDetail() !== $detail) {
                    if (!$dryRun) {
                        $deck->setLegal($legal);
                        $deck->setLegalityDetail($detail);
                    }
                    ++$updated;
                } else {
                    ++$unchanged;
                }

                $progress->advance();
            }

            if (!$dryRun) {
                $this->em->flush();
            }

            $this->em->clear();
            $offset += self::BATCH_SIZE;
        }

        $progress->finish();
        $io->newLine(2);

        $io->table(
            ['Status', 'Count'],
            [
                [$dryRun ? 'Would update' : 'Updated', $updated],
                ['Already correct',                      $unchanged],
                ['Skipped (no format)',                  $skipped],
            ]
        );

        return Command::SUCCESS;
    }

    /** @return array<string, array> */
    private function fetchCardsData(Deck $deck): array
    {
        $references = array_map(
            fn (DeckCard $dc) => $dc->getCardReference(),
            $deck->getDeckCards()->toArray(),
        );

        if (empty($references)) {
            return [];
        }

        try {
            return $this->alteredCoreClient->getCardsByReferences($references);
        } catch (\Throwable $e) {
            $this->logger->warning('CheckDeckSetLegality: could not fetch cards for deck {id}', [
                'id' => $deck->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
