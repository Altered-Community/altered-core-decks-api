<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Client\CardDataProviderFactory;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Entity\User;
use App\Validator\Format\DeckFormatValidatorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DeckStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CardDataProviderFactory $cardDataProviderFactory,
        private readonly DeckFormatValidatorFactory $validatorFactory,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Deck
    {
        /** @var Deck $data */
        $isNew = !$data->getId();

        if ($isNew) {
            $currentUser = $this->security->getUser();
            if (!$currentUser instanceof User) {
                throw new \LogicException('POST /api/decks requires an authenticated user.');
            }
            $user = $this->em->getReference(User::class, $currentUser->getId());
            $data->setUser($user);
        } else {
            $data->setUpdatedAt(new \DateTimeImmutable());
            $this->mergeDeckCards($data);
        }

        $cardsData = $this->fetchCardsData($data);
        $format = $data->getFormat()?->value;

        if (null !== $cardsData) {
            foreach ($data->getDeckCards() as $deckCard) {
                $cardInfo = $cardsData[$deckCard->getCardReference()] ?? null;
                if ($cardInfo) {
                    $deckCard->setName($cardInfo['name'] ?? null);
                }
            }
        }

        if ($data->getIsDraft()) {
            $data->setFormatErrors(null);
            $data->setLegalityDetail(null);
            $data->setLegal(false);
        } elseif (null !== $cardsData) {
            if ($format && $this->validatorFactory->supports($format)) {
                $validator = $this->validatorFactory->getValidator($format);
                $errors = $validator->validate($data, $cardsData);
                $detail = $validator->computeLegalityDetail($data, $cardsData);

                $data->setFormatErrors(empty($errors) ? null : $errors);
                $data->setLegalityDetail($detail);
                $data->setLegal($detail['global']);
            } else {
                $data->setFormatErrors(null);
                $data->setLegalityDetail(null);
                $data->setLegal(false);
            }
        }

        $data->setStats($this->computeStats($data, $cardsData ?? []));

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    /**
     * Fetches card data for all cards in the deck from altered-core.
     * Returns [] for empty decks, null when the API call fails.
     *
     * @return array<string, array>|null
     */
    private function fetchCardsData(Deck $deck): ?array
    {
        $references = array_map(
            fn (DeckCard $dc) => $dc->getCardReference(),
            $deck->getDeckCards()->toArray()
        );

        if (empty($references)) {
            return [];
        }

        $locale = $this->requestStack->getCurrentRequest()?->query->get('locale', 'fr') ?? 'fr';

        try {
            return $this->cardDataProviderFactory->getProvider()->getCardsByReferences($references, $locale);
        } catch (\Throwable $e) {
            $this->logger->error('CardDataProvider::getCardsByReferences failed', [
                'error' => $e->getMessage(),
                'references' => $references,
            ]);

            return null;
        }
    }

    /**
     * Computes deck stats from deckCards. Runs for draft and non-draft decks alike, so the
     * hero (and the faction derived from it downstream) is always available.
     * Rarity is parsed from the card reference (parts[5]: C, R1, R2, U).
     * Hero is detected from cardsData when available, null otherwise (e.g. card fetch failed).
     *
     * @param array<string, array> $cardsData
     */
    private function computeStats(Deck $deck, array $cardsData): array
    {
        $hero = null;
        $total = 0;
        $byRarity = ['C' => 0, 'R' => 0, 'U' => 0, 'E' => 0];

        foreach ($deck->getDeckCards() as $deckCard) {
            $ref = $deckCard->getCardReference();
            $qty = $deckCard->getQuantity();
            $cardData = $cardsData[$ref] ?? [];

            if ($cardData && $this->isHero($cardData)) {
                $hero = [
                    'reference' => $ref,
                    'name' => $cardData['name'] ?? null,
                    'imagePath' => $cardData['imagePath'] ?? null,
                ];
                continue;
            }

            $total += $qty;
            $rarity = $this->getRarityFromReference($ref);
            $byRarity[$rarity] += $qty;
        }

        return [
            'totalCards' => $total,
            'hero' => $hero,
            'byRarity' => $byRarity,
        ];
    }

    private function isHero(array $cardData): bool
    {
        $typeRef = $cardData['cardType']['reference'] ?? '';

        return false !== stripos($typeRef, 'HERO');
    }

    /**
     * Parses rarity from a card reference or CardGroup slug.
     *   Slug format    : AX-001-C, AX-020-R1, AX-021-R2, AX-001-U-185  → parts[2]
     *   Reference format: ALT_CORE_B_OR_17_R1_045                       → parts[5].
     */
    private function getRarityFromReference(string $ref): string
    {
        if (str_contains($ref, '-')) {
            $parts = explode('-', $ref);
            $rarity = strtoupper($parts[2] ?? 'C');
        } else {
            $parts = explode('_', $ref);
            $rarity = strtoupper($parts[5] ?? 'C');
        }

        return match ($rarity) {
            'U' => 'U',
            'R1', 'R2' => 'R',
            'E', 'EXALT' => 'E',
            default => 'C',
        };
    }

    private function mergeDeckCards(Deck $deck): void
    {
        $incomingByRef = [];
        foreach ($deck->getDeckCards() as $card) {
            $incomingByRef[$card->getCardReference()] = $card;
        }

        $dbCards = $this->em->getRepository(DeckCard::class)->findBy(['deck' => $deck]);

        if (empty($incomingByRef) && !empty($dbCards)) {
            throw new UnprocessableEntityHttpException('deckCards cannot be empty. Send at least one card or omit the field.');
        }

        $deck->getDeckCards()->clear();
        foreach ($dbCards as $dbCard) {
            $ref = $dbCard->getCardReference();

            if (!isset($incomingByRef[$ref])) {
                $this->em->remove($dbCard);
                continue;
            }

            $incoming = $incomingByRef[$ref];
            $dbCard->setQuantity($incoming->getQuantity());
            $deck->getDeckCards()->add($dbCard);
            unset($incomingByRef[$ref]);
        }

        foreach ($incomingByRef as $incoming) {
            $incoming->setDeck($deck);
            $deck->getDeckCards()->add($incoming);
            $this->em->persist($incoming);
        }
    }
}
