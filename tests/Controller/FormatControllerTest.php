<?php

namespace App\Tests\Controller;

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
        ];
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
