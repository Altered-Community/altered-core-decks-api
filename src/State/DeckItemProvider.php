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

        $isWriteOperation = in_array($operation->getMethod(), ['PATCH', 'PUT', 'DELETE'], true);

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
