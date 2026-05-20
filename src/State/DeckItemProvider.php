<?php

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Deck;
use App\Entity\User;
use App\Repository\DeckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class DeckItemProvider implements ProviderInterface
{
    public function __construct(
        private DeckRepository $deckRepository,
        private EntityManagerInterface $em,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Deck
    {
        $deck = $this->deckRepository->find($uriVariables['id']);

        if (!$deck) {
            throw new NotFoundHttpException();
        }

        $isWriteOperation = $operation instanceof Patch || $operation instanceof Put || $operation instanceof Delete;

        // Public decks are readable by anyone, but only writable by their owner
        if (!$isWriteOperation && $deck->getIsPublic()) {
            return $deck;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer');
        }

        if ($deck->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedHttpException();
        }

        return $deck;
    }
}
