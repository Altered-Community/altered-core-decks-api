<?php

namespace App\Entity;

use App\Repository\DeckUpvoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeckUpvoteRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_deck_upvote_user', columns: ['deck_id', 'user_id'])]
class DeckUpvote
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Deck::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Deck $deck;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Deck $deck, User $user)
    {
        $this->deck = $deck;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getDeck(): Deck
    {
        return $this->deck;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
