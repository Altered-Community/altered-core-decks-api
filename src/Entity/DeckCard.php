<?php

namespace App\Entity;

use App\Repository\DeckCardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeckCardRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_deck_card', fields: ['deck', 'cardReference'])]
class DeckCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['deck:read:detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Deck::class, inversedBy: 'deckCards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Deck $deck;

    /**
     * Reference of the card in altered-core (e.g. ALT_CORE_B_OR_17_C).
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['deck:read:detail', 'deck:write'])]
    private string $cardReference;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 3)]
    #[Groups(['deck:read:detail', 'deck:write'])]
    private int $quantity = 1;

    public function getId(): ?int { return $this->id; }

    public function getDeck(): Deck { return $this->deck; }
    public function setDeck(Deck $deck): self { $this->deck = $deck; return $this; }

    public function getCardReference(): string { return $this->cardReference; }
    public function setCardReference(string $cardReference): self { $this->cardReference = $cardReference; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }
}
