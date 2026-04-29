<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:promote-admin', description: 'Promote a user to admin by email')]
final class PromoteAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the user to promote');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        $user  = $this->userRepository->findOneBy(['email' => $email]);

        if ($user === null) {
            $output->writeln("<error>No user found with email: {$email}</error>");
            return Command::FAILURE;
        }

        $user->setIsAdmin(true);
        $this->em->flush();

        $output->writeln("<info>{$email} is now admin.</info>");
        return Command::SUCCESS;
    }
}
