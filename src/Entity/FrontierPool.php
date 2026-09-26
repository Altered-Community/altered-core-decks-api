<?php

namespace App\Entity;

use App\Repository\FrontierPoolRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Frontier card pool, as observed on altered-core-cards-api.
 *
 * The Altered Reunion manifest carries a `version`, but altered-core-cards-api drops it on
 * import and only keeps the resulting `gameplayFormat` tags on each CardGroup. Those tags are
 * what FrontierFormatValidator actually checks, so the pool is identified by a fingerprint of
 * the set of CardGroups tagged "FRONTIER": it changes exactly when Frontier legality can change.
 *
 * The row with the highest revision is the current pool. revision (not activatedAt, stored at
 * second precision) orders activations, and its unique index rejects two concurrent syncs.
 */
#[ORM\Entity(repositoryClass: FrontierPoolRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_frontier_pool_revision', fields: ['revision'])]
class FrontierPool
{
    private const int FINGERPRINT_LENGTH = 16;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $activatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $id,
        #[ORM\Column]
        private int $cardCount,
        #[ORM\Column]
        private int $revision,
        \DateTimeImmutable $now,
    ) {
        $this->firstSeenAt = $now;
        $this->activatedAt = $now;
    }

    /**
     * Order-insensitive, duplicate-insensitive fingerprint of a set of CardGroup slugs.
     *
     * @param string[] $slugs
     */
    public static function fingerprint(array $slugs): string
    {
        $normalized = array_values(array_unique(array_map(
            static fn (string $slug): string => strtoupper(trim($slug)),
            $slugs,
        )));
        sort($normalized, \SORT_STRING);

        return substr(hash('sha256', implode("\n", $normalized)), 0, self::FINGERPRINT_LENGTH);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCardCount(): int
    {
        return $this->cardCount;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getActivatedAt(): \DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function activate(int $revision, \DateTimeImmutable $now): self
    {
        $this->revision = $revision;
        $this->activatedAt = $now;

        return $this;
    }
}
