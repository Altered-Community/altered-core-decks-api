<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Repository\DeckRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class DeckCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly DeckRepository $deckRepository,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            return [];
        }

        $request = $this->requestStack->getCurrentRequest();
        $faction = $request?->query->get('faction') ?: null;
        $hero = $request?->query->get('hero') ?: null;

        return $this->deckRepository->findByUser($currentUser, $faction, $hero);
    }
}
