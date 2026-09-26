<?php

namespace App\Repository;

use App\Entity\FrontierPool;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FrontierPool>
 */
class FrontierPoolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FrontierPool::class);
    }

    public function findCurrent(): ?FrontierPool
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.revision', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
