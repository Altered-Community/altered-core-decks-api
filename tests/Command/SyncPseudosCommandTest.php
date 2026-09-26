<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SyncPseudosCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var MockHttpClient $keycloak */
        $keycloak = static::getContainer()->get('keycloak_admin.mock_http_client');
        $keycloak->setResponseFactory(function (string $method, string $url, array $options): MockResponse {
            $this->requestedUrls[] = $method.' '.$url;

            if (str_ends_with($url, '/protocol/openid-connect/token')) {
                self::assertStringContainsString('grant_type=client_credentials', $options['body']);

                return new MockResponse(json_encode(['access_token' => 'admin-token']));
            }

            $user = match (basename($url)) {
                'kc-with-pseudo' => ['attributes' => ['pseudo' => ['PseudoJoueur']], 'email' => 'joueur@example.com'],
                'kc-at-pseudo' => ['attributes' => ['pseudo' => ['Joueur@Altered']]],
                'kc-without-pseudo' => ['username' => 'sans-pseudo@example.com', 'email' => 'sans-pseudo@example.com'],
                'kc-already-synced' => ['attributes' => ['pseudo' => ['DejaLa']]],
                default => null,
            };

            return null === $user
                ? new MockResponse('', ['http_code' => 404])
                : new MockResponse(json_encode($user), ['response_headers' => ['Content-Type: application/json']]);
        });
    }

    private function createUser(string $keycloakId, ?string $username = null): void
    {
        $user = (new User())->setKeycloakId($keycloakId)->setEmail($keycloakId.'@example.com')->setUsername($username);
        $this->em->persist($user);
        $this->em->flush();
    }

    private function usernameOf(string $keycloakId): ?string
    {
        return $this->em->getConnection()->fetchOne('SELECT username FROM "user" WHERE keycloak_id = ?', [$keycloakId]) ?: null;
    }

    private function runCommand(array $options = []): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:users:sync-pseudos'));
        self::assertSame(0, $tester->execute($options));

        return $tester->getDisplay();
    }

    public function testFillsUsernamesFromKeycloakPseudo(): void
    {
        $this->createUser('kc-with-pseudo');
        $this->createUser('kc-at-pseudo');
        $this->createUser('kc-without-pseudo');
        $this->createUser('kc-already-synced', 'DejaLa');
        $this->createUser('kc-deleted');

        $output = $this->runCommand();

        self::assertSame('PseudoJoueur', $this->usernameOf('kc-with-pseudo'));
        self::assertSame('Joueur@Altered', $this->usernameOf('kc-at-pseudo'));
        self::assertNull($this->usernameOf('kc-without-pseudo'));
        self::assertSame('DejaLa', $this->usernameOf('kc-already-synced'));
        self::assertNull($this->usernameOf('kc-deleted'));

        self::assertMatchesRegularExpression('/^updated: 2$/m', $output);
        self::assertMatchesRegularExpression('/^unchanged: 1$/m', $output);
        self::assertStringNotContainsString('PseudoJoueur', $output, 'Only counts are printed');
        self::assertStringNotContainsString('@', $output, 'Only counts are printed');
        self::assertCount(1, array_filter($this->requestedUrls, fn (string $u) => str_ends_with($u, '/token')), 'One admin token for the whole run');
    }

    public function testDryRunSavesNothing(): void
    {
        $this->createUser('kc-with-pseudo');

        $output = $this->runCommand(['--dry-run' => true]);

        self::assertNull($this->usernameOf('kc-with-pseudo'));
        self::assertMatchesRegularExpression('/^updated: 1$/m', $output);
        self::assertStringContainsString('Dry run', $output);
    }
}
