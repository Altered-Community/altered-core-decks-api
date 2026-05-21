<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class MeController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer');
        }

        return $this->json([
            'email' => $user->getEmail(),
            'uniqueId' => $user->getKeycloakId(),
            'nickName' => $user->getUsername(),
            'locale' => $user->getLocale(),
        ]);
    }
}
