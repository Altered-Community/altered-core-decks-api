<?php

namespace App\Service;

use App\Entity\FrontierPool;

final readonly class FrontierPoolSyncResult
{
    public function __construct(
        public FrontierPool $current,
        public ?FrontierPool $previous,
    ) {
    }

    public function hasChanged(): bool
    {
        return $this->previous?->getId() !== $this->current->getId();
    }
}
