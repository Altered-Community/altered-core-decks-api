<?php

namespace App\Serializer;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Plain-JSON envelope for paginated API Platform collections (GET /api/decks).
 *
 * The API only serves `application/json`, where API Platform would emit a bare array and
 * drop the page metadata. The keys match GET /api/decks/public (member, totalItems,
 * currentPage, lastPage, nextPage, previousPage), plus itemsPerPage and a `view` of
 * ready-made page URLs (first, last, previous, next) that keep the request's filters.
 */
class DeckCollectionNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'DECK_COLLECTION_NORMALIZER_ALREADY_CALLED';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        \assert($object instanceof PaginatorInterface);
        $context[self::ALREADY_CALLED] = true;

        $items = [];
        foreach ($object as $item) {
            $items[] = $this->normalizer->normalize($item, $format, $context);
        }

        $currentPage = (int) $object->getCurrentPage();
        $itemsPerPage = (int) $object->getItemsPerPage();
        $totalItems = (int) $object->getTotalItems();
        $lastPage = $itemsPerPage > 0 ? max(1, (int) ceil($totalItems / $itemsPerPage)) : 1;

        $request = $this->requestStack->getCurrentRequest();
        $baseUrl = $request?->getPathInfo() ?? '';
        $queryParams = $request?->query->all() ?? [];

        $buildUrl = static function (int $page) use ($baseUrl, $queryParams): string {
            return $baseUrl.'?'.http_build_query(array_merge($queryParams, ['page' => $page]));
        };

        return [
            'member' => $items,
            'totalItems' => $totalItems,
            'itemsPerPage' => $itemsPerPage,
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
            'nextPage' => $currentPage < $lastPage ? $currentPage + 1 : null,
            'previousPage' => $currentPage > 1 ? $currentPage - 1 : null,
            'view' => [
                'first' => $buildUrl(1),
                'last' => $buildUrl($lastPage),
                'previous' => $currentPage > 1 ? $buildUrl($currentPage - 1) : null,
                'next' => $currentPage < $lastPage ? $buildUrl($currentPage + 1) : null,
            ],
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if ($context[self::ALREADY_CALLED] ?? false) {
            return false;
        }

        return $data instanceof PaginatorInterface;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [PaginatorInterface::class => false];
    }
}
