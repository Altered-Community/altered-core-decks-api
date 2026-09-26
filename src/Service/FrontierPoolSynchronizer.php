<?php

namespace App\Service;

use App\Client\AlteredCoreClient;
use App\Entity\FrontierPool;
use App\Repository\FrontierPoolRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the Frontier allowlist from altered-core-cards-api and makes its fingerprint the
 * current FrontierPool. When the pool changes, cached card payloads are dropped so that
 * revalidation and later saves see the new gameplayFormat tags.
 */
final readonly class FrontierPoolSynchronizer
{
    private const string GAMEPLAY_FORMAT = 'FRONTIER';

    public function __construct(
        private AlteredCoreClient $alteredCoreClient,
        private FrontierPoolRepository $frontierPoolRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws \RuntimeException when altered-core is unreachable or returns an empty allowlist;
     *                           the current pool is then left untouched
     */
    public function sync(): FrontierPoolSyncResult
    {
        $slugs = $this->alteredCoreClient->getCardGroupSlugsByGameplayFormat(self::GAMEPLAY_FORMAT);

        // An empty allowlist would make every Frontier deck with a Unique illegal. It is far
        // more likely to be a cards-api data problem than a real pool, so refuse to switch.
        if ([] === $slugs) {
            throw new \RuntimeException('altered-core returned no CardGroup tagged FRONTIER; keeping the current Frontier pool.');
        }

        $fingerprint = FrontierPool::fingerprint($slugs);
        $previous = $this->frontierPoolRepository->findCurrent();

        if ($previous?->getId() === $fingerprint) {
            return new FrontierPoolSyncResult(current: $previous, previous: $previous);
        }

        $now = new \DateTimeImmutable();
        $revision = ($previous?->getRevision() ?? 0) + 1;
        $current = $this->frontierPoolRepository->find($fingerprint)?->activate($revision, $now)
            ?? new FrontierPool(id: $fingerprint, cardCount: count(array_unique($slugs)), revision: $revision, now: $now);

        $this->em->persist($current);
        $this->em->flush();
        $this->alteredCoreClient->invalidateCardCache();

        return new FrontierPoolSyncResult(current: $current, previous: $previous);
    }
}
