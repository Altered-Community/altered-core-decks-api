<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Deck;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class DeckCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $currentUser = $this->security->getUser();

        if ($currentUser instanceof User) {
            return $this->getQueryBuilder($operation, $context, $currentUser)
                ->getQuery()
                ->getResult();
        }

        return $this->getQueryBuilder($operation, $context)->getQuery()->getResult();
    }

    private function getQueryBuilder(Operation $operation, array $context, ?User $currentUser): QueryBuilder
    {
        $qb = $this->em->getRepository(Deck::class)->createQueryBuilder('deck');

        if ($currentUser) {
            $qb->andWhere('deck.user = :user')
               ->setParameter('user', $currentUser);
        }

        return $qb;
    }
}