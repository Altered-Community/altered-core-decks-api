<?php

namespace App\Tests\Entity;

use App\Entity\Deck;
use PHPUnit\Framework\TestCase;

class DeckTest extends TestCase
{
    public function testNewDeckLastModifiedAtEqualsCreatedAt(): void
    {
        $deck = new Deck();

        $this->assertSame($deck->getCreatedAt(), $deck->getLastModifiedAt());
        $this->assertNull($deck->getUpdatedAt());
    }

    public function testMarkModifiedMovesUpdatedAtAndLastModifiedAtTogether(): void
    {
        $deck = new Deck();
        $createdAt = $deck->getCreatedAt();
        $at = new \DateTimeImmutable('+1 hour');

        $deck->markModified($at);

        $this->assertSame($at, $deck->getUpdatedAt());
        $this->assertSame($at, $deck->getLastModifiedAt());
        $this->assertSame($createdAt, $deck->getCreatedAt());
    }

    public function testMarkModifiedDefaultsToNow(): void
    {
        $deck = new Deck();
        $before = new \DateTimeImmutable();

        $deck->markModified();

        $this->assertGreaterThanOrEqual($before, $deck->getLastModifiedAt());
        $this->assertSame($deck->getUpdatedAt(), $deck->getLastModifiedAt());
    }

    public function testCountersDoNotTouchDates(): void
    {
        $deck = new Deck();
        $lastModifiedAt = $deck->getLastModifiedAt();

        $deck->incrementViewCount();
        $deck->incrementUpvoteCount();
        $deck->decrementUpvoteCount();

        $this->assertSame($lastModifiedAt, $deck->getLastModifiedAt());
        $this->assertNull($deck->getUpdatedAt());
    }
}
