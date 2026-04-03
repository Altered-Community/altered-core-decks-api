<?php

namespace App\Serializer;

use ApiPlatform\Doctrine\Orm\Paginator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class DeckCollectionNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'DECK_COLLECTION_NORMALIZER_ALREADY_CALLED';

    public function __construct(private readonly RequestStack $requestStack) {}

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        $items = [];
        foreach ($object as $item) {
            $items[] = $this->normalizer->normalize($item, $format, $context);
        }

        $currentPage  = (int) $object->getCurrentPage();
        $itemsPerPage = (int) $object->getItemsPerPage();
        $totalItems   = (int) $object->getTotalItems();
        $lastPage     = $itemsPerPage > 0 ? (int) ceil($totalItems / $itemsPerPage) : 1;

        $request    = $this->requestStack->getCurrentRequest();
        $baseUrl    = $request?->getPathInfo() ?? '';
        $queryParams = $request?->query->all() ?? [];

        $buildUrl = function (int $page) use ($baseUrl, $queryParams): string {
            $params = array_merge($queryParams, ['page' => $page]);
            return $baseUrl . '?' . http_build_query($params);
        };

        return [
            'data'       => $items,
            'pagination' => [
                'totalItems'   => $totalItems,
                'itemsPerPage' => $itemsPerPage,
                'currentPage'  => $currentPage,
                'lastPage'     => $lastPage,
            ],
            'links'      => [
                'first'    => $buildUrl(1),
                'last'     => $buildUrl($lastPage),
                'previous' => $currentPage > 1 ? $buildUrl($currentPage - 1) : null,
                'next'     => $currentPage < $lastPage ? $buildUrl($currentPage + 1) : null,
            ],
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if ($context[self::ALREADY_CALLED] ?? false) {
            return false;
        }

        return $data instanceof Paginator;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Paginator::class => false];
    }
}
