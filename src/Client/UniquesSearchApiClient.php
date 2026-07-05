<?php

namespace App\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class UniquesSearchApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $uniquesSearchApiUrl,
    ) {
    }

    /**
     * Returns the subset of $references that are legal in the given card format
     * (e.g. "frontier"), as reported by the uniques search API.
     *
     * @param string[] $references
     *
     * @return string[]
     */
    public function findLegalReferences(array $references, string $format): array
    {
        if (empty($references)) {
            return [];
        }

        $response = $this->httpClient->request('GET', $this->uniquesSearchApiUrl.'/api/v2/cards', [
            'query' => [
                'ref' => implode(',', $references),
                'format' => $format,
            ],
        ]);

        $data = $response->toArray();

        return array_column($data['cards'] ?? [], 'reference');
    }
}
