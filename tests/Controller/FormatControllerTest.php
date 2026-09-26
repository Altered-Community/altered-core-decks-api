<?php

namespace App\Tests\Controller;

use App\Entity\FrontierPool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FormatControllerTest extends WebTestCase
{
    public function testFormatsEndpointReturnsAllFormats(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $codes = array_column($data, 'code');

        self::assertContains('sandbox', $codes);
        self::assertContains('nuc', $codes);
        self::assertContains('standard', $codes);
        self::assertContains('singleton', $codes);
        self::assertContains('singleton_nuc', $codes);
        self::assertContains('frontier', $codes);
    }

    public function testHiddenFormatsAreNotReturnedByDefault(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $codes = array_column($data, 'code');

        self::assertNotContains('test', $codes);
    }

    public function testHiddenFormatsAreReturnedWhenRequested(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats?hiddenFormats=true');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $codes = array_column($data, 'code');

        self::assertContains('test', $codes);
        self::assertContains('sandbox', $codes);
    }

    public function testSandboxAllowsBannedAndSuspendedCards(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats');

        $data = json_decode($client->getResponse()->getContent(), true);
        $sandbox = $this->findFormat($data, 'sandbox');

        self::assertTrue($sandbox['allowBanned']);
        self::assertTrue($sandbox['allowSuspended']);
    }

    #[DataProvider('strictFormatsProvider')]
    public function testStrictFormatsForbidBannedAndSuspendedCards(string $code): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats');

        $data = json_decode($client->getResponse()->getContent(), true);
        $format = $this->findFormat($data, $code);

        self::assertFalse($format['allowBanned']);
        self::assertFalse($format['allowSuspended']);
    }

    public static function strictFormatsProvider(): array
    {
        return [
            ['nuc'],
            ['standard'],
            ['singleton'],
            ['singleton_nuc'],
            ['frontier'],
        ];
    }

    public function testFrontierPoolIsNullBeforeTheFirstSync(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats');

        $data = json_decode($client->getResponse()->getContent(), true);
        $frontier = $this->findFormat($data, 'frontier');

        self::assertArrayHasKey('pool', $frontier);
        self::assertNull($frontier['pool']);
    }

    public function testFrontierExposesTheMostRecentlyActivatedPool(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new FrontierPool(id: 'aaaaaaaaaaaaaaaa', cardCount: 10, revision: 1, now: new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        // Same second as the first one: revision, not activatedAt, decides which pool is current.
        $em->persist(new FrontierPool(id: 'bbbbbbbbbbbbbbbb', cardCount: 12, revision: 2, now: new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        $em->flush();

        $client->request('GET', '/api/formats');

        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertSame(
            ['id' => 'bbbbbbbbbbbbbbbb', 'cardCount' => 12, 'activatedAt' => '2026-09-01T10:00:00+00:00'],
            $this->findFormat($data, 'frontier')['pool'],
        );
    }

    public function testOnlyFrontierCarriesAPool(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/formats?hiddenFormats=true');

        $data = json_decode($client->getResponse()->getContent(), true);

        foreach ($data as $format) {
            self::assertSame('frontier' === $format['code'], array_key_exists('pool', $format), $format['code']);
        }
    }

    private function findFormat(array $data, string $code): array
    {
        foreach ($data as $format) {
            if ($format['code'] === $code) {
                return $format;
            }
        }

        self::fail(sprintf('Format "%s" not found in response.', $code));
    }
}
