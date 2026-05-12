<?php

namespace App\Command;

use App\Repository\DeckRepository;
use App\Validator\Format\DeckFormatValidatorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:deck:check-set-legality',
    description: 'Updates isLegal on all decks based on format-specific set rules (no HTTP calls)',
)]
final class CheckDeckSetLegalityCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly DeckRepository            $deckRepository,
        private readonly DeckFormatValidatorFactory $validatorFactory,
        private readonly EntityManagerInterface    $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be written.');
        }

        $total = $this->deckRepository->countTotal();
        $io->writeln(sprintf('Processing <info>%d</info> deck(s)…', $total));

        $progress = new ProgressBar($output, $total);
        $progress->start();

        $offset    = 0;
        $updated   = 0;
        $unchanged = 0;
        $skipped   = 0;

        while (true) {
            $decks = $this->deckRepository->findBatchWithCards($offset, self::BATCH_SIZE);

            if (empty($decks)) {
                break;
            }

            foreach ($decks as $deck) {
                $format = $deck->getFormat();

                if ($format === null || !$this->validatorFactory->supports($format)) {
                    $skipped++;
                    $progress->advance();
                    continue;
                }

                $validator  = $this->validatorFactory->getValidator($format);
                $setErrors  = $validator->validateSets($deck);
                $legal      = $setErrors === [] && $deck->getFormatErrors() === [];

                if ($deck->isLegal() !== $legal) {
                    if (!$dryRun) {
                        $deck->setLegal($legal);
                    }
                    $updated++;
                } else {
                    $unchanged++;
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
}
