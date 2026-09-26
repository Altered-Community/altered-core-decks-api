<?php

namespace App\Tests\Client;

use App\Client\AlteredCoreClient;
use App\Tests\Support\FrontierFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AlteredCoreClientTest extends TestCase
{
    public function testCardGroupSlugsWalkEveryPageWithTheAfterIdCursor(): void
    {
        $requests = [];
        $pages = [
            array_map(static fn (int $id): array => ['id' => $id, 'slug' => 'S-'.$id], range(1, 1000)),
            [['id' => 1500, 'slug' => 'S-1500']],
        ];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests, &$pages): MockResponse {
            $requests[] = $url;

            return FrontierFixtures::json(['member' => array_shift($pages)]);
        });

        $client = new AlteredCoreClient($http, new TagAwareAdapter(new ArrayAdapter()), 'http://core');
        $slugs = $client->getCardGroupSlugsByGameplayFormat('FRONTIER');

        self::assertCount(1001, $slugs);
        self::assertSame('S-1500', end($slugs));
        self::assertCount(2, $requests);
        self::assertStringContainsString('/api/card_groups?gameplayFormat=FRONTIER&itemsPerPage=1000&page=1', $requests[0]);
        self::assertStringNotContainsString('afterId', $requests[0]);
        self::assertStringContainsString('afterId=1000', $requests[1]);
    }

    public function testInvalidateCardCacheForcesARefetch(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return FrontierFixtures::json([['reference' => 'REF_1', 'gameplayFormat' => []]]);
        });
        $client = new AlteredCoreClient($http, new TagAwareAdapter(new ArrayAdapter()), 'http://core');

        $client->getCardsByReferences(['REF_1']);
        $client->getCardsByReferences(['REF_1']);
        self::assertSame(1, $calls);

        $client->invalidateCardCache();
        $client->getCardsByReferences(['REF_1']);
        self::assertSame(2, $calls);
    }
}
