<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Repository\DeckRepository;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class DeckCollectionProvider implements ProviderInterface
{
    /** order[...] keys honoured on GET /api/decks => deck column. */
    private const ORDER_FIELDS = ['lastModifiedAt' => 'last_modified_at'];

    public function __construct(
        private DeckRepository $deckRepository,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $currentUser = $this->security->getUser();

        if (!$currentUser instanceof User) {
            return [];
        }

        $filters = $context['filters'] ?? [];
        [$orderBy, $orderDir] = $this->order($filters) ?? [null, null];

        return $this->deckRepository->findByUser(
            $currentUser,
            faction: $this->stringFilter($filters, 'faction'),
            hero: $this->stringFilter($filters, 'hero'),
            orderBy: $orderBy,
            orderDir: $orderDir ?? 'DESC',
        );
    }

    /**
     * This provider bypasses API Platform's Doctrine extensions, so the declared OrderFilter
     * never runs here. Only the keys in ORDER_FIELDS are honoured; any other key keeps the
     * historical default ordering (updated_at DESC) to leave existing clients as-is.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: 'ASC'|'DESC'}|null [column, direction]
     */
    private function order(array $filters): ?array
    {
        $order = $filters['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        foreach (self::ORDER_FIELDS as $field => $column) {
            $dir = $order[$field] ?? null;
            $dir = is_string($dir) ? strtoupper($dir) : null;
            if ('ASC' === $dir || 'DESC' === $dir) {
                return [$column, $dir];
            }
        }

        return null;
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
}
