<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Repository\DeckRepository;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class DeckCollectionProvider implements ProviderInterface
{
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

        return $this->deckRepository->findByUser(
            $currentUser,
            faction: $this->stringFilter($filters, 'faction'),
            hero: $this->stringFilter($filters, 'hero'),
            lastModifiedDir: $this->lastModifiedOrder($filters),
        );
    }

    /**
     * This provider bypasses API Platform's Doctrine extensions, so the declared OrderFilter
     * never runs here. Only order[lastModifiedAt]=asc|desc is honoured; any other order key
     * keeps the historical default ordering (updated_at DESC) to leave existing clients as-is.
     *
     * @param array<string, mixed> $filters
     *
     * @return 'ASC'|'DESC'|null
     */
    private function lastModifiedOrder(array $filters): ?string
    {
        $order = $filters['order'] ?? null;
        $dir = is_array($order) ? ($order['lastModifiedAt'] ?? null) : null;

        return match (is_string($dir) ? strtolower($dir) : null) {
            'asc' => 'ASC',
            'desc' => 'DESC',
            default => null,
        };
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
