<?php

namespace App\Tests\Support;

use Firebase\JWT\JWT;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Shared helpers for functional tests: dev JWT bearer tokens and the altered_core mock.
 *
 * Meant for WebTestCase subclasses (relies on static::getContainer()).
 */
trait ApiTestTrait
{
    /** HS256 secret of dev tokens (`iss: dev`) in the test environment. */
    private const string JWT_SECRET = '$ecretf0rt3st_extended_for_hs256_tests';

    /**
     * @param array<string, mixed> $overrides
     */
    private static function bearer(string $sub, array $overrides = [], string $secret = self::JWT_SECRET): string
    {
        return 'Bearer '.JWT::encode(array_merge([
            'sub' => $sub,
            'preferred_username' => 'testuser',
            'email' => 'test@example.com',
            'iss' => 'dev',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides), $secret, 'HS256');
    }

    /**
     * @return array<string, string>
     */
    private static function authHeaders(string $sub, string $contentType = 'application/json'): array
    {
        return [
            'HTTP_AUTHORIZATION' => self::bearer($sub),
            'CONTENT_TYPE' => $contentType,
        ];
    }

    private static function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);
    }

    /**
     * Makes the altered_core mock answer every request with $json, and returns the mock.
     */
    private static function mockAlteredCoreResponse(string $json = '[]'): MockHttpClient
    {
        /** @var MockHttpClient $mock */
        $mock = static::getContainer()->get('altered_core.mock_http_client');
        $mock->setResponseFactory(static fn (): MockResponse => self::jsonResponse($json));

        return $mock;
    }
}
