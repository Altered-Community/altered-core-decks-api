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
     * Toggles the upvote for a user on a deck.
     * Uses an atomic DQL UPDATE to avoid race conditions.
     *
     * @return array{hasUpvoted: bool, upvoteCount: int}
     */
    public function toggle(Deck $deck, User $user): array
    {
        $existing = $this->findOneByDeckAndUser($deck, $user);
        $em = $this->getEntityManager();

        if ($existing) {
            $em->remove($existing);
            $em->flush();
            $em->createQueryBuilder()
                ->update(Deck::class, 'd')
                ->set('d.upvoteCount', 'GREATEST(0, d.upvoteCount - 1)')
                ->where('d.id = :id')
                ->setParameter('id', $deck->getId(), 'uuid')
                ->getQuery()
                ->execute();
            $upvoted = false;
        } else {
            $em->persist(new DeckUpvote($deck, $user));
            $em->flush();
            $em->createQueryBuilder()
                ->update(Deck::class, 'd')
                ->set('d.upvoteCount', 'd.upvoteCount + 1')
                ->where('d.id = :id')
                ->setParameter('id', $deck->getId(), 'uuid')
                ->getQuery()
                ->execute();
            $upvoted = true;
        }

        $em->refresh($deck);

        return ['hasUpvoted' => $upvoted, 'upvoteCount' => $deck->getUpvoteCount()];
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
