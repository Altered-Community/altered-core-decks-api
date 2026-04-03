<?php

namespace App\Controller;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class DevAuthController extends AbstractController
{
    public function __construct(
        private readonly string $appSecret,
        private readonly bool   $devAuthEnabled,
    ) {}

    #[Route('/api/dev/auth', name: 'dev_auth', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->devAuthEnabled) {
            throw new NotFoundHttpException();
        }

        $body = json_decode($request->getContent(), true);

        $sub      = $body['sub']      ?? 'dev-user-' . uniqid();
        $email    = $body['email']    ?? null;
        $username = $body['username'] ?? 'dev-user';

        $now     = time();
        $payload = [
            'sub'                => $sub,
            'preferred_username' => $username,
            'email'              => $email,
            'name'               => $username,
            'iss'                => 'dev',
            'iat'                => $now,
            'exp'                => $now + 3600,
        ];

        $token = JWT::encode($payload, $this->appSecret, 'HS256');

        return new JsonResponse([
            'token'      => $token,
            'expires_in' => 3600,
            'payload'    => $payload,
        ]);
    }
}
