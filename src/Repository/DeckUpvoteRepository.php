<?php

namespace App\Repository;

use App\Entity\Deck;
use App\Entity\DeckUpvote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DeckUpvoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeckUpvote::class);
    }

    public function findOneByDeckAndUser(Deck $deck, User $user): ?DeckUpvote
    {
        return $this->findOneBy(['deck' => $deck, 'user' => $user]);
    }

    /**
     * @param Deck[] $decks
     *
     * @return string[] RFC4122 UUIDs of decks the user has upvoted
     */
    public function findUpvotedDeckIdsByUser(array $decks, User $user): array
    {
        if (empty($decks)) {
            return [];
        }

        $upvotes = $this->findBy(['deck' => $decks, 'user' => $user]);

        return array_map(fn (DeckUpvote $u) => (string) $u->getDeck()->getId(), $upvotes);
    }
}
