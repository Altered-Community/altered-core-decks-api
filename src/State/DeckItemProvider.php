<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Deck;
use App\Entity\User;
use App\Repository\DeckRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class DeckItemProvider implements ProviderInterface
{
    public function __construct(
        private DeckRepository $deckRepository,
        private Security       $security,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Deck
    {
        $deck = $this->deckRepository->find($uriVariables['id']);

        if (!$deck) {
            throw new NotFoundHttpException();
        }

        if ($deck->getIsPublic()) {
            return $deck;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer');
        }

        if ($deck->getUser() !== $user) {
            throw new AccessDeniedHttpException();
        }

        return $deck;
    }
}
