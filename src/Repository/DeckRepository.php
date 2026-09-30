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

    /**
     * Runs a native SELECT over the deck table, hydrated into Deck entities.
     * $sqlTail is everything after "SELECT <cols> FROM deck d " — joins, WHERE, ORDER, LIMIT.
     *
     * @param array<string, mixed> $params
     *
     * @return Deck[]
     */
    private function fetchDecks(string $sqlTail, array $params): array
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Deck::class, 'd');

        $query = $this->getEntityManager()->createNativeQuery(
            "SELECT {$rsm->generateSelectClause(['d' => 'd'])} FROM deck d {$sqlTail}",
            $rsm,
        );

        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }

        return $query->getResult();
    }

    public function findPublic(int $page, int $itemsPerPage, ?string $hero = null, ?string $cardName = null, string $orderBy = 'created_at', ?string $faction = null, ?string $name = null, ?string $format = null, ?string $cardRef = null, string $orderDir = 'DESC'): array
    {
        [$where, $params] = $this->buildPublicFilters($hero, $cardName, $faction, $name, $format, $cardRef);

        $allowedOrderBy = ['created_at', 'updated_at', 'name', 'upvote_count', 'view_count'];
        $col = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'created_at';
        $dir = 'ASC' === strtoupper($orderDir) ? 'ASC' : 'DESC';

        $params['limit'] = $itemsPerPage;
        $params['offset'] = ($page - 1) * $itemsPerPage;

        return $this->fetchDecks(
            "WHERE {$where} ORDER BY d.{$col} {$dir} LIMIT :limit OFFSET :offset",
            $params,
        );
    }

    public function countPublic(?string $hero = null, ?string $cardName = null, ?string $faction = null, ?string $name = null, ?string $format = null, ?string $cardRef = null): int
    {
        [$where, $params] = $this->buildPublicFilters($hero, $cardName, $faction, $name, $format, $cardRef);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(d.id) FROM deck d WHERE {$where}",
            $params,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildPublicFilters(?string $hero, ?string $cardName, ?string $faction = null, ?string $name = null, ?string $format = null, ?string $cardRef = null): array
    {
        $where = 'd.is_public = true AND d.is_draft = false';
        $params = [];

        [$heroFactionWhere, $heroFactionParams] = $this->buildHeroFactionWhere($hero, $faction);
        $where .= $heroFactionWhere;
        $params += $heroFactionParams;

        if (null !== $cardName) {
            $where .= ' AND EXISTS (SELECT 1 FROM deck_card dc WHERE dc.deck_id = d.id AND dc.name ILIKE :cardName)';
            $params['cardName'] = '%'.$cardName.'%';
        }

        if (null !== $cardRef) {
            $where .= ' AND EXISTS (SELECT 1 FROM deck_card dc WHERE dc.deck_id = d.id AND dc.card_reference = :cardRef)';
            $params['cardRef'] = $cardRef;
        }

        if (null !== $name) {
            $where .= ' AND d.name LIKE :name';
            $params['name'] = '%'.$name.'%';
        }

        if (null !== $format) {
            $where .= ' AND d.format = :format';
            $params['format'] = $format;
        }

        return [$where, $params];
    }

    /**
     * SQL WHERE fragments (each prefixed with " AND ") matching a deck's stored hero
     * reference (jsonb) by faction and/or hero. Hero is normalised to faction_number
     * (reference parts 4+5) so the same hero across different sets or rarities all match
     * — identical semantics to the public listing so a hero picked from the shared hero
     * list filters "My Decks" and "Community" the same way.
     *
     * @return array{0: string, 1: array<string, mixed>} [whereFragment, params]
     */
    private function buildHeroFactionWhere(?string $hero, ?string $faction): array
    {
        $where = '';
        $params = [];

        if (null !== $hero) {
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

        return [$where, $params];
    }

    /**
     * Columns the "my decks" listing may be ordered by, keyed by the API field name
     * (the `order[field]=asc|desc` query parameter). Values are trusted SQL expressions:
     * only these ever reach the ORDER BY clause.
     *
     * updatedAt falls back to created_at: a deck that was never edited has a NULL
     * updated_at, and Postgres would otherwise sort those before every edited deck.
     */
    private const USER_DECK_ORDER_COLUMNS = [
        'createdAt' => 'd.created_at',
        'updatedAt' => 'COALESCE(d.updated_at, d.created_at)',
        'name' => 'LOWER(d.name)',
        'upvoteCount' => 'd.upvote_count',
        'viewCount' => 'd.view_count',
    ];

    /**
     * One page of the decks owned by $user, filtered and ordered.
     *
     * Filters combine with AND. Faction/hero use the same jsonb matching as the public
     * listing (see buildHeroFactionWhere). $name is a case-insensitive substring match.
     * $order maps API field names (see USER_DECK_ORDER_COLUMNS) to 'asc'|'desc'; unknown
     * fields are ignored, and d.id is always appended so pages never overlap or skip a
     * deck when the sort value ties. Default: most recently updated first.
     *
     * @param array<string, string> $order
     *
     * @return Deck[]
     */
    public function findByUser(
        User $user,
        int $offset = 0,
        ?int $limit = null,
        ?string $faction = null,
        ?string $hero = null,
        ?string $format = null,
        ?bool $isPublic = null,
        ?bool $isDraft = null,
        ?string $name = null,
        array $order = [],
    ): array {
        [$where, $params] = $this->buildUserFilters($user, $faction, $hero, $format, $isPublic, $isDraft, $name);

        $orderBy = [];
        foreach ($order as $field => $direction) {
            if (isset(self::USER_DECK_ORDER_COLUMNS[$field])) {
                $orderBy[] = [self::USER_DECK_ORDER_COLUMNS[$field], 'asc' === strtolower($direction) ? 'ASC' : 'DESC'];
            }
        }
        if ([] === $orderBy) {
            $orderBy[] = [self::USER_DECK_ORDER_COLUMNS['updatedAt'], 'DESC'];
        }
        // Tie-breaker in the primary direction. Timestamps are stored to the second and ids
        // are time-ordered UUIDv7, so decks saved within the same second still come out in
        // creation order (newest first for a DESC sort).
        $orderBy[] = ['d.id', $orderBy[0][1]];

        $sql = 'WHERE '.$where.' ORDER BY '.implode(', ', array_map(
            static fn (array $term): string => $term[0].' '.$term[1],
            $orderBy,
        ));

        if (null !== $limit) {
            $sql .= ' LIMIT :limit';
            $params['limit'] = $limit;
        }
        if ($offset > 0) {
            $sql .= ' OFFSET :offset';
            $params['offset'] = $offset;
        }

        return $this->fetchDecks($sql, $params);
    }

    /**
     * Number of decks findByUser() would return without LIMIT/OFFSET.
     */
    public function countByUser(
        User $user,
        ?string $faction = null,
        ?string $hero = null,
        ?string $format = null,
        ?bool $isPublic = null,
        ?bool $isDraft = null,
        ?string $name = null,
    ): int {
        [$where, $params] = $this->buildUserFilters($user, $faction, $hero, $format, $isPublic, $isDraft, $name);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(d.id) FROM deck d WHERE {$where}",
            $params,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildUserFilters(
        User $user,
        ?string $faction,
        ?string $hero,
        ?string $format,
        ?bool $isPublic,
        ?bool $isDraft,
        ?string $name,
    ): array {
        [$heroFactionWhere, $params] = $this->buildHeroFactionWhere($hero, $faction);
        $where = 'd.user_id = :userId'.$heroFactionWhere;
        $params['userId'] = (string) $user->getId();

        if (null !== $format) {
            $where .= ' AND d.format = :format';
            $params['format'] = $format;
        }

        // Booleans are inlined from a fixed pair, never from user input.
        if (null !== $isPublic) {
            $where .= ' AND d.is_public = '.($isPublic ? 'true' : 'false');
        }

        if (null !== $isDraft) {
            $where .= ' AND d.is_draft = '.($isDraft ? 'true' : 'false');
        }

        if (null !== $name) {
            // Escape LIKE wildcards so "100%" or "a_b" match literally.
            $where .= ' AND d.name ILIKE :name';
            $params['name'] = '%'.addcslashes($name, '%_\\').'%';
        }

        return [$where, $params];
    }

    public function findBgaDecks(?User $user, int $page, int $itemsPerPage, string $name, array $factions, string $hero, string $format, array $validFormats = []): array
    {
        [$conditions, $params] = $this->buildBgaConditions($user, $name, $factions, $hero, $format, $validFormats);
        $where = 'WHERE '.implode(' AND ', $conditions);

        $params['limit'] = $itemsPerPage;
        $params['offset'] = ($page - 1) * $itemsPerPage;

        return $this->fetchDecks(
            "{$where} ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset",
            $params,
        );
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
