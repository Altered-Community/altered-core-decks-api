<?php

namespace App\Client;

class CardDataProviderFactory
{
    /** @var array<string, CardDataProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<CardDataProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly string $activeProvider,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function getProvider(): CardDataProviderInterface
    {
        if (!isset($this->providers[$this->activeProvider])) {
            throw new \InvalidArgumentException(sprintf('No card data provider found for "%s". Available: %s', $this->activeProvider, implode(', ', array_keys($this->providers))));
        }

        return $this->providers[$this->activeProvider];
    }
}
