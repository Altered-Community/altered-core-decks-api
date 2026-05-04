<?php

namespace App\Repository;

use App\Entity\Deck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;

class DeckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deck::class);
    }

    public function countCreatedToday(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.createdAt >= :today')
            ->setParameter('today', new \DateTimeImmutable('today midnight'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPublic(int $page, int $itemsPerPage, ?string $hero = null): array
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Deck::class, 'd');

        $heroFilter = $hero !== null ? "AND d.stats->'hero'->>'reference' = :hero" : '';

        $sql = "SELECT {$rsm->generateSelectClause(['d' => 'd'])}
                FROM deck d
                WHERE d.is_public = true AND d.is_draft = false {$heroFilter}
                ORDER BY d.created_at DESC
                LIMIT :limit OFFSET :offset";

        $query = $this->getEntityManager()->createNativeQuery($sql, $rsm)
            ->setParameter('limit', $itemsPerPage)
            ->setParameter('offset', ($page - 1) * $itemsPerPage);

        if ($hero !== null) {
            $query->setParameter('hero', $hero);
        }

        return $query->getResult();
    }

    public function countPublic(?string $hero = null): int
    {
        $params = [];
        $heroFilter = '';

        if ($hero !== null) {
            $heroFilter = "AND stats->'hero'->>'reference' = :hero";
            $params['hero'] = $hero;
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM deck WHERE is_public = true AND is_draft = false {$heroFilter}",
            $params,
        );
    }

    public function findRecentAnonymized(int $limit = 30): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, name, format, is_public, is_draft, created_at, stats FROM deck ORDER BY created_at DESC LIMIT :limit',
            ['limit' => $limit],
        );
    }
}
