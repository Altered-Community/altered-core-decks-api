<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;

final readonly class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated,
        private string $appEnv,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $openApi = $this->addBearerSecurityScheme($openApi);

        if ($this->appEnv === 'dev') {
            $this->addDevAuthPath($openApi);
        }

        return $openApi;
    }

    private function addBearerSecurityScheme(OpenApi $openApi): OpenApi
    {
        $openApi->getComponents()->getSecuritySchemes()['bearerAuth'] = new \ArrayObject([
            'type'         => 'http',
            'scheme'       => 'bearer',
            'bearerFormat' => 'JWT',
        ]);

        return $openApi->withSecurity([['bearerAuth' => []]]);
    }

    private function addDevAuthPath(OpenApi $openApi): void
    {
        $openApi->getPaths()->addPath('/api/dev/auth', new Model\PathItem(
            post: new Model\Operation(
                operationId: 'dev_auth_post',
                tags: ['Dev'],
                responses: [
                    '200' => new Model\Response(
                        description: 'JWT token',
                        content: new \ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'token'      => ['type' => 'string'],
                                        'expires_in' => ['type' => 'integer', 'example' => 3600],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                ],
                summary: 'Generate a dev JWT token (disabled in production)',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'sub'      => ['type' => 'string', 'example' => 'user-1'],
                                    'username' => ['type' => 'string', 'example' => 'john'],
                                    'email'    => ['type' => 'string', 'example' => 'john@example.com'],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ));
    }
}
