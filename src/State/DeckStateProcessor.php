<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Client\AlteredCoreClient;
use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Entity\User;
use App\Validator\Format\DeckFormatValidatorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class DeckStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly Security                   $security,
        private readonly AlteredCoreClient          $alteredCoreClient,
        private readonly DeckFormatValidatorFactory $validatorFactory,
        private readonly RequestStack               $requestStack,
        private readonly LoggerInterface            $logger,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Deck
    {
        /** @var Deck $data */
        $isNew = !$data->getId();

        if ($isNew) {
            /** @var User $user */
            $user = $this->em->getReference(User::class, $this->security->getUser()->getId());
            $data->setUser($user);
        } else {
            $data->setUpdatedAt(new \DateTimeImmutable());
        }

        $cardsData = $this->fetchCardsData($data);
        $this->validateFormat($data, $cardsData);
        $data->setStats($this->computeStats($data, $cardsData));

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    /**
     * Fetches card data for all cards in the deck from altered-core.
     * Returns an empty array if the deck has no cards.
     *
     * @return array<string, array>
     */
    private function fetchCardsData(Deck $deck): array
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
            return $this->alteredCoreClient->getCardsByReferences($references, $locale);
        } catch (\Throwable $e) {
            $this->logger->error('AlteredCoreClient::getCardsByReferences failed', [
                'error'      => $e->getMessage(),
                'references' => $references,
            ]);
            return [];
        }
    }

    /**
     * Validates deck format rules against already-fetched cardsData.
     *
     * @param array<string, array> $cardsData
     */
    private function validateFormat(Deck $deck, array $cardsData): void
    {
        $format = $deck->getFormat();

        if (!$format || !$this->validatorFactory->supports($format)) {
            return;
        }

        $errors = $this->validatorFactory->getValidator($format)->validate($deck, $cardsData);

        if (!empty($errors)) {
            $violations = new ConstraintViolationList();
            foreach ($errors as $message) {
                $violations->add(new ConstraintViolation($message, $message, [], $deck, 'deckCards', null));
            }
            throw new ValidationException($violations);
        }
    }

    /**
     * Computes deck stats from deckCards.
     * Rarity is parsed from the card reference (parts[5]: C, R1, R2, U).
     * Hero is detected from cardsData when available (format validation ran), null otherwise.
     *
     * @param array<string, array> $cardsData
     */
    private function computeStats(Deck $deck, array $cardsData): array
    {
        $hero     = null;
        $total    = 0;
        $byRarity = ['C' => 0, 'R' => 0, 'U' => 0, 'E' => 0];

        foreach ($deck->getDeckCards() as $deckCard) {
            $ref      = $deckCard->getCardReference();
            $qty      = $deckCard->getQuantity();
            $cardData = $cardsData[$ref] ?? [];

            if ($cardData && $this->isHero($cardData)) {
                $hero = [
                    'reference' => $ref,
                    'name'      => $cardData['cardGroup']['name'] ?? null,
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
            'hero'       => $hero,
            'byRarity'   => $byRarity,
        ];
    }

    private function isHero(array $cardData): bool
    {
        $typeRef = $cardData['cardGroup']['cardType']['reference'] ?? '';
        return stripos($typeRef, 'HERO') !== false;
    }

    /**
     * Parses rarity from a card reference or CardGroup slug.
     *   Slug format    : AX-001-C, AX-020-R1, AX-021-R2, AX-001-U-185  → parts[2]
     *   Reference format: ALT_CORE_B_OR_17_R1_045                       → parts[5]
     */
    private function getRarityFromReference(string $ref): string
    {
        if (str_contains($ref, '-')) {
            $parts  = explode('-', $ref);
            $rarity = strtoupper($parts[2] ?? 'C');
        } else {
            $parts  = explode('_', $ref);
            $rarity = strtoupper($parts[5] ?? 'C');
        }

        return match ($rarity) {
            'U'          => 'U',
            'R1', 'R2'   => 'R',
            'E', 'EXALT' => 'E',
            default      => 'C',
        };
    }
}
