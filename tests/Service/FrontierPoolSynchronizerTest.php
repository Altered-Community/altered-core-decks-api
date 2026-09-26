<?php

namespace App\Tests\Service;

use App\Client\AlteredCoreClient;
use App\Entity\FrontierPool;
use App\Repository\FrontierPoolRepository;
use App\Service\FrontierPoolSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class FrontierPoolSynchronizerTest extends TestCase
{
    private AlteredCoreClient&MockObject $client;
    private FrontierPoolRepository&Stub $repository;
    private EntityManagerInterface&MockObject $em;
    private FrontierPoolSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->client = $this->createMock(AlteredCoreClient::class);
        $this->repository = $this->createStub(FrontierPoolRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->synchronizer = new FrontierPoolSynchronizer($this->client, $this->repository, $this->em);
    }

    private function allowlist(string ...$slugs): void
    {
        $this->client->method('getCardGroupSlugsByGameplayFormat')->willReturnMap([['FRONTIER', $slugs]]);
    }

    public function testFirstSyncCreatesThePoolAndDropsCachedCards(): void
    {
        $this->allowlist('AX-020-U-101', 'BR-003-U-7');
        $this->repository->method('findCurrent')->willReturn(null);
        $this->repository->method('find')->willReturn(null);

        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(FrontierPool::class));
        $this->em->expects(self::once())->method('flush');
        $this->client->expects(self::once())->method('invalidateCardCache');

        $result = $this->synchronizer->sync();

        self::assertTrue($result->hasChanged());
        self::assertNull($result->previous);
        self::assertSame(FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7']), $result->current->getId());
        self::assertSame(2, $result->current->getCardCount());
        self::assertSame(1, $result->current->getRevision());
    }

    public function testUnchangedPoolWritesNothing(): void
    {
        $this->allowlist('BR-003-U-7', 'AX-020-U-101');
        $current = new FrontierPool(FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7']), 2, 1, new \DateTimeImmutable());
        $this->repository->method('findCurrent')->willReturn($current);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        $this->client->expects(self::never())->method('invalidateCardCache');

        $result = $this->synchronizer->sync();

        self::assertFalse($result->hasChanged());
        self::assertSame($current, $result->current);
    }

    public function testChangedPoolBecomesCurrent(): void
    {
        $this->allowlist('AX-020-U-101', 'LY-011-U-42');
        $previous = new FrontierPool(FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7']), 2, 4, new \DateTimeImmutable());
        $this->repository->method('findCurrent')->willReturn($previous);
        $this->repository->method('find')->willReturn(null);

        $this->em->expects(self::once())->method('persist');
        $this->client->expects(self::once())->method('invalidateCardCache');

        $result = $this->synchronizer->sync();

        self::assertTrue($result->hasChanged());
        self::assertSame($previous, $result->previous);
        self::assertSame(FrontierPool::fingerprint(['AX-020-U-101', 'LY-011-U-42']), $result->current->getId());
        self::assertSame(5, $result->current->getRevision());
    }

    public function testReturningToAKnownPoolReactivatesItsRow(): void
    {
        $this->allowlist('AX-020-U-101');
        $known = new FrontierPool(FrontierPool::fingerprint(['AX-020-U-101']), 1, 1, new \DateTimeImmutable('2026-01-01'));
        $previous = new FrontierPool('other', 1, 2, new \DateTimeImmutable('2026-06-01'));
        $this->repository->method('findCurrent')->willReturn($previous);
        $this->repository->method('find')->willReturn($known);

        $this->em->expects(self::once())->method('persist')->with($known);
        $this->client->expects(self::once())->method('invalidateCardCache');

        $result = $this->synchronizer->sync();

        self::assertSame($known, $result->current);
        self::assertSame(3, $known->getRevision());
        self::assertEquals(new \DateTimeImmutable('2026-01-01'), $known->getFirstSeenAt());
        self::assertGreaterThan(new \DateTimeImmutable('2026-06-01'), $known->getActivatedAt());
    }

    public function testEmptyAllowlistIsRefused(): void
    {
        $this->allowlist();

        $this->em->expects(self::never())->method('persist');
        $this->client->expects(self::never())->method('invalidateCardCache');

        $this->expectException(\RuntimeException::class);

        $this->synchronizer->sync();
    }
}
