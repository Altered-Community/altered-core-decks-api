<?php

namespace App\Controller;

use App\Entity\DeckUpvote;
use App\Entity\User;
use App\Repository\DeckRepository;
use App\Repository\DeckUpvoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class DeckUpvoteController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $deckRepository,
        private readonly DeckUpvoteRepository $deckUpvoteRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/decks/{id}/upvote', name: 'api_deck_upvote', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function __invoke(string $id): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $deck = $this->deckRepository->find(Uuid::fromString($id));

        if (!$deck || !$deck->getIsPublic()) {
            throw new NotFoundHttpException();
        }

        $existing = $this->deckUpvoteRepository->findOneByDeckAndUser($deck, $user);

        if ($existing) {
            $this->em->remove($existing);
            $deck->decrementUpvoteCount();
            $upvoted = false;
        } else {
            $upvote = new DeckUpvote($deck, $user);
            $this->em->persist($upvote);
            $deck->incrementUpvoteCount();
            $upvoted = true;
        }

        $this->em->flush();

        return $this->json([
            'upvoteCount' => $deck->getUpvoteCount(),
            'hasUpvoted' => $upvoted,
        ]);
    }
}
