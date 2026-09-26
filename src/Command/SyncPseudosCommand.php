<?php

namespace App\Command;

use App\Client\KeycloakAdminClient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:users:sync-pseudos', description: 'Fill user.username (public deck author) from the Keycloak `pseudo` attribute')]
final class SyncPseudosCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly KeycloakAdminClient $keycloakAdminClient,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the changes without saving them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $counts = ['checked' => 0, 'updated' => 0, 'unchanged' => 0, 'without pseudo' => 0];

        /** @var iterable<User> $users */
        $users = $this->em->createQuery(sprintf('SELECT u FROM %s u ORDER BY u.createdAt ASC', User::class))->toIterable();

        foreach ($users as $user) {
            ++$counts['checked'];
            $pseudo = $this->keycloakAdminClient->fetchPseudo($user->getKeycloakId());

            if (null === $pseudo) {
                ++$counts['without pseudo'];
            } elseif ($user->getUsername() === mb_substr($pseudo, 0, 100)) {
                ++$counts['unchanged'];
            } else {
                ++$counts['updated'];
                $user->setUsername(mb_substr($pseudo, 0, 100));
            }

            if (0 === $counts['checked'] % self::BATCH_SIZE) {
                $this->flush($dryRun);
            }
        }

        $this->flush($dryRun);

        // Counts only: never print pseudos or emails.
        foreach ($counts as $label => $count) {
            $output->writeln(sprintf('%s: %d', $label, $count));
        }
        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing saved.</comment>');
        }

        return Command::SUCCESS;
    }

    private function flush(bool $dryRun): void
    {
        if (!$dryRun) {
            $this->em->flush();
        }
        $this->em->clear();
    }
}
