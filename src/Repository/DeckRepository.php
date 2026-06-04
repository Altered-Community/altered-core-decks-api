<?php

namespace App\Repository;

use App\Entity\Deck;
use App\Entity\User;
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

    /**
     * Returns a batch of decks with their deckCards eagerly loaded.
     * Uses a two-step query (IDs first, then JOIN FETCH) to avoid Doctrine
     * pagination issues with collection joins.
     *
     * @return Deck[]
     */
    public function findBatchWithCards(int $offset, int $limit): array
    {
        $ids = $this->createQueryBuilder('d')
            ->select('d.id')
            ->orderBy('d.id')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->leftJoin('d.deckCards', 'dc')
            ->addSelect('dc')
            ->where('d.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function findPublic(int $page, int $itemsPerPage, ?string $hero = null, ?string $cardName = null, string $orderBy = 'created_at', ?string $faction = null, ?string $name = null): array
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Deck::class, 'd');

        [$join, $where, $params] = $this->buildPublicFilters($hero, $cardName, $faction, $name);

        $allowedOrderBy = ['created_at', 'upvote_count', 'view_count'];
        $col = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'created_at';

        $sql = "SELECT {$rsm->generateSelectClause(['d' => 'd'])}
                FROM deck d {$join}
                WHERE {$where}
                ORDER BY d.{$col} DESC
                LIMIT :limit OFFSET :offset";

        $query = $this->getEntityManager()->createNativeQuery($sql, $rsm)
            ->setParameter('limit', $itemsPerPage)
            ->setParameter('offset', ($page - 1) * $itemsPerPage);

        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }

        return $query->getResult();
    }

    public function countPublic(?string $hero = null, ?string $cardName = null, ?string $faction = null, ?string $name = null): int
    {
        [$join, $where, $params] = $this->buildPublicFilters($hero, $cardName, $faction, $name);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(DISTINCT d.id) FROM deck d {$join} WHERE {$where}",
            $params,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function buildPublicFilters(?string $hero, ?string $cardName, ?string $faction = null, ?string $name = null): array
    {
        $where = 'd.is_public = true AND d.is_draft = false';
        $join = '';
        $params = [];

        if (null !== $hero) {
            // Normalise to faction_number (parts 4+5) so decks with the same hero across
            // different sets (CORE/COREKS/BISE) or rarities are all returned.
            $parts = explode('_', $hero);
            $heroKey = ($parts[3] ?? '').'_'.($parts[4] ?? '');
            $where .= " AND split_part(d.stats->'hero'->>'reference', '_', 4)"
                    ." || '_' || split_part(d.stats->'hero'->>'reference', '_', 5) = :heroKey";
            $params['heroKey'] = $heroKey;
        }

        if (null !== $faction) {
            $where .= " AND split_part(d.stats->'hero'->>'reference', '_', 4) = :faction";
            $params['faction'] = $faction;
        }

        if (null !== $cardName) {
            $join = 'JOIN deck_card dc ON dc.deck_id = d.id';
            $where .= ' AND dc.name ILIKE :cardName';
            $params['cardName'] = '%'.$cardName.'%';
        }

        if (null !== $name) {
            $where .= ' AND d.name LIKE :name';
            $params['name'] = '%'.$name.'%';
        }

        return [$join, $where, $params];
    }

    public function findBgaDecks(?User $user, int $page, int $itemsPerPage, string $name, array $factions, string $hero, string $format, array $validFormats = []): array
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Deck::class, 'd');

        [$conditions, $params] = $this->buildBgaConditions($user, $name, $factions, $hero, $format, $validFormats);
        $where = 'WHERE '.implode(' AND ', $conditions);

        $sql = "SELECT {$rsm->generateSelectClause(['d' => 'd'])} FROM deck d {$where} ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset";

        $query = $this->getEntityManager()->createNativeQuery($sql, $rsm);
        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }
        $query->setParameter('limit', $itemsPerPage);
        $query->setParameter('offset', ($page - 1) * $itemsPerPage);

        return $query->getResult();
    }

    public function countBgaDecks(?User $user, string $name, array $factions, string $hero, string $format, array $validFormats = []): int
    {
        [$conditions, $params] = $this->buildBgaConditions($user, $name, $factions, $hero, $format, $validFormats);
        $where = 'WHERE '.implode(' AND ', $conditions);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM deck d {$where}",
            $params,
        );
    }

    private function buildBgaConditions(?User $user, string $name, array $factions, string $hero, string $format, array $validFormats = []): array
    {
        if (null === $user) {
            return [['1 = 0'], []];
        }

        $conditions = ['d.legal = true', 'd.user_id = :userId'];
        $params = ['userId' => (string) $user->getId()];

        if ('' !== $name) {
            $conditions[] = 'd.name ILIKE :name';
            $params['name'] = '%'.$name.'%';
        }

        if ('' !== $hero) {
            $conditions[] = "d.stats->'hero'->>'reference' = :hero";
            $params['hero'] = $hero;
        }

        if ('' !== $format) {
            $conditions[] = 'd.format = :format';
            $params['format'] = $format;
        } elseif (!empty($validFormats)) {
            $inList = [];
            foreach ($validFormats as $i => $fmt) {
                $key = 'fmt'.$i;
                $inList[] = ':'.$key;
                $params[$key] = $fmt;
            }
            $conditions[] = 'd.format IN ('.implode(', ', $inList).')';
        }

        if (!empty($factions)) {
            $inList = [];
            foreach ($factions as $i => $faction) {
                $key = 'faction'.$i;
                $inList[] = ':'.$key;
                $params[$key] = $faction;
            }
            $conditions[] = "split_part(d.stats->'hero'->>'reference', '_', 4) IN (".implode(', ', $inList).')';
        }

        return [$conditions, $params];
    }

    /**
     * @return array<int, array{reference: string, name: string|null, imagePath: string|null}>
     */
    public function findPublicHeroes(string $locale = 'fr'): array
    {
        // Group by faction+number (parts 4 and 5) to deduplicate heroes that share the
        // same identity across different sets (e.g. ALT_CORE_B_OR_03_C and ALT_BISE_B_OR_03_C)
        // or rarities. MIN() picks a stable canonical reference for the consumer.
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT
                MIN(d.stats->'hero'->>'reference') AS reference,
                MIN(d.stats->'hero'->>'name') AS name,
                MIN(d.stats->'hero'->>'imagePath') AS image_path
             FROM deck d
             WHERE d.is_public = true
               AND d.is_draft = false
               AND d.stats->'hero'->>'reference' IS NOT NULL
             GROUP BY
                split_part(d.stats->'hero'->>'reference', '_', 4),
                split_part(d.stats->'hero'->>'reference', '_', 5)
             ORDER BY
                split_part(d.stats->'hero'->>'reference', '_', 4),
                split_part(d.stats->'hero'->>'reference', '_', 5)",
        );

        return array_map(function (array $row) use ($locale): array {
            // name/imagePath may be a plain string or a JSON object (locale map).
            // ->> already strips JSON string quotes, so a plain string needs no decoding.
            $nameDecoded = isset($row['name']) ? json_decode($row['name'], true) : null;
            $imageDecoded = isset($row['image_path']) ? json_decode($row['image_path'], true) : null;

            return [
                'reference' => $row['reference'],
                'name' => is_array($nameDecoded)
                    ? ($nameDecoded[$locale] ?? $nameDecoded['fr'] ?? null)
                    : ($row['name'] ?? null),
                'imagePath' => is_array($imageDecoded)
                    ? ($imageDecoded[$locale] ?? $imageDecoded['fr'] ?? null)
                    : ($row['image_path'] ?? null),
            ];
        }, $rows);
    }

    public function findRecentAnonymized(int $limit = 30): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, name, format, is_public, is_draft, created_at, stats FROM deck ORDER BY created_at DESC LIMIT :limit',
            ['limit' => $limit],
        );
    }
}
