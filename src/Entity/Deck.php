<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\Repository\DeckRepository;
use App\State\DeckCollectionProvider;
use App\State\DeckStateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeckRepository::class)]
#[ORM\Index(name: 'idx_deck_user', fields: ['user'])]
#[ORM\Index(name: 'idx_deck_format', fields: ['format'])]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['deck:read']],
            paginationClientItemsPerPage: true,
            paginationMaximumItemsPerPage: 1000,
            provider: DeckCollectionProvider::class,
        ),
        new Get(
            normalizationContext: ['groups' => ['deck:read', 'deck:read:detail']],
        ),
        new Post(
            normalizationContext:   ['groups' => ['deck:read']],
            denormalizationContext: ['groups' => ['deck:write']],
            processor: DeckStateProcessor::class,
        ),
        new Patch(
            normalizationContext:   ['groups' => ['deck:read']],
            denormalizationContext: ['groups' => ['deck:write']],
            processor: DeckStateProcessor::class,
        ),
        new Delete(),
    ],
    paginationItemsPerPage: 20,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'format'    => 'exact',
    'isPublic'  => 'exact',
    'isDraft'   => 'exact',
    'user'      => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt', 'name'])]
class Deck
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['deck:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    #[Groups(['deck:read', 'deck:write'])]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['deck:read', 'deck:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['deck:read', 'deck:write'])]
    private ?string $format = null;

    #[ORM\Column]
    #[Groups(['deck:read', 'deck:write'])]
    private bool $isPublic = false;

    #[ORM\Column]
    #[Groups(['deck:read', 'deck:write'])]
    private bool $isDraft = false;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'decks')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['deck:read'])]
    private User $user;

    #[ORM\OneToMany(targetEntity: DeckCard::class, mappedBy: 'deck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    #[Groups(['deck:read:detail', 'deck:write'])]
    private Collection $deckCards;

    #[ORM\Column]
    #[Groups(['deck:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['deck:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['deck:read'])]
    private ?array $stats = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->deckCards = new ArrayCollection();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getFormat(): ?string { return $this->format; }
    public function setFormat(?string $format): self { $this->format = $format; return $this; }

    public function isPublic(): bool { return $this->isPublic; }
    public function setIsPublic(bool $isPublic): self { $this->isPublic = $isPublic; return $this; }

    public function isDraft(): bool { return $this->isDraft; }
    public function setIsDraft(bool $isDraft): self { $this->isDraft = $isDraft; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getDeckCards(): Collection { return $this->deckCards; }
    public function addDeckCard(DeckCard $card): self
    {
        if (!$this->deckCards->contains($card)) {
            $this->deckCards->add($card);
            $card->setDeck($this);
        }
        return $this;
    }
    public function removeDeckCard(DeckCard $card): self
    {
        $this->deckCards->removeElement($card);
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function getStats(): ?array { return $this->stats; }
    public function setStats(?array $stats): self { $this->stats = $stats; return $this; }
}
