<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Client\AlteredCoreClient;
use App\Entity\Deck;
use App\Entity\User;
use App\Validator\Format\DeckFormatValidatorFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class DeckStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly Security                  $security,
        private readonly AlteredCoreClient         $alteredCoreClient,
        private readonly DeckFormatValidatorFactory $validatorFactory,
        private readonly RequestStack              $requestStack,
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

        $this->validateFormat($data);

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    private function validateFormat(Deck $deck): void
    {
        $format = $deck->getFormat();

        if (!$format || !$this->validatorFactory->supports($format)) {
            return;
        }

        $locale     = $this->requestStack->getCurrentRequest()?->query->get('locale', 'fr') ?? 'fr';
        $references = array_map(
            fn ($dc) => $dc->getCardReference(),
            $deck->getDeckCards()->toArray()
        );

        $cardsData = $this->alteredCoreClient->getCardsByReferences($references, $locale);
        $errors    = $this->validatorFactory->getValidator($format)->validate($deck, $cardsData);

        if (empty($errors)) {
            return;
        }

        $violations = new ConstraintViolationList();
        foreach ($errors as $message) {
            $violations->add(new ConstraintViolation($message, $message, [], $deck, 'deckCards', null));
        }

        throw new ValidationException($violations);
    }
}
