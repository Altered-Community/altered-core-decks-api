<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Deck;
use App\Entity\User;
use App\Repository\DeckRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/decks: one page of the caller's own decks.
 *
 * The query runs in DeckRepository (native SQL, for the jsonb hero/faction match), so
 * API Platform's Doctrine filter and pagination extensions never apply here: the
 * supported filters and the page window are read from the request by hand.
 *
 * @implements ProviderInterface<Deck>
 */
final readonly class DeckCollectionProvider implements ProviderInterface
{
    public function __construct(
        private DeckRepository $deckRepository,
        private Security $security,
        private Pagination $pagination,
    ) {
    }

    /**
     * @return TraversablePaginator<Deck>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        // page / itemsPerPage, honouring the operation's default (20), client override and
        // maximum (1000). Invalid values (page < 1, itemsPerPage < 0) become a 400.
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);

        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            return new TraversablePaginator(new \ArrayIterator([]), $page, $limit, 0);
        }

        $filters = $context['filters'] ?? [];
        $criteria = [
            'faction' => $this->stringFilter($filters, 'faction'),
            'hero' => $this->stringFilter($filters, 'hero'),
            'format' => $this->stringFilter($filters, 'format'),
            'isPublic' => $this->boolFilter($filters, 'isPublic'),
            'isDraft' => $this->boolFilter($filters, 'isDraft'),
            'name' => $this->stringFilter($filters, 'name'),
        ];

        $total = $this->deckRepository->countByUser($currentUser, ...$criteria);
        $decks = 0 === $limit || $offset >= $total
            ? []
            : $this->deckRepository->findByUser(
                $currentUser,
                ...$criteria,
                offset: $offset,
                limit: $limit,
                order: $this->orderFilter($filters),
            );

        return new TraversablePaginator(new \ArrayIterator($decks), $page, $limit, $total);
    }

    /**
     * Reads a non-empty scalar string from the API Platform filter context.
     * Array-style params (e.g. ?faction[]=x) and empty strings resolve to null
     * so they can't reach the string-typed repository method.
     *
     * @param array<string, mixed> $filters
     */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * 'true'/'1' and 'false'/'0' (case-insensitive); anything else means "no filter".
     *
     * @param array<string, mixed> $filters
     */
    private function boolFilter(array $filters, string $key): ?bool
    {
        $value = $filters[$key] ?? null;

        return match (is_string($value) ? strtolower($value) : null) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * `order[field]=asc|desc`, possibly several fields in priority order. Only string
     * pairs pass; the repository keeps the whitelisted fields and ignores the rest.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, string>
     */
    private function orderFilter(array $filters): array
    {
        $order = $filters['order'] ?? null;
        if (!is_array($order)) {
            return [];
        }

        $result = [];
        foreach ($order as $field => $direction) {
            if (is_string($field) && is_string($direction)) {
                $result[$field] = $direction;
            }
        }

        return $result;
    }
}
