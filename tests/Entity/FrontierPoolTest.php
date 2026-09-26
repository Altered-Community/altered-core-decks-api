<?php

namespace App\Tests\Entity;

use App\Entity\FrontierPool;
use PHPUnit\Framework\TestCase;

class FrontierPoolTest extends TestCase
{
    public function testFingerprintIgnoresOrder(): void
    {
        self::assertSame(
            FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7', 'LY-011-U-42']),
            FrontierPool::fingerprint(['LY-011-U-42', 'AX-020-U-101', 'BR-003-U-7']),
        );
    }

    public function testFingerprintIgnoresDuplicatesCaseAndWhitespace(): void
    {
        self::assertSame(
            FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7']),
            FrontierPool::fingerprint(['ax-020-u-101', ' BR-003-U-7 ', 'AX-020-U-101']),
        );
    }

    public function testFingerprintChangesWhenACardIsAdded(): void
    {
        self::assertNotSame(
            FrontierPool::fingerprint(['AX-020-U-101']),
            FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7']),
        );
    }

    public function testFingerprintChangesWhenACardIsSwapped(): void
    {
        self::assertNotSame(
            FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-7']),
            FrontierPool::fingerprint(['AX-020-U-101', 'BR-003-U-8']),
        );
    }

    public function testFingerprintIsShortHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', FrontierPool::fingerprint(['AX-020-U-101']));
    }

    public function testActivateMovesRevisionAndActivatedAtButKeepsFirstSeenAt(): void
    {
        $firstSeen = new \DateTimeImmutable('2026-06-01 10:00:00');
        $pool = new FrontierPool(id: 'abc', cardCount: 3, revision: 1, now: $firstSeen);

        $pool->activate(3, new \DateTimeImmutable('2026-09-01 10:00:00'));

        self::assertEquals($firstSeen, $pool->getFirstSeenAt());
        self::assertEquals(new \DateTimeImmutable('2026-09-01 10:00:00'), $pool->getActivatedAt());
        self::assertSame(3, $pool->getRevision());
    }
}
